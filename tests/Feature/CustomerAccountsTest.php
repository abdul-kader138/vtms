<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\Customer;
use App\Models\User;
use App\Models\UserSession;
use App\Services\DeviceLockService;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_sign_in_works_from_a_host_missing_from_the_stateful_list(): void
    {
        // The reported bug: APP at localhost:8000 while SANCTUM_STATEFUL_DOMAINS
        // only listed "localhost" and "127.0.0.1:8000" → "Session store not set".
        config(['sanctum.stateful' => ['localhost', '127.0.0.1:8000']]);
        User::factory()->create(['email' => 'c@example.com', 'password' => Hash::make('Secret123'), 'email_verified_at' => now()]);

        $this->withServerVariables(['HTTP_HOST' => 'localhost:8000'])
            ->withHeaders(['Origin' => 'http://localhost:8000', 'Referer' => 'http://localhost:8000/login'])
            ->postJson('http://localhost:8000/api/v1/auth/login', ['email' => 'c@example.com', 'password' => 'Secret123'])
            ->assertOk()
            // The portal needs the session cookie; without it every page
            // after sign-in would bounce back to /login.
            ->assertCookie(config('session.cookie'));
    }

    public function test_registration_and_linking_give_the_customer_role(): void
    {
        $this->seed(ShieldSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Sara', 'last_name' => 'Neri', 'email' => 'sara@example.com',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123',
        ])->assertSuccessful();
        $registered = User::where('email', 'sara@example.com')->first();
        $this->assertTrue($registered->hasRole(User::CUSTOMER_ROLE));
        $this->assertFalse($registered->isStaff());

        $this->actingAs(User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail());
        $customer = Customer::create(['name' => 'Linked']);
        Livewire::test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Luca', 'last_name' => 'Rossi', 'email' => 'luca@example.com',
                'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'customer_id' => $customer->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(User::where('email', 'luca@example.com')->first()->hasRole(User::CUSTOMER_ROLE));
    }

    public function test_customer_role_never_becomes_staff_even_with_a_permission(): void
    {
        $this->seed(ShieldSeeder::class);
        $role = Role::findOrCreate(User::CUSTOMER_ROLE, 'web');
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any_customer', 'guard_name' => 'web']));
        $user = User::factory()->create();
        $user->markAsCustomer();

        $this->assertFalse($user->fresh()->isStaff());
        $this->actingAs($user)->get('/panel')->assertForbidden();
    }

    public function test_device_lock_can_be_lifted_for_one_customer(): void
    {
        $locked = User::factory()->create();
        $exempt = User::factory()->create(['device_lock_exempt' => true]);
        foreach ([$locked, $exempt] as $user) {
            UserSession::forceCreate(['user_id' => $user->id, 'session_token' => 'tok-'.$user->id, 'device_fingerprint' => 'first-device', 'last_seen_at' => now()]);
        }

        app(DeviceLockService::class)->enforce($exempt, 'another-device');

        $this->expectException(ValidationException::class);
        app(DeviceLockService::class)->enforce($locked, 'another-device');
    }
}
