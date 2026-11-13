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
        $this->get('/app/login')->assertOk()->assertSee('c-auth', false)->assertSee('customer-auth-root', false);
        $this->get('/app/register')->assertOk()->assertSee('data-mode="register"', false);
        $this->get('/verify-email')->assertOk()
            ->assertSee('links the account to your tax records', false)
            ->assertSee(route('customer.login'), false);
        $this->get('/app/tax')->assertOk()->assertSee('customer-tax-root', false);
    }

    public function test_pages_render_in_italian(): void
    {
        $this->withCookie('locale', 'it')->get('/app/login')->assertOk();
        app()->setLocale('it');
        $this->assertSame('Le tue tasse, una fermata alla volta.', __('Your taxes, one stop at a time.'));
    }
}
