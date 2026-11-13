<?php

namespace App\Filament\Resources;

use App\Enums\CustomerType;
use App\Filament\Resources\InpsRateSlabResource\Pages;
use App\Models\InpsRateSlab;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InpsRateSlabResource extends Resource
{
    protected static ?string $model = InpsRateSlab::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('INPS Rate Slabs');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('slab_year')->label(__('Year'))->numeric()->required(),
            Select::make('customer_type')
                ->label(__('Customer type'))
                ->options(collect(CustomerType::cases())->mapWithKeys(fn (CustomerType $c) => [$c->value => $c->label()]))
                ->native(false)
                ->helperText(__('Leave empty to apply to all customer types.')),
            TextInput::make('income_from')->label(__('Income from'))->numeric()->required()->prefix('€'),
            TextInput::make('income_to')->label(__('Income to'))->numeric()->prefix('€')->helperText(__('Leave empty for unbounded.')),
            TextInput::make('inps_rate')->label(__('INPS rate (%)'))->numeric()->required()->suffix('%'),
            TextInput::make('fixed_amount')->label(__('Fixed amount'))->numeric()->prefix('€')->helperText(__('If set and this is the first matching slab, used instead of the percentage.')),
            TextInput::make('description')->label(__('Description'))->maxLength(255)->columnSpanFull(),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('slab_year')->label(__('Year'))->sortable(),
                TextColumn::make('customer_type')->label(__('Type'))->badge()->formatStateUsing(fn ($state) => $state?->label() ?? __('All types'))->sortable(),
                TextColumn::make('income_from')->label(__('From'))->money('EUR')->sortable(),
                TextColumn::make('income_to')->label(__('To'))->money('EUR')->placeholder(__('Unbounded'))->sortable(),
                TextColumn::make('inps_rate')->label(__('Rate'))->suffix('%')->sortable(),
                TextColumn::make('fixed_amount')->label(__('Fixed'))->money('EUR')->placeholder('—'),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->defaultSort('slab_year', 'desc')
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInpsRateSlabs::route('/'),
            'create' => Pages\CreateInpsRateSlab::route('/create'),
            'edit' => Pages\EditInpsRateSlab::route('/{record}/edit'),
        ];
    }
}
