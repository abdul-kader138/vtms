<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\DirittoAnnualeBracket;
use App\Models\InpsCalculation;
use App\Services\Tax\PaymentScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentScheduleService::class);
    }

    private function fakeInpsCalculation(Customer $customer, int $year, float $afterDiscount): InpsCalculation
    {
        return InpsCalculation::create([
            'customer_id' => $customer->id,
            'tax_year' => $year,
            'taxable_income' => $afterDiscount,
            'inps_rate' => 0,
            'inps_amount' => $afterDiscount,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'inps_amount_after_discount' => $afterDiscount,
            'calculation_method' => 'percentage',
        ]);
    }

    public function test_artigiani_four_installments_sum_exactly_with_remainder_on_the_last_one(): void
    {
        $customer = Customer::create(['name' => 'Artigiano', 'customer_type' => 'artigiani']);
        $ic = $this->fakeInpsCalculation($customer, 2025, 1583.35);

        $this->service->scheduleInpsPayments($ic, $customer);

        $installments = $customer->inpsPayments()->orderBy('installment_number')->get();

        $this->assertCount(4, $installments);
        $this->assertEqualsWithDelta(395.84, (float) $installments[0]->amount, 0.001);
        $this->assertEqualsWithDelta(395.84, (float) $installments[1]->amount, 0.001);
        $this->assertEqualsWithDelta(395.84, (float) $installments[2]->amount, 0.001);
        $this->assertEqualsWithDelta(395.83, (float) $installments[3]->amount, 0.001);

        $sum = $installments->sum(fn ($i) => (float) $i->amount);
        $this->assertEqualsWithDelta(1583.35, $sum, 0.0001);

        // Due dates: May 16 / Aug 20 / Nov 16 (year Y) + Feb 16 (Y+1).
        $this->assertSame('2025-05-16', $installments[0]->due_date->toDateString());
        $this->assertSame('2025-08-20', $installments[1]->due_date->toDateString());
        $this->assertSame('2025-11-16', $installments[2]->due_date->toDateString());
        $this->assertSame('2026-02-16', $installments[3]->due_date->toDateString());
    }

    public function test_gestione_separata_produces_saldo_plus_two_advances(): void
    {
        $customer = Customer::create(['name' => 'Gestione Separata', 'customer_type' => 'gestione_separata']);
        $ic = $this->fakeInpsCalculation($customer, 2025, 1000.00);

        $this->service->scheduleInpsPayments($ic, $customer);

        $installments = $customer->inpsPayments()->orderBy('installment_number')->get();

        $this->assertCount(3, $installments);
        $this->assertEqualsWithDelta(1000.00, (float) $installments[0]->amount, 0.001); // saldo
        $this->assertEqualsWithDelta(400.00, (float) $installments[1]->amount, 0.001); // 1st acconto, 40%
        $this->assertEqualsWithDelta(400.00, (float) $installments[2]->amount, 0.001); // 2nd acconto, 40%

        $this->assertSame('2026-06-30', $installments[0]->due_date->toDateString());
        $this->assertSame('2026-06-30', $installments[1]->due_date->toDateString());
        $this->assertSame('2026-11-30', $installments[2]->due_date->toDateString());
    }

    public function test_diritto_annuale_is_due_march_31_of_the_same_year_not_the_next(): void
    {
        $customer = Customer::create(['name' => 'Artigiano', 'customer_type' => 'artigiani']);
        $bracket = DirittoAnnualeBracket::create(['bracket_year' => 2025, 'revenue_from' => 0, 'revenue_to' => null, 'amount' => 53.00]);

        $this->service->scheduleDirittoAnnualePayment($customer, 2025, $bracket);

        $payment = $customer->dirittoAnnualePayments()->first();
        $this->assertSame('2025-03-31', $payment->due_date->toDateString());
        $this->assertEqualsWithDelta(53.00, (float) $payment->amount, 0.001);
    }

    public function test_rescheduling_the_same_year_is_idempotent_not_duplicating_rows(): void
    {
        $customer = Customer::create(['name' => 'Artigiano', 'customer_type' => 'artigiani']);
        $ic = $this->fakeInpsCalculation($customer, 2025, 1583.35);

        $this->service->scheduleInpsPayments($ic, $customer);
        $this->service->scheduleInpsPayments($ic, $customer);

        $this->assertCount(4, $customer->inpsPayments()->get());
    }

    public function test_preview_for_year_writes_nothing_and_matches_the_real_artigiani_schedule(): void
    {
        $customer = Customer::create(['name' => 'Artigiano Preview', 'customer_type' => 'artigiani']);

        $rows = $this->service->previewForYear($customer, 2025, [
            'tax' => ['tax_due' => 0, 'balance_payment' => 0],
            'inps' => ['inps_amount_after_discount' => 1583.35, 'discount_percentage' => 0, 'calculation_method' => 'percentage'],
        ]);

        $inpsRows = collect($rows)->filter(fn ($r) => str_starts_with($r['label'], 'INPS'))->values();

        $this->assertCount(4, $inpsRows);
        $this->assertEqualsWithDelta(395.84, $inpsRows[0]['amount'], 0.001);
        $this->assertEqualsWithDelta(395.83, $inpsRows[3]['amount'], 0.001);
        $this->assertSame('2025-05-16', $inpsRows[0]['due_date']->toDateString());

        $this->assertDatabaseCount('inps_payments', 0);
        $this->assertDatabaseCount('inps_calculations', 0);
    }

    public function test_preview_for_year_matches_the_real_gestione_separata_schedule(): void
    {
        $customer = Customer::create(['name' => 'GS Preview', 'customer_type' => 'gestione_separata']);

        $rows = $this->service->previewForYear($customer, 2025, [
            'tax' => ['tax_due' => 0, 'balance_payment' => 0],
            'inps' => ['inps_amount_after_discount' => 1000.00, 'discount_percentage' => 0, 'calculation_method' => 'percentage'],
        ]);

        $inpsRows = collect($rows)->filter(fn ($r) => str_starts_with($r['label'], 'INPS'))->values();

        $this->assertCount(3, $inpsRows);
        $this->assertEqualsWithDelta(1000.00, $inpsRows[0]['amount'], 0.001);
        $this->assertEqualsWithDelta(400.00, $inpsRows[1]['amount'], 0.001);
        $this->assertEqualsWithDelta(400.00, $inpsRows[2]['amount'], 0.001);
        $this->assertSame('2026-06-30', $inpsRows[0]['due_date']->toDateString());
        $this->assertSame('2026-11-30', $inpsRows[2]['due_date']->toDateString());
    }

    public function test_preview_for_year_includes_tax_inail_and_fattura_rows_and_totals_them(): void
    {
        $customer = Customer::create(['name' => 'Full Preview', 'customer_type' => 'gestione_separata', 'business_start_date' => '2020-01-01']);

        $rows = $this->service->previewForYear($customer, 2025, [
            'tax' => ['tax_due' => 1000.00, 'balance_payment' => 200.00],
            'inps' => ['inps_amount_after_discount' => 100.00, 'discount_percentage' => 0, 'calculation_method' => 'percentage'],
            'inail' => ['inail_final_amount' => 210.00],
            'fattura_tra_privati' => ['total_payment_amount' => 10.00],
        ]);

        $this->assertTrue(collect($rows)->contains(fn ($r) => $r['label'] === 'Tax — balance' && $r['amount'] === 200.00));
        $this->assertTrue(collect($rows)->contains(fn ($r) => $r['label'] === 'INAIL' && $r['amount'] === 210.00));
        $this->assertTrue(collect($rows)->contains(fn ($r) => $r['amount'] === 10.00 && $r['due_date'] === null));

        // Tax: balance 200 + two 40% advances of the 1000 tax_due (400 each).
        // INPS (Gestione Separata, 100 after discount): balance 100 + two 40% advances (40 each).
        $this->assertEqualsWithDelta(200.00 + 400.00 + 400.00 + 100.00 + 40.00 + 40.00 + 210.00 + 10.00, collect($rows)->sum('amount'), 0.001);
    }

    public function test_preview_for_year_omits_a_calculator_the_form_left_blank(): void
    {
        $customer = Customer::create(['name' => 'Omit Preview', 'customer_type' => 'gestione_separata']);

        $rows = $this->service->previewForYear($customer, 2025, [
            'tax' => ['tax_due' => 0, 'balance_payment' => 0],
            'inps' => ['inps_amount_after_discount' => 100.00, 'discount_percentage' => 0, 'calculation_method' => 'percentage'],
            'inail' => ['inail_final_amount' => null],
            'fattura_tra_privati' => ['total_payment_amount' => null],
        ]);

        $this->assertFalse(collect($rows)->contains(fn ($r) => $r['label'] === 'INAIL'));
        $this->assertFalse(collect($rows)->contains(fn ($r) => str_contains($r['label'], 'Imposta di bollo')));
    }
}
