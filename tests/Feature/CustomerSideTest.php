<?php

namespace Tests\Feature;

use App\Filament\Auth\LoginResponse;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CustomerSideTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlinked_account_gets_the_not_linked_code(): void
    {
        $user = User::factory()->create(['first_name' => 'Sara', 'email' => 'sara@example.com']);

        $this->actingAs($user)->getJson('/api/v1/tax/years')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_linked')
            ->assertJsonPath('user.first_name', 'Sara');
    }

    public function test_portal_payload_carries_the_revenue_limit(): void
    {
        $customer = Customer::create(['name' => 'Limit', 'customer_type' => 'commercianti', 'coefficient_of_profitability' => 40, 'tax_rate' => 15, 'annual_revenue_limit' => 85000]);
        $user = User::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)->getJson('/api/v1/tax/years')->assertOk()->assertJsonPath('customer.revenue_limit', 85000);
    }

    public function test_customer_signing_in_on_the_panel_login_lands_in_the_portal(): void
    {
        $this->seed(ShieldSeeder::class);
        $customerUser = User::factory()->create(['customer_id' => Customer::create(['name' => 'X'])->id]);

        $request = Request::create('/login');
        $request->setUserResolver(fn () => $customerUser);

        $this->assertSame(route('customer.tax'), app(LoginResponse::class)->toResponse($request)->getTargetUrl());
    }

    public function test_auth_and_status_pages_render_in_the_new_shell(): void
    {
        $this->get('/login')->assertOk()->assertSee('c-auth', false)->assertSee('customer-auth-root', false);
        $this->get('/register')->assertOk()->assertSee('data-mode="register"', false);
        $this->get('/verify-email')->assertOk()
            ->assertSee('links the account to your tax records', false)
            ->assertSee(route('customer.login'), false);
        $this->get('/app/tax')->assertOk()->assertSee('customer-tax-root', false);
    }

    public function test_pages_render_in_italian(): void
    {
        $this->withCookie('locale', 'it')->get('/login')->assertOk();
        app()->setLocale('it');
        $this->assertSame('Le tue tasse, una fermata alla volta.', __('Your taxes, one stop at a time.'));
    }

    public function test_staff_and_customers_have_separate_sign_ins_and_dashboards(): void
    {
        $this->seed(ShieldSeeder::class);
        $admin = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();
        $customerUser = User::factory()->create(['customer_id' => Customer::create(['name' => 'Portal'])->id]);

        // Addresses
        $this->get('/login')->assertOk()->assertSee('customer-auth-root', false);
        $this->get('/panel/login')->assertOk()->assertDontSee('customer-auth-root', false);
        $this->get('/app/login')->assertRedirect('/login');
        $this->get('/app/register')->assertRedirect('/register');

        // "/" is each account's own dashboard
        $this->get('/')->assertRedirect(route('customer.login'));
        $this->actingAs($admin)->get('/')->assertRedirect('/panel');
        $this->actingAs($customerUser)->get('/')->assertRedirect(route('customer.tax'));

        // Wrong side → own dashboard
        $this->actingAs($admin)->get('/app/tax')->assertRedirect('/panel');
        $this->actingAs($customerUser)->get('/app/tax')->assertOk();
    }

    public function test_guests_are_sent_to_the_matching_sign_in(): void
    {
        $this->get('/panel/customers')->assertRedirect('/panel/login');
        $this->get(route('tax.import-sample', ['format' => 'invoices', 'ext' => 'csv']))->assertRedirect('/panel/login');
    }
}
