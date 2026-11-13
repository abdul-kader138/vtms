<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\DirittoAnnualeBracket;
use App\Models\TaxCalculation;
use App\Services\Tax\DirittoAnnualeCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirittoAnnualeCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private DirittoAnnualeCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DirittoAnnualeCalculationService::class);
    }

    private function fakeTaxCalculation(Customer $customer, int $year, float $totalSales): TaxCalculation
    {
        return TaxCalculation::create([
            'customer_id' => $customer->id,
            'tax_year' => $year,
            'total_sales' => $totalSales,
            'taxable_income' => $totalSales,
            'coefficient_used' => 100,
            'tax_rate_used' => 5,
            'tax_due' => 0,
            'balance_payment' => 0,
            'next_year_advance_base' => 0,
        ]);
    }

    public function test_it_matches_the_bracket_containing_total_sales(): void
    {
        $customer = Customer::create(['name' => 'Artigiano', 'customer_type' => 'artigiani']);
        DirittoAnnualeBracket::create(['bracket_year' => 2025, 'revenue_from' => 0, 'revenue_to' => 100000, 'amount' => 53.00]);
        DirittoAnnualeBracket::create(['bracket_year' => 2025, 'revenue_from' => 100000.01, 'revenue_to' => null, 'amount' => 90.00]);

        $tc = $this->fakeTaxCalculation($customer, 2025, 18401.67);
        $bracket = $this->service->matchBracket($customer, 2025, $tc);

        $this->assertNotNull($bracket);
        $this->assertEqualsWithDelta(53.00, (float) $bracket->amount, 0.001);
    }

    public function test_a_zero_amount_bracket_match_yields_no_payment(): void
    {
        $customer = Customer::create(['name' => 'Artigiano', 'customer_type' => 'artigiani']);
        DirittoAnnualeBracket::create(['bracket_year' => 2025, 'revenue_from' => 0, 'revenue_to' => null, 'amount' => 0]);

        $tc = $this->fakeTaxCalculation($customer, 2025, 18401.67);

        $this->assertNull($this->service->matchBracket($customer, 2025, $tc));
    }

    public function test_it_is_skipped_for_gestione_separata(): void
    {
        $customer = Customer::create(['name' => 'Gestione Separata', 'customer_type' => 'gestione_separata']);
        DirittoAnnualeBracket::create(['bracket_year' => 2025, 'revenue_from' => 0, 'revenue_to' => null, 'amount' => 53.00]);

        $tc = $this->fakeTaxCalculation($customer, 2025, 18401.67);

        $this->assertNull($this->service->matchBracket($customer, 2025, $tc));
    }
}
