<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataRemoveTest extends TestCase
{
    use RefreshDatabase;

    private function demoCustomer(): Customer
    {
        $customer = Customer::create(['name' => 'Demo Co', 'vat_no' => 'DEMO00000099', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);
        IncomeData::create(['customer_id' => $customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01', 'sales_amount' => 100, 'taxable_sales' => 100, 'sale_taxes' => 0, 'tax_year' => 2025]);
        User::create(['first_name' => 'Demo', 'last_name' => 'User', 'email' => 'demo.user@example.com', 'password' => bcrypt('password'), 'customer_id' => $customer->id]);

        return $customer;
    }

    public function test_dry_run_lists_without_deleting(): void
    {
        $customer = $this->demoCustomer();

        $this->artisan('demo:remove --dry-run')->assertSuccessful();

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('income_data', ['customer_id' => $customer->id]);
    }

    public function test_removes_demo_customers_their_data_and_their_portal_login(): void
    {
        $customer = $this->demoCustomer();
        $real = Customer::create(['name' => 'Real Co', 'vat_no' => 'IT12345678901', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);

        $this->artisan('demo:remove --force')->assertSuccessful();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('income_data', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('users', ['email' => 'demo.user@example.com']);
        $this->assertDatabaseHas('customers', ['id' => $real->id]);
    }

    public function test_reports_when_nothing_to_remove(): void
    {
        $this->artisan('demo:remove --force')
            ->expectsOutputToContain('No demo customers found.')
            ->assertSuccessful();
    }
}
