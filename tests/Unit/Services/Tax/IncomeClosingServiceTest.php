<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\IncomeClosing;
use App\Models\IncomeData;
use App\Models\InpsCalculation;
use App\Models\TaxCalculation;
use App\Services\Tax\IncomeClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeClosingServiceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private IncomeClosingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create(['name' => 'Closing Test', 'customer_type' => 'commercianti', 'coefficient_of_profitability' => 40, 'tax_rate' => 15]);
        $this->service = app(IncomeClosingService::class);
    }

    public function test_a_month_starts_open(): void
    {
        $this->assertFalse($this->service->isClosed($this->customer, 2025, 3));
    }

    public function test_close_then_reopen(): void
    {
        $this->service->close($this->customer, 2025, 3);
        $this->assertTrue($this->service->isClosed($this->customer, 2025, 3));

        $this->service->reopen($this->customer, 2025, 3);
        $this->assertFalse($this->service->isClosed($this->customer, 2025, 3));
    }

    public function test_closing_twice_is_idempotent(): void
    {
        $this->service->close($this->customer, 2025, 3);
        $this->service->close($this->customer, 2025, 3);

        $this->assertSame(1, IncomeClosing::where('customer_id', $this->customer->id)->where('year', 2025)->where('month', 3)->count());
    }

    public function test_closed_months_lists_all_twelve_with_correct_flags(): void
    {
        $this->service->close($this->customer, 2025, 1);
        $this->service->close($this->customer, 2025, 6);

        $months = $this->service->closedMonths($this->customer, 2025);

        $this->assertCount(12, $months);
        $this->assertTrue($months[1]);
        $this->assertTrue($months[6]);
        $this->assertFalse($months[2]);
        $this->assertFalse($months[12]);
    }

    public function test_closing_never_touches_income_data_or_tax_tables(): void
    {
        IncomeData::create([
            'customer_id' => $this->customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-01-10',
            'sales_amount' => 1000, 'taxable_sales' => 1000, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);

        $this->service->close($this->customer, 2025, 1);

        $this->assertSame(1, IncomeData::where('customer_id', $this->customer->id)->count());
        $this->assertSame(0, TaxCalculation::where('customer_id', $this->customer->id)->count());
        $this->assertSame(0, InpsCalculation::where('customer_id', $this->customer->id)->count());
    }
}
