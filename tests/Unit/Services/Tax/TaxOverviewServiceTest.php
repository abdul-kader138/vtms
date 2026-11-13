<?php

namespace Tests\Unit\Services\Tax;

use App\Enums\TaxPaymentType;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\InpsPayment;
use App\Models\TaxCalculation;
use App\Models\TaxPayment;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Services\Tax\TaxOverviewService;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TaxOverviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);

        $this->customer = Customer::create(['name' => 'Overview', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function income(string $date, float $amount): void
    {
        IncomeData::create([
            'customer_id' => $this->customer->id, 'source' => 'sdi_fattura', 'transaction_date' => $date,
            'sales_amount' => $amount, 'taxable_sales' => $amount, 'sale_taxes' => 0, 'tax_year' => (int) substr($date, 0, 4),
        ]);
    }

    public function test_live_estimate_is_shown_only_before_a_calculation_exists(): void
    {
        $this->income('2025-03-01', 10000);

        $before = app(TaxOverviewService::class)->build($this->customer, 2025);
        $this->assertNull($before['tax']);
        $this->assertSame(['total_sales' => 10000.0, 'taxable_income' => 6700.0, 'coefficient_used' => 67.0, 'tax_rate' => 5.0, 'estimated_tax' => 335.0], $before['estimate']);

        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);

        $after = app(TaxOverviewService::class)->build($this->customer, 2025);
        $this->assertNotNull($after['tax']);
        $this->assertNull($after['estimate']);
    }

    public function test_tax_payments_are_the_ones_generated_by_that_years_calculation(): void
    {
        $this->income('2025-03-01', 20000);
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);

        $types = app(TaxOverviewService::class)->payments($this->customer, 2025)['tax']
            ->map(fn (TaxPayment $p) => $p->payment_type->value.':'.$p->tax_year)
            ->all();

        // Balance for 2025 plus the two advances it scheduled toward 2026.
        $this->assertSame(['balance:2025', 'first_advance:2026', 'second_advance:2026'], $types);
    }

    public function test_summary_splits_outstanding_amounts_by_effective_status(): void
    {
        Carbon::setTestNow('2026-07-01');
        $this->income('2025-03-01', 20000);
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);

        $balance = TaxPayment::where('customer_id', $this->customer->id)->where('payment_type', TaxPaymentType::Balance)->first();
        $balance->update(['status' => 'paid', 'paid_amount' => $balance->amount, 'paid_date' => '2026-02-10']);

        // First advance (due 2026-06-30) is still pending but past due → overdue.
        $firstAdvance = TaxPayment::where('customer_id', $this->customer->id)->where('payment_type', TaxPaymentType::FirstAdvance)->first();
        $firstAdvance->update(['paid_amount' => 10]);

        $overview = app(TaxOverviewService::class)->build($this->customer, 2025);
        $summary = $overview['summary'];

        $this->assertEqualsWithDelta((float) $balance->amount + 10, $summary['total_paid'], 0.001);
        $this->assertEqualsWithDelta((float) $firstAdvance->amount - 10, $summary['total_overdue'] - $this->overdueNonTax($overview), 0.001);
        $this->assertEqualsWithDelta($summary['total_overdue'] + $summary['total_upcoming'], $summary['total_due'], 0.001);
        $this->assertSame('overdue', TaxOverviewService::effectiveStatus($firstAdvance->fresh()));
    }

    private function overdueNonTax(array $overview): float
    {
        $total = 0.0;
        foreach ($overview['payments'] as $type => $payments) {
            if ($type === 'tax') {
                continue;
            }
            foreach ($payments as $p) {
                if (TaxOverviewService::effectiveStatus($p) === 'overdue') {
                    $total += (float) $p->amount - (float) $p->paid_amount;
                }
            }
        }

        return $total;
    }

    public function test_cancelled_payments_are_excluded_from_totals(): void
    {
        $this->income('2025-03-01', 20000);
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);
        InpsPayment::where('customer_id', $this->customer->id)->update(['status' => 'cancelled']);

        $overview = app(TaxOverviewService::class)->build($this->customer, 2025);
        $nonInps = collect($overview['payments'])->except('inps')->flatten()->sum(fn ($p) => (float) $p->amount);

        $this->assertGreaterThan(0, $overview['payments']['inps']->count());
        $this->assertEqualsWithDelta($nonInps, $overview['summary']['total_due'], 0.001);
    }

    public function test_warns_when_revenue_exceeds_the_customer_limit(): void
    {
        TaxCalculation::create([
            'customer_id' => $this->customer->id, 'tax_year' => 2025, 'total_sales' => 90000, 'taxable_income' => 60300,
            'coefficient_used' => 67, 'tax_rate_used' => 5, 'tax_due' => 3015, 'advance_payments_made' => 0, 'balance_payment' => 3015, 'next_year_advance_base' => 2412,
        ]);

        $warnings = app(TaxOverviewService::class)->eligibilityWarnings($this->customer, 2025, TaxCalculation::first());

        $this->assertSame('warning', $warnings[0]['level']);
        $this->assertStringContainsString('1 January 2026', $warnings[0]['message']);
    }

    public function test_warns_of_immediate_exit_above_100k(): void
    {
        $this->income('2025-03-01', 100500);

        $warnings = app(TaxOverviewService::class)->eligibilityWarnings($this->customer, 2025, null);

        $this->assertSame('danger', $warnings[0]['level']);
    }

    public function test_warns_when_projected_revenue_exceeds_the_limit(): void
    {
        $this->income('2025-01-10', 10000);
        $this->income('2025-02-10', 10000);

        $warnings = app(TaxOverviewService::class)->eligibilityWarnings($this->customer, 2025, null);

        $this->assertSame('info', $warnings[0]['level']);
    }

    public function test_no_warnings_for_a_customer_well_under_the_limit(): void
    {
        $this->income('2025-01-10', 1000);

        $this->assertSame([], app(TaxOverviewService::class)->eligibilityWarnings($this->customer, 2025, null));
    }
}
