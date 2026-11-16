<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\InpsCalculation;
use App\Models\TaxCalculation;
use App\Services\Tax\RedditiReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RedditiReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_quadro_lm_and_rr_from_the_years_calculations(): void
    {
        $customer = Customer::create(['name' => 'Redditi', 'customer_type' => 'commercianti', 'coefficient_of_profitability' => 40, 'tax_rate' => 15]);

        TaxCalculation::create([
            'customer_id' => $customer->id, 'tax_year' => 2025, 'total_sales' => 50000, 'gross_income' => 20000,
            'inps_deducted' => 3000, 'taxable_income' => 17000, 'coefficient_used' => 40, 'tax_rate_used' => 15,
            'tax_due' => 2550, 'advance_payments_made' => 0, 'balance_payment' => 2550, 'next_year_advance_base' => 2550,
        ]);

        InpsCalculation::create([
            'customer_id' => $customer->id, 'tax_year' => 2025, 'taxable_income' => 17000, 'inps_rate' => 24,
            'inps_amount' => 4000, 'discount_percentage' => 35, 'discount_amount' => 1400,
            'inps_amount_after_discount' => 2600, 'calculation_method' => 'percentage',
            'fixed_amount' => 2000, 'variable_amount' => 600,
        ]);

        $data = app(RedditiReportService::class)->build($customer, 2025);

        $this->assertSame(50000.0, $data['lm']['total_sales']);
        $this->assertSame(17000.0, $data['lm']['taxable_income']);
        $this->assertSame(2550.0, $data['lm']['tax_due']);
        $this->assertNotNull($data['rr']);
        $this->assertSame('I', $data['rr']['section']);
        $this->assertSame(2600.0, $data['rr']['amount_due']);
    }

    public function test_rr_is_null_without_an_inps_calculation(): void
    {
        $customer = Customer::create(['name' => 'NoInps', 'customer_type' => 'commercianti', 'coefficient_of_profitability' => 40, 'tax_rate' => 15]);
        TaxCalculation::create([
            'customer_id' => $customer->id, 'tax_year' => 2025, 'total_sales' => 50000, 'taxable_income' => 20000,
            'coefficient_used' => 40, 'tax_rate_used' => 15, 'tax_due' => 3000, 'advance_payments_made' => 0,
            'balance_payment' => 3000, 'next_year_advance_base' => 3000,
        ]);

        $data = app(RedditiReportService::class)->build($customer, 2025);

        $this->assertNull($data['rr']);
    }

    public function test_throws_without_a_tax_calculation(): void
    {
        $customer = Customer::create(['name' => 'Missing', 'customer_type' => 'commercianti', 'coefficient_of_profitability' => 40, 'tax_rate' => 15]);

        $this->expectException(RuntimeException::class);
        app(RedditiReportService::class)->build($customer, 2025);
    }
}
