<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\TaxCalculation;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AnnualTaxReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_download_the_annual_report_pdf_via_a_signed_url(): void
    {
        $this->seed(ShieldSeeder::class);
        $admin = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();

        $customer = Customer::create([
            'name' => 'Report Customer',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 65,
            'tax_rate' => 5,
        ]);

        TaxCalculation::create([
            'customer_id' => $customer->id,
            'tax_year' => 2025,
            'total_sales' => 50000,
            'taxable_income' => 32500,
            'coefficient_used' => 65,
            'tax_rate_used' => 5,
            'tax_due' => 1625,
            'advance_payments_made' => 0,
            'balance_payment' => 1625,
            'next_year_advance_base' => 1300,
        ]);

        $this->actingAs($admin);

        $url = URL::signedRoute('tax.annual-report.download', ['customer' => $customer->id, 'year' => 2025]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_request_without_a_valid_signature_is_rejected(): void
    {
        $this->seed(ShieldSeeder::class);
        $admin = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();

        $customer = Customer::create([
            'name' => 'Report Customer',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 65,
            'tax_rate' => 5,
        ]);

        $this->actingAs($admin);

        $this->get("/customers/{$customer->id}/tax/2025/annual-report")->assertForbidden();
    }

    public function test_missing_tax_calculation_returns_404_instead_of_a_blank_pdf(): void
    {
        $this->seed(ShieldSeeder::class);
        $admin = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();

        $customer = Customer::create([
            'name' => 'No Calc Customer',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 65,
            'tax_rate' => 5,
        ]);

        $this->actingAs($admin);

        $url = URL::signedRoute('tax.annual-report.download', ['customer' => $customer->id, 'year' => 2025]);

        $this->get($url)->assertNotFound();
    }
}
