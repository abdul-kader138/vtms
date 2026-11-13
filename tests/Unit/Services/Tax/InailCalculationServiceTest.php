<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\TaxCalculation;
use App\Services\Tax\InailCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InailCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private InailCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InailCalculationService::class);
    }

    private function fakeTaxCalculation(Customer $customer, int $year, float $taxableIncome): TaxCalculation
    {
        return TaxCalculation::create([
            'customer_id' => $customer->id,
            'tax_year' => $year,
            'total_sales' => $taxableIncome,
            'taxable_income' => $taxableIncome,
            'coefficient_used' => $customer->coefficient_of_profitability,
            'tax_rate_used' => $customer->tax_rate,
            'tax_due' => 0,
            'balance_payment' => 0,
            'next_year_advance_base' => 0,
        ]);
    }

    public function test_it_matches_vtms_2025_worked_example_base_equals_taxable_income_directly(): void
    {
        $customer = Customer::create([
            'name' => 'Test Artigiano',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 65.00,
            'tax_rate' => 5.00,
            'inail_rate' => 5.00,
            'inail_minimum_payment' => 210.00,
        ]);
        $tc = $this->fakeTaxCalculation($customer, 2025, 11961.09);

        $inail = $this->service->calculateForYear($customer, 2025, $tc);

        $this->assertNotNull($inail);
        $this->assertEqualsWithDelta(598.05, (float) $inail->inail_calculated_amount, 0.001);
        $this->assertEqualsWithDelta(598.05, (float) $inail->inail_final_amount, 0.001);
    }

    public function test_it_matches_vtms_2024_worked_example(): void
    {
        $customer = Customer::create([
            'name' => 'Test Artigiano',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 78.00,
            'tax_rate' => 5.00,
            'inail_rate' => 5.00,
            'inail_minimum_payment' => 210.00,
        ]);
        $tc = $this->fakeTaxCalculation($customer, 2024, 5485.62);

        $inail = $this->service->calculateForYear($customer, 2024, $tc);

        $this->assertEqualsWithDelta(274.28, (float) $inail->inail_calculated_amount, 0.001);
        $this->assertEqualsWithDelta(274.28, (float) $inail->inail_final_amount, 0.001);
    }

    public function test_final_amount_falls_back_to_minimum_when_calculated_is_lower(): void
    {
        $customer = Customer::create([
            'name' => 'Small Artigiano',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 65.00,
            'tax_rate' => 5.00,
            'inail_rate' => 1.00,
            'inail_minimum_payment' => 210.00,
        ]);
        $tc = $this->fakeTaxCalculation($customer, 2025, 1000.00);

        $inail = $this->service->calculateForYear($customer, 2025, $tc);

        $this->assertEqualsWithDelta(10.00, (float) $inail->inail_calculated_amount, 0.001);
        $this->assertEqualsWithDelta(210.00, (float) $inail->inail_final_amount, 0.001);
    }

    public function test_it_is_skipped_for_non_artigiani_customers(): void
    {
        $customer = Customer::create([
            'name' => 'Commerciante',
            'customer_type' => 'commercianti',
            'coefficient_of_profitability' => 78.00,
            'tax_rate' => 5.00,
            'inail_rate' => 5.00,
            'inail_minimum_payment' => 210.00,
        ]);
        $tc = $this->fakeTaxCalculation($customer, 2025, 10000);

        $this->assertNull($this->service->calculateForYear($customer, 2025, $tc));
    }

    public function test_it_is_skipped_when_inail_fields_are_missing(): void
    {
        $customer = Customer::create([
            'name' => 'Artigiano No Inail',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 78.00,
            'tax_rate' => 5.00,
        ]);
        $tc = $this->fakeTaxCalculation($customer, 2025, 10000);

        $this->assertNull($this->service->calculateForYear($customer, 2025, $tc));
    }
}
