<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
        $this->admin = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();
    }

    public function test_list_page_renders(): void
    {
        Customer::create(['name' => 'Alpha Srl', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 65, 'tax_rate' => 5]);
        Customer::create(['name' => 'Beta Snc', 'customer_type' => 'commercianti', 'coefficient_of_profitability' => 40, 'tax_rate' => 5]);

        $this->actingAs($this->admin);

        Livewire::test(ListCustomers::class)->assertOk();
    }

    public function test_can_create_customer(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'name' => 'New Customer',
                'customer_type' => 'artigiani',
                'coefficient_of_profitability' => 65.00,
                'tax_rate' => 5.00,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('customers', ['name' => 'New Customer']);
    }

    public function test_inps_discount_eligible_toggle_is_hidden_and_never_submitted_for_gestione_separata(): void
    {
        $this->actingAs($this->admin);

        // The form's Toggle for inps_discount_eligible is visible() only for
        // Commercianti/Artigiani, so fillForm's attempt to set it true for a
        // Gestione Separata customer never reaches submitted state — it's
        // this UI-level guard (backed by Customer::booted()'s saving-event
        // guard as the model-level backstop) that keeps the combination from
        // ever existing, not a rejected submission.
        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'name' => 'Gestione Separata Customer',
                'customer_type' => 'gestione_separata',
                'coefficient_of_profitability' => 78.00,
                'tax_rate' => 5.00,
                'inps_discount_eligible' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('customers', [
            'name' => 'Gestione Separata Customer',
            'inps_discount_eligible' => false,
        ]);
    }
}
