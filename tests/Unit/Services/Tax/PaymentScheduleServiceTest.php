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
}
