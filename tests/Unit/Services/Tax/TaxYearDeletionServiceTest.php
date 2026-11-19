<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\InpsPayment;
use App\Models\Setting;
use App\Models\TaxPayment;
use App\Models\TaxYearLock;
use App\Services\Tax\PaymentScheduleService;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Services\Tax\TaxYearDeletionService;
use App\Support\Tax\TaxRules;
use Database\Seeders\InpsContributionRuleSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Artigiani/Commercianti under the "law" rules deliberately create payment
 * rows that reach into the NEXT year while belonging to THIS year's
 * calculation (tax advances, INPS excess-income installments — see
 * TaxYearDeletionService's docblock). These tests exist specifically to
 * pin down that cross-year cascade danger, not just plain single-year
 * deletion.
 */
class TaxYearDeletionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsContributionRuleSeeder::class);
        Setting::set(TaxRules::SETTING, TaxRules::LAW, 'tax');

        $this->customer = Customer::create([
            'name' => 'Deletion Test Commerciante',
            'customer_type' => 'commercianti',
            'coefficient_of_profitability' => 40.00,
            'tax_rate' => 15.00,
            'inail_rate' => 5.00,
            'inail_minimum_payment' => 210.00,
            'business_start_date' => '2020-01-01',
        ]);

        // Sales well above the minimale so the excess-income (variable)
        // part is > 0 and creates installment 6/7 for the following year.
        IncomeData::create([
            'customer_id' => $this->customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2024-03-01',
            'sales_amount' => 75000.00, 'taxable_sales' => 75000.00, 'sale_taxes' => 0, 'tax_year' => 2024,
        ]);

        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2024);
    }

    public function test_preview_reports_nothing_touched_and_deletable_before_any_payment(): void
    {
        $preview = app(TaxYearDeletionService::class)->preview($this->customer, 2024);

        $this->assertTrue($preview['has_data']);
        $this->assertTrue($preview['deletable']);
        $this->assertSame([], $preview['blockers']);
        $this->assertSame(1, $preview['counts']['tax_calculation']);
        $this->assertSame(1, $preview['counts']['inps_calculation']);
    }

    public function test_delete_removes_the_year_and_its_forward_scheduled_payments(): void
    {
        // Sanity: the cross-year rows this test is really about exist before deletion.
        $this->assertDatabaseHas('tax_payments', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);
        $this->assertDatabaseHas('inps_payments', [
            'customer_id' => $this->customer->id, 'payment_year' => 2025,
            'installment_number' => PaymentScheduleService::INPS_EXCESS_FIRST_ADVANCE,
        ]);

        app(TaxYearDeletionService::class)->delete($this->customer, 2024);

        $this->assertDatabaseMissing('tax_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2024]);
        $this->assertDatabaseMissing('inps_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2024]);
        $this->assertDatabaseMissing('diritto_annuale_payments', ['customer_id' => $this->customer->id, 'payment_year' => 2024]);

        // The forward-scheduled rows must be gone too — they were only
        // reachable through 2024's calculation rows.
        $this->assertDatabaseMissing('tax_payments', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);
        $this->assertDatabaseMissing('inps_payments', [
            'customer_id' => $this->customer->id, 'payment_year' => 2025,
            'installment_number' => PaymentScheduleService::INPS_EXCESS_FIRST_ADVANCE,
        ]);
    }

    public function test_delete_refuses_when_a_same_year_payment_is_paid(): void
    {
        InpsPayment::where('customer_id', $this->customer->id)->where('payment_year', 2024)->where('installment_number', 1)
            ->update(['status' => 'paid', 'paid_amount' => 1.00, 'paid_date' => '2024-05-16']);

        $this->expectException(RuntimeException::class);

        try {
            app(TaxYearDeletionService::class)->delete($this->customer, 2024);
        } finally {
            // Nothing partially deleted on failure.
            $this->assertDatabaseHas('tax_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2024]);
            $this->assertDatabaseHas('inps_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2024]);
        }
    }

    /**
     * The scenario the whole service exists for: nothing in 2024 itself was
     * touched, but one of the advances it scheduled FORWARD into 2025 was
     * paid — deleting 2024 must still be refused, because that payment is
     * only reachable (and would cascade-delete) through 2024's row.
     */
    public function test_delete_refuses_when_a_forward_scheduled_next_year_payment_is_paid(): void
    {
        TaxPayment::where('customer_id', $this->customer->id)->where('tax_year', 2025)->where('payment_type', 'first_advance')
            ->update(['status' => 'paid', 'paid_amount' => 1.00, 'paid_date' => '2025-06-30']);

        $preview = app(TaxYearDeletionService::class)->preview($this->customer, 2024);
        $this->assertFalse($preview['deletable']);
        $this->assertTrue(collect($preview['blockers'])->contains(fn ($b) => $b['year'] === 2025 && $b['belongs_to_next_year'] === true));

        $this->expectException(RuntimeException::class);
        app(TaxYearDeletionService::class)->delete($this->customer, 2024);
    }

    public function test_delete_refuses_a_locked_year_even_with_nothing_else_touched(): void
    {
        TaxYearLock::create(['customer_id' => $this->customer->id, 'tax_year' => 2024, 'locked_at' => now()]);

        $preview = app(TaxYearDeletionService::class)->preview($this->customer, 2024);
        $this->assertTrue($preview['locked']);
        $this->assertFalse($preview['deletable']);
        $this->assertSame([], $preview['blockers']);

        $this->expectException(RuntimeException::class);

        try {
            app(TaxYearDeletionService::class)->delete($this->customer, 2024);
        } finally {
            $this->assertDatabaseHas('tax_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2024]);
        }
    }

    public function test_delete_is_a_no_op_when_the_year_has_no_data(): void
    {
        app(TaxYearDeletionService::class)->delete($this->customer, 1999);
        $this->assertTrue(true); // did not throw
    }

    public function test_stale_following_year_reflects_whether_next_year_has_data(): void
    {
        $service = app(TaxYearDeletionService::class);

        $this->assertFalse($service->staleFollowingYear($this->customer, 2024));

        IncomeData::create([
            'customer_id' => $this->customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 1000.00, 'taxable_sales' => 1000.00, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);

        $this->assertTrue($service->staleFollowingYear($this->customer, 2024));
    }
}
