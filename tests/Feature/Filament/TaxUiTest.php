<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\PaymentCalendar;
use App\Filament\Pages\TaxSettings;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Widgets\PaymentsDueChart;
use App\Filament\Widgets\RevenueLimitWatch;
use App\Filament\Widgets\TaxStatsOverview;
use App\Filament\Widgets\UpcomingDeadlines;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\Setting;
use App\Models\TaxPayment;
use App\Models\User;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Support\Tax\TaxRules;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\ShieldSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class TaxUiTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-01');
        $this->seed(ShieldSeeder::class);
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);
        $this->actingAs(User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail());

        $this->customer = Customer::create(['name' => 'Ui Test', 'company' => 'Ui Srl', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);
        foreach (['2025-03-01' => 20000, '2026-01-10' => 30000, '2026-02-10' => 30000] as $d => $a) {
            IncomeData::create(['customer_id' => $this->customer->id, 'source' => 'sdi_fattura', 'transaction_date' => $d, 'sales_amount' => $a, 'taxable_sales' => $a, 'sale_taxes' => 0, 'tax_year' => (int) substr($d, 0, 4)]);
        }
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_home_dashboard_and_widgets_render_with_data(): void
    {
        $this->get('/')->assertOk();

        Livewire::test(TaxStatsOverview::class)->assertOk()->assertSee('Overdue')->assertSee('Due in the next 30 days');
        Livewire::test(UpcomingDeadlines::class)->assertOk()->assertSee('Ui Srl');
        Livewire::test(RevenueLimitWatch::class)->assertOk()->assertSee('Ui Srl'); // 60k in 2 months → projected 360k
        Livewire::test(PaymentsDueChart::class)->assertOk();
    }

    public function test_payment_calendar_filters_and_records_a_payment(): void
    {
        $this->get(PaymentCalendar::getUrl())->assertOk();

        $first = TaxPayment::where('payment_type', 'first_advance')->firstOrFail(); // due 2026-06-30 → overdue now

        Livewire::test(PaymentCalendar::class)
            ->set('status', 'overdue')
            ->assertSee('Ui Srl')
            ->assertSee('1790')
            ->set('search', 'nobody')
            ->assertSee('No payments match these filters')
            ->set('search', '')
            ->callAction('markPaid', data: ['paid_amount' => $first->amount, 'paid_date' => '2026-07-01'], arguments: ['type' => 'tax', 'id' => $first->id])
            ->assertHasNoActionErrors();

        $this->assertSame('paid', $first->fresh()->status->value);
    }

    public function test_partial_payment_keeps_the_payment_open(): void
    {
        $first = TaxPayment::where('payment_type', 'first_advance')->firstOrFail();

        Livewire::test(PaymentCalendar::class)
            ->callAction('markPaid', data: ['paid_amount' => 1, 'paid_date' => '2026-07-01'], arguments: ['type' => 'tax', 'id' => $first->id]);

        $this->assertNotSame('paid', $first->fresh()->status->value);
        $this->assertSame('1.00', $first->fresh()->paid_amount);
    }

    public function test_payment_calendar_exports_csv(): void
    {
        Livewire::test(PaymentCalendar::class)
            ->set('period', 'all')
            ->set('status', '')
            ->callAction('exportCsv')
            ->assertFileDownloaded('payment-calendar-2026-07-01.csv');
    }

    public function test_tax_settings_switches_the_rule_set(): void
    {
        $this->assertSame(TaxRules::LEGACY, TaxRules::mode());

        Livewire::test(TaxSettings::class)
            ->fillForm([TaxRules::SETTING => TaxRules::LAW])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(TaxRules::LAW, TaxRules::mode());
        $this->assertSame('law', Setting::get(TaxRules::SETTING));
    }

    public function test_ateco_code_suggests_and_applies_the_legal_coefficient(): void
    {
        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Edile Srl', 'customer_type' => 'artigiani', 'ateco_code' => '43.21.01', 'coefficient_of_profitability' => 67, 'tax_rate' => 15])
            ->assertSee('legal coefficient 86%')
            ->callFormComponentAction('coefficient_of_profitability', 'useSuggestedCoefficient')
            ->assertFormSet(['coefficient_of_profitability' => 86.0])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('86.00', Customer::where('name', 'Edile Srl')->value('coefficient_of_profitability'));
    }

    public function test_startup_rate_toggle_saves_and_drives_the_rate(): void
    {
        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Startup', 'customer_type' => 'commercianti', 'coefficient_of_profitability' => 40, 'tax_rate' => 15, 'business_start_date' => '2024-02-01', 'startup_rate_eligible' => true])
            ->assertFormFieldIsDisabled('tax_rate')
            ->call('create')
            ->assertHasNoFormErrors();

        $c = Customer::where('name', 'Startup')->firstOrFail();
        $this->assertTrue($c->startup_rate_eligible);
        $this->assertSame(5.0, $c->taxRateFor(2026));
        $this->assertSame(15.0, $c->taxRateFor(2029));
    }

    public function test_customer_list_shows_and_filters_overdue(): void
    {
        $clean = Customer::create(['name' => 'Clean', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$this->customer, $clean])
            ->filterTable('has_overdue', true)
            ->assertCanSeeTableRecords([$this->customer])
            ->assertCanNotSeeTableRecords([$clean]);
    }
}
