<?php

namespace App\Filament\Pages;

use App\Models\Setting;
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
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
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
                        ->helperText(__('Invoices at or above this amount count toward Fattura Tra Privati.')),
                    TextInput::make('stamp_duty_fee')
                        ->label(__('Stamp duty fee per invoice'))
                        ->numeric()
                        ->required()
                        ->prefix('€'),
                ])->columns(3),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            Setting::set($key, (float) $value, 'tax');
        }

        Notification::make()->success()->title(__('Settings saved'))->send();
    }
}
