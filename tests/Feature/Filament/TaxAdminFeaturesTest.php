<?php

namespace Tests\Feature\Filament;

use App\Enums\TaxPaymentType;
use App\Filament\Pages\CustomerTaxDashboard;
use App\Filament\Pages\HistoricalYearEntry;
use App\Filament\Pages\TaxSettings;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\Setting;
use App\Models\TaxPayment;
use App\Models\User;
use App\Services\Tax\HistoricalYearTemplateService;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Services\Tax\TaxCalculationService;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\ShieldSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use Tests\TestCase;

class TaxAdminFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);

        $this->admin = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();
        $this->customer = Customer::create(['name' => 'Admin Features', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);

        IncomeData::create([
            'customer_id' => $this->customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 20000, 'taxable_sales' => 20000, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);

        $this->actingAs($this->admin);
    }

    public function test_customer_type_cannot_change_once_calculations_exist(): void
    {
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);

        $this->expectException(RuntimeException::class);
        $this->customer->fresh()->update(['customer_type' => 'commercianti']);
    }

    public function test_customer_type_field_is_disabled_on_the_form_once_calculations_exist(): void
    {
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);

        Livewire::test(EditCustomer::class, ['record' => $this->customer->id])
            ->assertFormFieldIsDisabled('customer_type')
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed', $this->customer->fresh()->name);
        $this->assertSame('artigiani', $this->customer->fresh()->customer_type->value);
    }

    public function test_customer_type_is_still_editable_without_calculations(): void
    {
        Livewire::test(EditCustomer::class, ['record' => $this->customer->id])
            ->assertFormFieldIsEnabled('customer_type');
    }

    public function test_calculate_row_action_runs_the_engine_for_the_chosen_year(): void
    {
        Livewire::test(ListCustomers::class)
            ->callTableAction('calculate', $this->customer, data: ['year' => 2025])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('tax_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025, 'taxable_income' => 13400]);
    }

    public function test_bulk_calculate_skips_customers_without_a_type(): void
    {
        $untyped = Customer::create(['name' => 'No Type', 'coefficient_of_profitability' => 78, 'tax_rate' => 5]);

        Livewire::test(ListCustomers::class)
            ->callTableBulkAction('calculate', [$this->customer, $untyped], data: ['year' => 2025])
            ->assertHasNoTableBulkActionErrors();

        $this->assertDatabaseHas('tax_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);
        $this->assertDatabaseMissing('tax_calculations', ['customer_id' => $untyped->id]);
    }

    public function test_edit_payment_action_changes_amount_and_due_date(): void
    {
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);
        $payment = TaxPayment::where('customer_id', $this->customer->id)->where('payment_type', TaxPaymentType::Balance)->firstOrFail();

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->callAction('editPayment', data: [
                'amount' => '123.45',
                'due_date' => '2026-03-02',
                'status' => 'pending',
                'paid_amount' => null,
                'paid_date' => null,
            ], arguments: ['type' => 'tax', 'id' => $payment->id])
            ->assertHasNoActionErrors();

        $payment->refresh();
        $this->assertSame('123.45', $payment->amount);
        $this->assertSame('2026-03-02', $payment->due_date->toDateString());
        $this->assertSame('0.00', $payment->paid_amount);
    }

    public function test_edit_payment_rejects_another_customers_payment(): void
    {
        $other = Customer::create(['name' => 'Other', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);
        IncomeData::create([
            'customer_id' => $other->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 5000, 'taxable_sales' => 5000, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($other, 2025);
        $foreign = TaxPayment::where('customer_id', $other->id)->where('payment_type', TaxPaymentType::Balance)->firstOrFail();
        $originalAmount = $foreign->amount;

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->callAction('editPayment', data: ['amount' => '1', 'due_date' => '2026-01-01', 'status' => 'paid'], arguments: ['type' => 'tax', 'id' => $foreign->id]);

        $this->assertSame($originalAmount, $foreign->fresh()->amount);
    }

    public function test_dashboard_shows_prediction_and_summary_before_calculation(): void
    {
        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->assertOk()
            ->assertSee('Estimate &amp; prediction', false)
            ->assertSee('Predicted total sales')
            ->assertSee('Outstanding')
            ->assertActionHidden('annualTaxReport');
    }

    public function test_historical_advances_are_stored_against_the_following_year(): void
    {
        Livewire::test(HistoricalYearEntry::class, ['customer' => $this->customer->id])
            ->fillForm([
                'year' => 2023,
                'tax' => ['total_sales' => 30000, 'taxable_income' => 20100, 'tax_due' => 1005, 'balance_payment' => 1005, 'next_year_advance_base' => 804],
                'tax_payments' => [
                    ['payment_type' => 'balance', 'amount' => 1005, 'due_date' => '2024-02-16', 'status' => 'paid'],
                    ['payment_type' => 'first_advance', 'amount' => 402, 'due_date' => '2024-06-30', 'status' => 'paid'],
                    ['payment_type' => 'second_advance', 'amount' => 402, 'due_date' => '2024-11-30', 'status' => 'paid'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tax_payments', ['customer_id' => $this->customer->id, 'payment_type' => 'balance', 'tax_year' => 2023]);
        $this->assertDatabaseHas('tax_payments', ['customer_id' => $this->customer->id, 'payment_type' => 'first_advance', 'tax_year' => 2024]);
        $this->assertDatabaseHas('tax_payments', ['customer_id' => $this->customer->id, 'payment_type' => 'second_advance', 'tax_year' => 2024]);

        // …so a 2024 calculation subtracts them from the 2024 balance.
        IncomeData::create([
            'customer_id' => $this->customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2024-03-01',
            'sales_amount' => 30000, 'taxable_sales' => 30000, 'sale_taxes' => 0, 'tax_year' => 2024,
        ]);
        $calc = app(TaxCalculationService::class)->calculateForYear($this->customer->fresh(), 2024);
        $this->assertSame('804.00', $calc->advance_payments_made);
    }

    public function test_template_download_contains_the_customers_fields(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl');
        app(HistoricalYearTemplateService::class)->writeXlsx($this->customer, $path);

        $keys = collect(IOFactory::load($path)->getSheetByName(HistoricalYearTemplateService::SHEET)->toArray())->pluck(0)->filter()->all();
        @unlink($path);

        $this->assertContains('tax.total_sales', $keys);
        $this->assertContains('tax_payments.first_advance.amount', $keys);
        $this->assertContains('inps_payments.4.amount', $keys);
        $this->assertContains('inail.inail_final_amount', $keys); // Artigiani only
        $this->assertContains('diritto_annuale_payment.amount', $keys);
    }

    public function test_filled_template_round_trips_into_form_state(): void
    {
        $service = app(HistoricalYearTemplateService::class);
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $service->writeXlsx($this->customer, $path);

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName(HistoricalYearTemplateService::SHEET);
        $fill = [
            'year' => '2023',
            'tax.total_sales' => '30000',
            'tax.tax_due' => '1005,50', // Italian decimal comma
            'tax_payments.balance.amount' => '1005.50',
            'tax_payments.balance.status' => 'paid',
            'tax_payments.first_advance.amount' => '402',
            'tax_payments.first_advance.due_date' => '2024-07-01',
            'inps_payments.2.amount' => '900',
        ];
        foreach ($sheet->getRowIterator(2) as $row) {
            $key = $sheet->getCell('A'.$row->getRowIndex())->getValue();
            if (isset($fill[$key])) {
                $sheet->setCellValue('F'.$row->getRowIndex(), $fill[$key]);
            }
        }
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

        $state = $service->readFormState($path, $this->customer);
        @unlink($path);

        $this->assertSame(2023, $state['year']);
        $this->assertSame(30000.0, $state['tax']['total_sales']);
        $this->assertSame(1005.5, $state['tax']['tax_due']);
        $this->assertSame(['payment_type' => 'balance', 'amount' => 1005.5, 'status' => 'paid', 'due_date' => '2024-02-16'], $state['tax_payments'][0]);
        $this->assertSame('2024-07-01', $state['tax_payments'][1]['due_date']);
        $this->assertCount(2, $state['tax_payments']);
        // Artigiani INPS installment 2 defaults to 20 August of the same year.
        $this->assertSame(['installment_number' => 2, 'amount' => 900.0, 'status' => 'pending', 'due_date' => '2023-08-20'], $state['inps_payments'][0]);
        $this->assertArrayNotHasKey('inail_payment', $state);
    }

    public function test_reading_a_template_without_a_year_fails_clearly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['Field Key', 'x'], ['tax.total_sales', '', '', '', '', '5']]);
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

        $this->expectException(RuntimeException::class);
        try {
            app(HistoricalYearTemplateService::class)->readFormState($path, $this->customer);
        } finally {
            @unlink($path);
        }
    }

    public function test_gestione_separata_historical_year_saves_without_inail_or_diritto_sections(): void
    {
        $gs = Customer::create(['name' => 'GS', 'customer_type' => 'gestione_separata', 'coefficient_of_profitability' => 78, 'tax_rate' => 5]);

        Livewire::test(HistoricalYearEntry::class, ['customer' => $gs->id])
            ->assertFormComponentDoesNotExist('inail.inail_rate')
            ->fillForm([
                'year' => 2022,
                'tax' => ['total_sales' => 10000, 'taxable_income' => 7800, 'tax_due' => 390, 'balance_payment' => 390, 'next_year_advance_base' => 312],
                'tax_payments' => [['payment_type' => 'balance', 'amount' => 390, 'due_date' => '2023-02-16', 'status' => 'pending']],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tax_payments', ['customer_id' => $gs->id, 'payment_type' => 'balance', 'tax_year' => 2022, 'paid_amount' => 0]);
        $this->assertDatabaseCount('fattura_tra_privati_payments', 0);
    }

    public function test_template_download_action_returns_an_xlsx(): void
    {
        Livewire::test(HistoricalYearEntry::class, ['customer' => $this->customer->id])
            ->callAction('downloadTemplate')
            ->assertFileDownloaded('historical_year_template_customer_'.$this->customer->id.'.xlsx');
    }

    public function test_tax_settings_saves_the_new_eligibility_and_reminder_values(): void
    {
        Livewire::test(TaxSettings::class)
            ->fillForm(['forfettario_immediate_exit_threshold' => 95000, 'payment_reminder_days' => 10])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(95000, Setting::get('forfettario_immediate_exit_threshold'));
        $this->assertEquals(10, Setting::get('payment_reminder_days'));
    }
}
