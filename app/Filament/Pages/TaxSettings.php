<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\Tax\RavvedimentoService;
use App\Support\Tax\TaxRules;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class TaxSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 22;

    protected static string $view = 'filament.pages.tax-settings';

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Tax Settings');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public function getTitle(): string
    {
        return __('Tax Settings');
    }

    public function mount(): void
    {
        $this->form->fill([
            'inps_discount_percent' => Setting::get('inps_discount_percent', 35.00),
            'stamp_duty_threshold' => Setting::get('stamp_duty_threshold', 77.47),
            'stamp_duty_fee' => Setting::get('stamp_duty_fee', 2.00),
            'forfettario_immediate_exit_threshold' => Setting::get('forfettario_immediate_exit_threshold', 100000.00),
            'payment_reminder_days' => Setting::get('payment_reminder_days', 7),
            'diritto_annuale_base_speciale' => Setting::get('diritto_annuale_base_speciale', 44.00),
            'diritto_annuale_base_ordinaria' => Setting::get('diritto_annuale_base_ordinaria', 100.00),
            'diritto_annuale_surcharge_percent' => Setting::get('diritto_annuale_surcharge_percent', 20.00),
            TaxRules::SETTING => TaxRules::mode(),
            'legal_interest_rates' => collect(RavvedimentoService::rates())->mapWithKeys(fn ($rate, $year) => [(string) $year => (string) $rate])->all(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('Calculation rules'))
                ->description(__('Applies the next time a year is calculated or recalculated. Existing figures do not change until then.'))
                ->icon('heroicon-o-scale')
                ->schema([
                    Radio::make(TaxRules::SETTING)
                        ->hiddenLabel()
                        ->options(TaxRules::options())
                        ->descriptions([
                            TaxRules::LAW => __('Regime Forfettario as the law requires: income counts when collected (cash basis); INPS paid in the year is deducted from taxable income; Artigiani/Commercianti INPS is fixed contributions plus a percentage above the minimum income; advances are 100% of the tax (none up to €51.65, one payment on 30 Nov up to €257.52, otherwise 40% on 30 Jun and 60% on 30 Nov); balance and Diritto Annuale due 30 Jun; stamp duty paid quarterly on e-invoices over €77.47; deadlines move off weekends and public holidays.'),
                            TaxRules::LEGACY => __('Reproduces the original VTMS figures exactly: no INPS deduction, advances 80% split 40/40, balance due 16 Feb, Diritto Annuale 31 Mar, one stamp-duty payment a year.'),
                        ])
                        ->required(),
                ]),
            Section::make(__('Tax calculation constants'))
                ->description(__('Values used by the tax-calculation engine that are configurable rather than hardcoded.'))
                ->schema([
                    TextInput::make('inps_discount_percent')
                        ->label(__('INPS discount (%)'))
                        ->numeric()
                        ->required()
                        ->suffix('%')
                        ->helperText(__('Applied to the INPS amount for Commercianti/Artigiani customers marked eligible.')),
                    TextInput::make('stamp_duty_threshold')
                        ->label(__('Stamp duty threshold'))
                        ->numeric()
                        ->required()
                        ->prefix('€')
                        ->helperText(__('Invoices above this amount carry the €2 stamp duty.')),
                    TextInput::make('stamp_duty_fee')
                        ->label(__('Stamp duty fee per invoice'))
                        ->numeric()
                        ->required()
                        ->prefix('€'),
                ])->columns(3),
            Section::make(__('Diritto Annuale (Italian law rules)'))
                ->description(__('Yearly Chamber of Commerce fee for sole traders, rounded to the euro. Each customer\'s section and surcharge are set on the customer.'))
                ->schema([
                    TextInput::make('diritto_annuale_base_speciale')->label(__('Base — sezione speciale'))->numeric()->required()->prefix('€'),
                    TextInput::make('diritto_annuale_base_ordinaria')->label(__('Base — sezione ordinaria'))->numeric()->required()->prefix('€'),
                    TextInput::make('diritto_annuale_surcharge_percent')->label(__('Chamber surcharge'))->numeric()->required()->suffix('%'),
                ])->columns(3),
            Section::make(__('Late payments (ravvedimento)'))
                ->description(__('Legal interest rate for each year, set by decree every December. Used for the interest on late payments.'))
                ->collapsible()
                ->schema([
                    KeyValue::make('legal_interest_rates')
                        ->hiddenLabel()
                        ->keyLabel(__('Year'))
                        ->valueLabel(__('Rate (%)'))
                        ->addActionLabel(__('Add year'))
                        ->rules([fn () => function (string $attribute, $value, \Closure $fail) {
                            foreach ((array) $value as $year => $rate) {
                                if (! preg_match('/^\d{4}$/', (string) $year) || ! is_numeric($rate)) {
                                    $fail(__('Use a 4-digit year and a numeric rate, e.g. 2026 → 1.6.'));
                                }
                            }
                        }]),
                ]),
            Section::make(__('Eligibility & reminders'))
                ->schema([
                    TextInput::make('forfettario_immediate_exit_threshold')
                        ->label(__('Immediate exit threshold'))
                        ->numeric()
                        ->required()
                        ->prefix('€')
                        ->helperText(__('Revenue above this ends Regime Forfettario immediately (the per-customer annual revenue limit ends it from next year).')),
                    TextInput::make('payment_reminder_days')
                        ->label(__('Payment reminder lead time'))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->required()
                        ->suffix(__('days'))
                        ->helperText(__('Linked customer portal users are emailed this many days before a payment is due. 0 disables reminders.')),
                ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            Setting::set($key, match ($key) {
                TaxRules::SETTING => (string) $value,
                'legal_interest_rates' => collect($value)->mapWithKeys(fn ($rate, $year) => [(int) $year => (float) $rate])->sortKeys()->all(),
                'payment_reminder_days' => (int) $value,
                default => (float) $value,
            }, 'tax');
        }

        Notification::make()->success()->title(__('Settings saved'))->send();
    }
}
