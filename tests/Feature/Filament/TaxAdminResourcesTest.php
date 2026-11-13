<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\TaxSettings;
use App\Filament\Resources\DirittoAnnualeBracketResource\Pages\CreateDirittoAnnualeBracket;
use App\Filament\Resources\DirittoAnnualeBracketResource\Pages\ListDirittoAnnualeBrackets;
use App\Filament\Resources\InpsRateSlabResource\Pages\CreateInpsRateSlab;
use App\Filament\Resources\InpsRateSlabResource\Pages\ListInpsRateSlabs;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaxAdminResourcesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
        $this->admin = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_inps_rate_slab_crud(): void
    {
        Livewire::test(ListInpsRateSlabs::class)->assertOk();

        Livewire::test(CreateInpsRateSlab::class)
            ->fillForm([
                'slab_year' => 2026,
                'income_from' => 0,
                'income_to' => 10000,
                'inps_rate' => 20,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('inps_rate_slabs', ['slab_year' => 2026, 'inps_rate' => 20]);
    }

    public function test_diritto_annuale_bracket_crud(): void
    {
        Livewire::test(ListDirittoAnnualeBrackets::class)->assertOk();

        Livewire::test(CreateDirittoAnnualeBracket::class)
            ->fillForm([
                'bracket_year' => 2026,
                'revenue_from' => 0,
                'amount' => 53,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('diritto_annuale_brackets', ['bracket_year' => 2026, 'amount' => 53]);
    }

    public function test_tax_settings_page_loads_and_saves(): void
    {
        Livewire::test(TaxSettings::class)
            ->assertOk()
            ->fillForm([
                'inps_discount_percent' => 40,
                'stamp_duty_threshold' => 77.47,
                'stamp_duty_fee' => 2.00,
            ])
            ->call('save');

        $this->assertEquals(40.0, (float) Setting::get('inps_discount_percent'));
    }
}
