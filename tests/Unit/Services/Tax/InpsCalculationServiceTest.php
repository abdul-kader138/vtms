<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\TaxCalculation;
use App\Services\Tax\InpsCalculationService;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InpsCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private InpsCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);
        $this->service = app(InpsCalculationService::class);
    }

    private function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Test Artigiano',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 65.00,
            'tax_rate' => 5.00,
            'inps_discount_eligible' => true,
        ], $overrides));
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

    public function test_it_matches_vtms_2025_progressive_slab_walk_with_discount(): void
    {
        $customer = $this->makeCustomer();
        $tc = $this->fakeTaxCalculation($customer, 2025, 11961.09);

        $inps = $this->service->calculateForYear($customer, 2025, $tc);

        $this->assertEqualsWithDelta(2435.92, (float) $inps->inps_amount, 0.001);
        $this->assertEqualsWithDelta(852.57, (float) $inps->discount_amount, 0.001);
        $this->assertEqualsWithDelta(1583.35, (float) $inps->inps_amount_after_discount, 0.001);
        $this->assertSame('percentage', $inps->calculation_method->value);
    }

    public function test_it_matches_vtms_2024_single_flat_slab_with_discount(): void
    {
        $customer = $this->makeCustomer();
        $tc = $this->fakeTaxCalculation($customer, 2024, 5485.62);

        $inps = $this->service->calculateForYear($customer, 2024, $tc);

        $this->assertEqualsWithDelta(1151.77, (float) $inps->inps_amount, 0.001);
        $this->assertEqualsWithDelta(403.12, (float) $inps->discount_amount, 0.001);
        $this->assertEqualsWithDelta(748.65, (float) $inps->inps_amount_after_discount, 0.001);
    }

    public function test_discount_is_zero_when_customer_is_not_eligible(): void
    {
        $customer = $this->makeCustomer(['inps_discount_eligible' => false]);
        $tc = $this->fakeTaxCalculation($customer, 2024, 5485.62);

        $inps = $this->service->calculateForYear($customer, 2024, $tc);

        $this->assertEqualsWithDelta(1151.77, (float) $inps->inps_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $inps->discount_amount, 0.001);
        $this->assertEqualsWithDelta(1151.77, (float) $inps->inps_amount_after_discount, 0.001);
    }

    public function test_zero_taxable_income_produces_zero_inps_amount(): void
    {
        $customer = $this->makeCustomer();
        $tc = $this->fakeTaxCalculation($customer, 2025, 0);

        $inps = $this->service->calculateForYear($customer, 2025, $tc);

        $this->assertEqualsWithDelta(0.0, (float) $inps->inps_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $inps->inps_amount_after_discount, 0.001);
    }
}
