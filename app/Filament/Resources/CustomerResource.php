<?php

namespace App\Filament\Resources;

use App\Enums\CustomerType;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers\IncomeDataRelationManager;
use App\Models\Customer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
            Section::make(__('Identity'))->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                TextInput::make('company')->label(__('Company'))->maxLength(255),
                TextInput::make('vat_no')->label(__('VAT number'))->maxLength(50),
                TextInput::make('email')->label(__('Email'))->email()->maxLength(255),
                TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(50),
                TextInput::make('address')->label(__('Address'))->maxLength(255),
                TextInput::make('city')->label(__('City'))->maxLength(255),
            ])->columns(2),

            Section::make(__('Tax settings'))
                ->description(__('Regime Forfettario settings used by the tax-calculation engine.'))
                ->schema([
                    Select::make('customer_type')
                        ->label(__('Customer type'))
                        ->options(collect(CustomerType::cases())->mapWithKeys(fn (CustomerType $c) => [$c->value => $c->label()]))
                        ->native(false)
                        ->live()
                        ->helperText(__('INPS contributor category. Determines INAIL eligibility and the INPS discount/installment schedule.')),
                    TextInput::make('coefficient_of_profitability')
                        ->label(__('Coefficient of profitability (%)'))
                        ->numeric()
                        ->default(78.00)
                        ->required()
                        ->suffix('%'),
                    TextInput::make('tax_rate')
                        ->label(__('Flat tax rate (%)'))
                        ->numeric()
                        ->default(5.00)
                        ->required()
                        ->suffix('%'),
                    DatePicker::make('business_start_date')
                        ->label(__('Business start date'))
                        ->helperText(__('No tax balance payment is generated for this customer in their own start year.')),
                    Toggle::make('inps_discount_eligible')
                        ->label(__('Eligible for INPS discount'))
                        ->live()
                        ->visible(fn (Get $get): bool => in_array($get('customer_type'), [CustomerType::Commercianti->value, CustomerType::Artigiani->value], true))
                        ->helperText(__('Only available for Commercianti/Artigiani. The discount percentage is configured in Tax Settings.')),
                    TextInput::make('annual_revenue_limit')
                        ->label(__('Annual revenue limit'))
                        ->numeric()
                        ->default(85000.00)
                        ->prefix('€')
                        ->helperText(__('Regime Forfettario eligibility threshold — enforced by the tax engine.')),
                    TextInput::make('employee_cost_limit')
                        ->label(__('Employee cost limit'))
                        ->numeric()
                        ->default(20000.00)
                        ->prefix('€')
                        ->helperText(__('Regime Forfettario eligibility threshold — enforced by the tax engine.')),
                ])->columns(2),

            Section::make(__('INAIL (Artigiani only)'))
                ->visible(fn (Get $get): bool => $get('customer_type') === CustomerType::Artigiani->value)
                ->schema([
                    TextInput::make('inail_ateco_code')->label(__('ATECO code'))->maxLength(20),
                    TextInput::make('inail_rate')->label(__('INAIL rate (%)'))->numeric()->suffix('%'),
                    TextInput::make('inail_minimum_payment')->label(__('INAIL minimum payment'))->numeric()->prefix('€'),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('company')->label(__('Company'))->searchable()->toggleable(),
                TextColumn::make('customer_type')->label(__('Type'))->badge()->formatStateUsing(fn ($state) => $state?->label())->sortable(),
                TextColumn::make('coefficient_of_profitability')->label(__('Coefficient'))->suffix('%')->sortable(),
                TextColumn::make('tax_rate')->label(__('Tax rate'))->suffix('%')->sortable(),
                TextColumn::make('email')->label(__('Email'))->searchable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('customer_type')
                    ->label(__('Type'))
                    ->options(collect(CustomerType::cases())->mapWithKeys(fn (CustomerType $c) => [$c->value => $c->label()])),
            ])
            ->actions([
                Action::make('taxDashboard')
                    ->label(__('Tax dashboard'))
                    ->icon('heroicon-o-calculator')
                    ->url(fn (Customer $record): string => \App\Filament\Pages\CustomerTaxDashboard::getUrl(['customer' => $record->id])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
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
