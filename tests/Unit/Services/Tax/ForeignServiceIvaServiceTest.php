<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\ForeignServicePurchase;
use App\Models\Setting;
use App\Services\Tax\ForeignServiceIvaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForeignServiceIvaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_purchases_and_iva_owed_use_the_default_22_percent_rate(): void
    {
        $customer = Customer::create(['name' => 'Foreign', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);

        ForeignServicePurchase::create(['customer_id' => $customer->id, 'purchase_date' => '2025-02-01', 'provider' => 'Google Ads', 'amount' => 100]);
        ForeignServicePurchase::create(['customer_id' => $customer->id, 'purchase_date' => '2025-06-01', 'provider' => 'Canva', 'amount' => 50]);
        // Different year — excluded from the 2025 totals.
        ForeignServicePurchase::create(['customer_id' => $customer->id, 'purchase_date' => '2024-06-01', 'provider' => 'Canva', 'amount' => 999]);

        $service = app(ForeignServiceIvaService::class);

        $this->assertEqualsWithDelta(150.0, $service->totalPurchases($customer, 2025), 0.001);
        $this->assertEqualsWithDelta(33.0, $service->ivaOwed($customer, 2025), 0.001);
    }

    public function test_tax_year_is_derived_from_the_purchase_date(): void
    {
        $customer = Customer::create(['name' => 'Foreign2', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);

        $purchase = ForeignServicePurchase::create(['customer_id' => $customer->id, 'purchase_date' => '2026-01-10', 'provider' => 'Facebook', 'amount' => 10]);

        $this->assertSame(2026, $purchase->tax_year);
    }

    public function test_rate_can_be_overridden_via_settings(): void
    {
        Setting::set('foreign_services_iva_rate', 20.00, 'tax');
        $customer = Customer::create(['name' => 'Foreign3', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);
        ForeignServicePurchase::create(['customer_id' => $customer->id, 'purchase_date' => '2025-02-01', 'provider' => 'Google Ads', 'amount' => 100]);

        $this->assertEqualsWithDelta(20.0, app(ForeignServiceIvaService::class)->ivaOwed($customer, 2025), 0.001);
    }
}
