<?php

namespace Tests\Feature\Filament;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plain HTTP-level check (not just Livewire::test) that every new route
 * actually boots end-to-end — middleware, layout, panel discovery included.
 */
class TaxRoutesHttpSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_new_tax_routes_return_200_for_an_authenticated_admin(): void
    {
        $this->seed(ShieldSeeder::class);
        $admin = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();

        $customer = Customer::create([
            'name' => 'HTTP Smoke Customer',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 65,
            'tax_rate' => 5,
        ]);

        $this->actingAs($admin);

        $this->get('/panel/customers')->assertOk();
        $this->get('/panel/customers/create')->assertOk();
        $this->get("/panel/customers/{$customer->id}/edit")->assertOk();
        $this->get("/panel/customers/{$customer->id}/tax/2025")->assertOk();
        $this->get("/panel/customers/{$customer->id}/tax/historical")->assertOk();
        $this->get('/panel/inps-rate-slabs')->assertOk();
        $this->get('/panel/diritto-annuale-brackets')->assertOk();
        $this->get('/panel/tax-settings')->assertOk();
    }

    public function test_guest_is_redirected_not_shown_an_error(): void
    {
        $this->get('/panel/tax-settings')->assertRedirect();
    }
}
