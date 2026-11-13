<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\IncomeData;
use App\Services\Tax\IncomePredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomePredictionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function income(Customer $customer, string $date, float $amount): void
    {
        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => $date,
            'sales_amount' => $amount, 'taxable_sales' => $amount, 'sale_taxes' => 0, 'tax_year' => (int) substr($date, 0, 4),
        ]);
    }

    public function test_returns_null_without_income_data(): void
    {
        $customer = Customer::create(['name' => 'Empty', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);

        $this->assertNull(app(IncomePredictionService::class)->predict($customer, 2025));
    }

    public function test_extrapolates_missing_months_from_the_monthly_average(): void
    {
        $customer = Customer::create(['name' => 'Predict', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);

        // Two entries in January (counts as one month), one in February, one in March.
        $this->income($customer, '2025-01-05', 1000);
        $this->income($customer, '2025-01-20', 2000);
        $this->income($customer, '2025-02-10', 3000);
        $this->income($customer, '2025-03-10', 3000);

        $p = app(IncomePredictionService::class)->predict($customer, 2025);

        $this->assertSame([1, 2, 3], $p['months_with_data']);
        $this->assertCount(9, $p['missing_months']);
        $this->assertSame(9000.0, $p['current_total_sales']);
        $this->assertSame(3000.0, $p['average_monthly_sales']);
        $this->assertSame(27000.0, $p['predicted_additional_sales']);
        $this->assertSame(36000.0, $p['predicted_total_sales']);
        $this->assertSame(24120.0, $p['predicted_taxable_income']);
        $this->assertSame(1206.0, $p['predicted_tax_due']);
    }
}
