<?php

namespace Tests\Unit\Services\Tax;

use App\Enums\TaxAdjustmentRequestStatus;
use App\Exceptions\Tax\ManualOverrideApprovalRequiredException;
use App\Models\Customer;
use App\Models\DirittoAnnualeBracket;
use App\Models\IncomeData;
use App\Models\TaxAdjustmentRequest;
use App\Models\TaxYearLock;
use App\Models\User;
use App\Services\Tax\TaxCalculationOrchestrator;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\ShieldSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxCalculationOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);
    }

    public function test_it_runs_all_five_calculators_and_schedules_payments_in_one_pass(): void
    {
        $customer = Customer::create([
            'name' => 'Full Pipeline Artigiano',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 65.00,
            'tax_rate' => 5.00,
            'inail_rate' => 5.00,
            'inail_minimum_payment' => 210.00,
            'inps_discount_eligible' => true,
            'business_start_date' => '2010-01-01',
        ]);

        DirittoAnnualeBracket::create(['bracket_year' => 2025, 'revenue_from' => 0, 'revenue_to' => null, 'amount' => 53.00]);

        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 18401.67, 'taxable_sales' => 18401.67, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);
        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'privati_device', 'transaction_date' => '2025-04-01',
            'sales_amount' => 100.00, 'taxable_sales' => 0, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);

        $result = app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($customer, 2025);

        $this->assertEqualsWithDelta(11961.09, (float) $result['tax']->taxable_income, 0.001);
        $this->assertEqualsWithDelta(1583.35, (float) $result['inps']->inps_amount_after_discount, 0.001);
        $this->assertEqualsWithDelta(598.05, (float) $result['inail']->inail_final_amount, 0.001);
        $this->assertEqualsWithDelta(53.00, (float) $result['diritto_annuale_bracket']->amount, 0.001);
        // Both seeded income rows have sales_amount >= the €77.47 threshold
        // (18401.67 and 100.00), from different sources — both must count.
        $this->assertSame(2, $result['fattura_tra_privati']->total_invoices);

        $this->assertDatabaseHas('tax_payments', ['customer_id' => $customer->id, 'payment_type' => 'balance']);
        $this->assertCount(4, $customer->inpsPayments()->get());
        $this->assertDatabaseHas('inail_payments', ['customer_id' => $customer->id]);
        $this->assertDatabaseHas('diritto_annuale_payments', ['customer_id' => $customer->id]);
        $this->assertDatabaseHas('fattura_tra_privati_payments', ['customer_id' => $customer->id]);

        // Re-running is idempotent — no duplicate rows.
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($customer, 2025);
        $this->assertCount(4, $customer->inpsPayments()->get());
        $this->assertSame(1, $customer->taxCalculations()->where('tax_year', 2025)->count());
    }

    public function test_preview_computes_without_persisting_anything(): void
    {
        $customer = Customer::create([
            'name' => 'Preview Only', 'customer_type' => 'gestione_separata',
            'coefficient_of_profitability' => 78.00, 'tax_rate' => 5.00,
        ]);
        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 10000.00, 'taxable_sales' => 10000.00, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);

        $preview = app(TaxCalculationOrchestrator::class)->preview($customer, 2025);

        $this->assertArrayHasKey('tax_due', $preview['tax']);
        $this->assertArrayHasKey('inps_amount_after_discount', $preview['inps']);
        $this->assertDatabaseMissing('tax_calculations', ['customer_id' => $customer->id, 'tax_year' => 2025]);
        $this->assertDatabaseMissing('inps_calculations', ['customer_id' => $customer->id, 'tax_year' => 2025]);
    }

    public function test_commit_persists_a_staff_override_and_flags_it_manually_adjusted(): void
    {
        $this->seed(ShieldSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo('finalize_tax_calculation');
        $this->actingAs($user);

        $customer = Customer::create([
            'name' => 'Overridden INPS', 'customer_type' => 'gestione_separata',
            'coefficient_of_profitability' => 78.00, 'tax_rate' => 5.00,
        ]);
        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 10000.00, 'taxable_sales' => 10000.00, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);

        $preview = app(TaxCalculationOrchestrator::class)->preview($customer, 2025);
        $computedAfterDiscount = (float) $preview['inps']['inps_amount_after_discount'];

        $result = app(TaxCalculationOrchestrator::class)->commit($customer, 2025, [
            'inps' => [
                'inps_amount_after_discount' => $computedAfterDiscount + 10,
                'adjustment_reason' => 'Rounding correction agreed with the accountant.',
            ],
        ]);

        $this->assertEqualsWithDelta($computedAfterDiscount + 10, (float) $result['inps']->inps_amount_after_discount, 0.001);
        $this->assertTrue($result['inps']->is_manually_adjusted);
        $this->assertSame($user->id, $result['inps']->adjusted_by);
        $this->assertNotNull($result['inps']->adjusted_at);
        $this->assertSame('Rounding correction agreed with the accountant.', $result['inps']->adjustment_reason);

        // The income tax calculator got no override, so it isn't flagged.
        $this->assertFalse($result['tax']->is_manually_adjusted);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => get_class($result['inps']),
            'subject_id' => $result['inps']->id,
            'log_name' => 'inps_calculation',
        ]);
    }

    public function test_commit_rejects_an_override_with_no_adjustment_reason(): void
    {
        $this->seed(ShieldSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo('finalize_tax_calculation');
        $this->actingAs($user);

        $customer = Customer::create([
            'name' => 'No Reason Given', 'customer_type' => 'gestione_separata',
            'coefficient_of_profitability' => 78.00, 'tax_rate' => 5.00,
        ]);
        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 10000.00, 'taxable_sales' => 10000.00, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);

        $this->expectException(\RuntimeException::class);

        app(TaxCalculationOrchestrator::class)->commit($customer, 2025, [
            'inps' => ['inps_amount_after_discount' => 9999],
        ]);
    }

    public function test_commit_rejects_an_override_from_a_user_without_the_finalize_permission(): void
    {
        $this->seed(ShieldSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user);

        $customer = Customer::create([
            'name' => 'No Permission', 'customer_type' => 'gestione_separata',
            'coefficient_of_profitability' => 78.00, 'tax_rate' => 5.00,
        ]);
        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 10000.00, 'taxable_sales' => 10000.00, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);

        $this->expectException(ManualOverrideApprovalRequiredException::class);

        app(TaxCalculationOrchestrator::class)->commit($customer, 2025, [
            'inps' => ['inps_amount_after_discount' => 9999, 'adjustment_reason' => 'Looks off.'],
        ]);
    }

    public function test_commit_flags_a_large_override_as_a_variance_warning(): void
    {
        $this->seed(ShieldSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo('finalize_tax_calculation');
        $this->actingAs($user);

        $customer = Customer::create([
            'name' => 'Big Override', 'customer_type' => 'gestione_separata',
            'coefficient_of_profitability' => 78.00, 'tax_rate' => 5.00,
        ]);
        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 10000.00, 'taxable_sales' => 10000.00, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);

        $preview = app(TaxCalculationOrchestrator::class)->preview($customer, 2025);
        $computedAfterDiscount = (float) $preview['inps']['inps_amount_after_discount'];

        $result = app(TaxCalculationOrchestrator::class)->commit($customer, 2025, [
            'inps' => [
                'inps_amount_after_discount' => $computedAfterDiscount + 500,
                'adjustment_reason' => 'Testing a big override.',
            ],
        ]);

        $this->assertNotEmpty($result['variance_warnings']);
    }

    public function test_commit_refuses_to_recalculate_a_locked_year(): void
    {
        $customer = Customer::create([
            'name' => 'Locked Year', 'customer_type' => 'gestione_separata',
            'coefficient_of_profitability' => 78.00, 'tax_rate' => 5.00,
        ]);
        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 10000.00, 'taxable_sales' => 10000.00, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);

        TaxYearLock::create(['customer_id' => $customer->id, 'tax_year' => 2025, 'locked_at' => now()]);

        $this->expectException(\RuntimeException::class);

        app(TaxCalculationOrchestrator::class)->commit($customer, 2025);
    }

    public function test_approve_request_commits_the_queued_payload_under_the_approvers_identity(): void
    {
        $this->seed(ShieldSeeder::class);
        $requester = User::factory()->create();
        $approver = User::factory()->create();
        $approver->givePermissionTo('finalize_tax_calculation');

        $customer = Customer::create([
            'name' => 'Queued Override', 'customer_type' => 'gestione_separata',
            'coefficient_of_profitability' => 78.00, 'tax_rate' => 5.00,
        ]);
        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 10000.00, 'taxable_sales' => 10000.00, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);

        $preview = app(TaxCalculationOrchestrator::class)->preview($customer, 2025);
        $computedAfterDiscount = (float) $preview['inps']['inps_amount_after_discount'];

        $payload = [
            'tax' => $preview['tax'],
            'inps' => array_merge($preview['inps'], [
                'inps_amount_after_discount' => $computedAfterDiscount + 10,
                'adjustment_reason' => 'Rounding correction agreed with the accountant.',
            ]),
        ];

        $request = TaxAdjustmentRequest::create([
            'customer_id' => $customer->id,
            'tax_year' => 2025,
            'payload' => $payload,
            'status' => TaxAdjustmentRequestStatus::Pending,
            'requested_by' => $requester->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($approver);
        $result = app(TaxCalculationOrchestrator::class)->approveRequest($request);

        $this->assertEqualsWithDelta($computedAfterDiscount + 10, (float) $result['inps']->inps_amount_after_discount, 0.001);
        $this->assertSame($approver->id, $result['inps']->adjusted_by);

        $request->refresh();
        $this->assertSame(TaxAdjustmentRequestStatus::Approved, $request->status);
        $this->assertSame($approver->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
    }
}
