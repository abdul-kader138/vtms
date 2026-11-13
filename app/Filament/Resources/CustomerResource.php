<?php

namespace App\Filament\Resources;

use App\Enums\CustomerType;
use App\Filament\Pages\CustomerTaxDashboard;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers\IncomeDataRelationManager;
use App\Models\Customer;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Support\Tax\AtecoGroup;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Throwable;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Tax Calculations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Customers');
    }

    public static function getModelLabel(): string
    {
        return __('Customer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Customers');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'company', 'email', 'vat_no'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('Identity'))
                ->icon('heroicon-o-identification')
                ->schema([
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                    TextInput::make('company')->label(__('Company'))->maxLength(255),
                    TextInput::make('vat_no')->label(__('VAT number (Partita IVA)'))->maxLength(50),
                    TextInput::make('codice_fiscale')->label(__('Tax code (codice fiscale)'))
                        ->maxLength(16)
                        ->rule('regex:/^([A-Za-z0-9]{16}|\d{11})$/')
                        ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper(trim($state)) : null)
                        ->helperText(__('Printed in the F24 header.')),
                    TextInput::make('email')->label(__('Email'))->email()->maxLength(255),
                    TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(50),
                    TextInput::make('address')->label(__('Address'))->maxLength(255),
                    TextInput::make('city')->label(__('City'))->maxLength(255),
                ])->columns(2),

            Section::make(__('Activity & coefficient'))
                ->icon('heroicon-o-briefcase')
                ->description(__('The ATECO code determines the legal coefficient of profitability.'))
                ->schema([
                    Select::make('customer_type')
                        ->label(__('INPS category'))
                        ->options(collect(CustomerType::cases())->mapWithKeys(fn (CustomerType $c) => [$c->value => $c->label()]))
                        ->native(false)
                        ->live()
                        // Model-level guard too (Customer::booted) — this just
                        // explains it in the form instead of erroring on save.
                        ->disabled(fn (?Customer $record): bool => $record !== null && $record->customer_type !== null && $record->hasTaxCalculations())
                        ->helperText(fn (?Customer $record): string => $record !== null && $record->customer_type !== null && $record->hasTaxCalculations()
                            ? __('Locked: tax calculations already exist for this customer, and their INPS/INAIL/Diritto Annuale schedules depend on this type.')
                            : __('Artigiani and Commercianti pay fixed INPS installments; Gestione Separata pays a percentage.')),
                    TextInput::make('ateco_code')
                        ->label(__('ATECO 2007 code'))
                        ->placeholder('43.21.01')
                        ->maxLength(20)
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get): ?string => ($group = AtecoGroup::forAtecoCode($get('ateco_code')))
                            ? __(':group — legal coefficient :c%', ['group' => $group->label(), 'c' => $group->coefficient()])
                            : __('The forfettario coefficient still follows the ATECO 2007 code (as in the LM section of the tax return); there is no official table for ATECO 2025 codes yet.')),
                    TextInput::make('coefficient_of_profitability')
                        ->label(__('Coefficient of profitability'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(78.00)
                        ->required()
                        ->suffix('%')
                        ->hintAction(
                            FormAction::make('useSuggestedCoefficient')
                                ->label(fn (Get $get): string => __('Use :c%', ['c' => AtecoGroup::forAtecoCode($get('ateco_code'))?->coefficient()]))
                                ->icon('heroicon-o-sparkles')
                                ->visible(fn (Get $get): bool => ($group = AtecoGroup::forAtecoCode($get('ateco_code'))) !== null
                                    && (float) $get('coefficient_of_profitability') !== $group->coefficient())
                                ->action(fn (Get $get, Set $set) => $set('coefficient_of_profitability', AtecoGroup::forAtecoCode($get('ateco_code'))->coefficient()))
                        ),
                ])->columns(3),

            Section::make(__('Flat tax'))
                ->icon('heroicon-o-receipt-percent')
                ->schema([
                    DatePicker::make('business_start_date')
                        ->label(__('Business start date'))
                        ->live()
                        ->native(false)
                        ->displayFormat('d/m/Y'),
                    Toggle::make('startup_rate_eligible')
                        ->label(__('Start-up rate (5% for 5 years)'))
                        ->live()
                        ->inline(false)
                        ->helperText(fn (Get $get): string => ($start = $get('business_start_date'))
                            ? __('5% from :from to :to, then 15%.', ['from' => Carbon::parse($start)->year, 'to' => Carbon::parse($start)->year + Customer::STARTUP_YEARS - 1])
                            : __('New activities that meet the legal conditions. Needs a business start date.')),
                    TextInput::make('tax_rate')
                        ->label(__('Flat tax rate'))
                        ->numeric()
                        ->default(15.00)
                        ->required()
                        ->suffix('%')
                        ->disabled(fn (Get $get): bool => (bool) $get('startup_rate_eligible'))
                        ->dehydrated()
                        ->helperText(fn (Get $get): string => $get('startup_rate_eligible')
                            ? __('Set automatically each year by the start-up rule.')
                            : __('Standard rate is 15%.')),
                ])->columns(3),

            Section::make(__('Regime limits & INPS'))
                ->icon('heroicon-o-shield-exclamation')
                ->collapsible()
                ->schema([
                    TextInput::make('annual_revenue_limit')
                        ->label(__('Annual revenue limit'))
                        ->numeric()
                        ->default(85000.00)
                        ->prefix('€')
                        ->helperText(__('The tax dashboard warns when actual or projected revenue exceeds it.')),
                    TextInput::make('employee_cost_limit')
                        ->label(__('Employee cost limit'))
                        ->numeric()
                        ->default(20000.00)
                        ->prefix('€')
                        ->helperText(__('For reference only — employee costs are not tracked in this system.')),
                    Toggle::make('inps_discount_eligible')
                        ->label(__('INPS 35% reduction'))
                        ->inline(false)
                        ->visible(fn (Get $get): bool => in_array($get('customer_type'), [CustomerType::Commercianti->value, CustomerType::Artigiani->value], true))
                        ->helperText(__('Artigiani/Commercianti in Regime Forfettario who applied for it. The percentage is set in Tax Settings.')),
                    TextInput::make('inps_office_code')
                        ->label(__('INPS office code (codice sede)'))
                        ->maxLength(4)
                        ->rule('digits:4')
                        ->visible(fn (Get $get): bool => in_array($get('customer_type'), [CustomerType::Commercianti->value, CustomerType::Artigiani->value], true)),
                    TextInput::make('inps_code')
                        ->label(__('Codice INPS (17 digits)'))
                        ->maxLength(17)
                        ->rule('digits:17')
                        ->helperText(__('From the INPS payment notice. Printed on the F24.'))
                        ->visible(fn (Get $get): bool => in_array($get('customer_type'), [CustomerType::Commercianti->value, CustomerType::Artigiani->value], true)),
                    Toggle::make('gs_other_pension')
                        ->label(__('Already has a pension or other compulsory cover'))
                        ->helperText(__('Gestione Separata: pays under F24 code P10 instead of PXX.'))
                        ->inline(false)
                        ->visible(fn (Get $get): bool => $get('customer_type') === CustomerType::GestioneSeparata->value),
                ])->columns(3),

            Section::make(__('Chamber of Commerce (Diritto Annuale)'))
                ->icon('heroicon-o-building-library')
                ->description(__('Used by the "Italian law" rules: a fixed yearly fee by register section, plus the local surcharge.'))
                ->collapsible()
                ->visible(fn (Get $get): bool => in_array($get('customer_type'), [CustomerType::Commercianti->value, CustomerType::Artigiani->value], true))
                ->schema([
                    Select::make('chamber_section')
                        ->label(__('Registro Imprese section'))
                        ->options([
                            'speciale' => __('Sezione speciale — artigiani, small businesses'),
                            'ordinaria' => __('Sezione ordinaria'),
                        ])
                        ->default('speciale')
                        ->native(false)
                        ->required(),
                    Toggle::make('chamber_surcharge')
                        ->label(__('Chamber applies the surcharge'))
                        ->helperText(__('Most Camere di Commercio add 20% (set in Tax Settings).'))
                        ->default(true)
                        ->inline(false),
                    TextInput::make('chamber_province')
                        ->label(__('Chamber province (codice ente)'))
                        ->placeholder('FI')
                        ->maxLength(2)
                        ->rule('alpha')
                        ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper($state) : null)
                        ->helperText(__('Printed on the Diritto Annuale line of the F24.')),
                ])->columns(2),

            Section::make(__('INAIL'))
                ->icon('heroicon-o-lifebuoy')
                ->description(__('Accident insurance — Artigiani only.'))
                ->visible(fn (Get $get): bool => $get('customer_type') === CustomerType::Artigiani->value)
                ->schema([
                    TextInput::make('inail_ateco_code')->label(__('INAIL ATECO code'))->maxLength(20),
                    TextInput::make('inail_rate')->label(__('INAIL rate'))->numeric()->suffix('%'),
                    TextInput::make('inail_minimum_payment')->label(__('INAIL minimum payment'))->numeric()->prefix('€'),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        $overdue = fn ($q) => $q->where(fn ($q) => $q->where('status', 'overdue')
            ->orWhere(fn ($q) => $q->where('status', 'pending')->whereDate('due_date', '<', today())));
        $relations = ['taxPayments', 'inpsPayments', 'inailPayments', 'dirittoAnnualePayments', 'fatturaTraPrivatiPayments'];

        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(collect($relations)->mapWithKeys(fn ($r) => ["{$r} as {$r}_overdue_count" => $overdue])->all()))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Customer'))
                    ->description(fn (Customer $record): ?string => $record->company)
                    ->searchable(['name', 'company'])
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('customer_type')
                    ->label(__('INPS category'))
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        CustomerType::Artigiani => 'warning',
                        CustomerType::Commercianti => 'info',
                        CustomerType::GestioneSeparata => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->sortable(),
                TextColumn::make('coefficient_of_profitability')->label(__('Coefficient'))->formatStateUsing(fn ($state) => (float) $state.'%')->sortable()->alignEnd(),
                TextColumn::make('tax_rate')
                    ->label(__('Tax rate :year', ['year' => now()->year]))
                    ->state(fn (Customer $record): string => $record->taxRateFor(now()->year).'%')
                    ->description(fn (Customer $record): ?string => $record->startupRateEndsAfter() >= now()->year ? __('start-up until :y', ['y' => $record->startupRateEndsAfter()]) : null)
                    ->alignEnd(),
                TextColumn::make('overdue')
                    ->label(__('Overdue'))
                    ->state(fn (Customer $record): int => collect($relations)->sum(fn ($r) => (int) $record->{"{$r}_overdue_count"}))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? trans_choice(':count payment|:count payments', $state) : '—')
                    ->alignCenter(),
                TextColumn::make('email')->label(__('Email'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('city')->label(__('City'))->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->recordUrl(fn (Customer $record): string => CustomerTaxDashboard::getUrl(['customer' => $record->id]))
            ->filters([
                Filter::make('has_overdue')
                    ->label(__('Has overdue payments'))
                    ->toggle()
                    ->query(fn ($query) => $query->where(fn ($q) => collect($relations)->each(fn ($r) => $q->orWhereHas($r, $overdue)))),
                SelectFilter::make('customer_type')
                    ->label(__('Type'))
                    ->options(collect(CustomerType::cases())->mapWithKeys(fn (CustomerType $c) => [$c->value => $c->label()])),
            ])
            ->actions([
                Action::make('taxDashboard')
                    ->label(__('Tax dashboard'))
                    ->icon('heroicon-o-chart-bar')
                    ->url(fn (Customer $record): string => CustomerTaxDashboard::getUrl(['customer' => $record->id])),
                ActionGroup::make([
                    Action::make('calculate')
                        ->label(__('Calculate'))
                        ->icon('heroicon-o-calculator')
                        ->form([self::yearField()])
                        ->action(function (Customer $record, array $data) {
                            $year = (int) $data['year'];

                            try {
                                app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($record, $year);
                            } catch (Throwable $e) {
                                Notification::make()->danger()->title(__('Calculation failed'))->body($e->getMessage())->send();

                                return;
                            }

                            Notification::make()->success()
                                ->title(__('Tax calculated for :year', ['year' => $year]))
                                ->actions([\Filament\Notifications\Actions\Action::make('view')->label(__('Open dashboard'))->url(CustomerTaxDashboard::getUrl(['customer' => $record->id, 'year' => $year]))])
                                ->send();
                        }),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('calculate')
                        ->label(__('Calculate tax for year'))
                        ->icon('heroicon-o-calculator')
                        ->form([self::yearField()])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data) {
                            $year = (int) $data['year'];
                            $failed = [];

                            foreach ($records as $record) {
                                if ($record->customer_type === null) {
                                    $failed[] = $record->name.' ('.__('no customer type').')';

                                    continue;
                                }

                                try {
                                    app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($record, $year);
                                } catch (Throwable $e) {
                                    $failed[] = $record->name.' ('.$e->getMessage().')';
                                }
                            }

                            $done = $records->count() - count($failed);

                            Notification::make()
                                ->status($failed === [] ? 'success' : 'warning')
                                ->title(__(':done of :total customers calculated for :year', ['done' => $done, 'total' => $records->count(), 'year' => $year]))
                                ->body($failed === [] ? null : __('Skipped: :list', ['list' => implode(', ', $failed)]))
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function yearField(): TextInput
    {
        return TextInput::make('year')
            ->label(__('Tax year'))
            ->numeric()
            ->integer()
            ->minValue(2000)
            ->maxValue(now()->year + 1)
            ->default(now()->year)
            ->required();
    }

    public static function getRelations(): array
    {
        return [
            IncomeDataRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
