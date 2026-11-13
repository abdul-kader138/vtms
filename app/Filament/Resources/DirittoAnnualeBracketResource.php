<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DirittoAnnualeBracketResource\Pages;
use App\Models\DirittoAnnualeBracket;
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

class DirittoAnnualeBracketResource extends Resource
{
    protected static ?string $model = DirittoAnnualeBracket::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 21;

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('Diritto Annuale Brackets');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('bracket_year')->label(__('Year'))->numeric()->required(),
            TextInput::make('revenue_from')->label(__('Revenue from'))->numeric()->required()->prefix('€'),
            TextInput::make('revenue_to')->label(__('Revenue to'))->numeric()->prefix('€')->helperText(__('Leave empty for unbounded.')),
            TextInput::make('amount')->label(__('Amount due'))->numeric()->required()->prefix('€'),
            TextInput::make('description')->label(__('Description'))->maxLength(255)->columnSpanFull(),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bracket_year')->label(__('Year'))->sortable(),
                TextColumn::make('revenue_from')->label(__('Revenue from'))->money('EUR')->sortable(),
                TextColumn::make('revenue_to')->label(__('Revenue to'))->money('EUR')->placeholder(__('Unbounded'))->sortable(),
                TextColumn::make('amount')->label(__('Amount'))->money('EUR')->sortable(),
                TextColumn::make('description')->label(__('Description'))->limit(40),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->defaultSort('bracket_year', 'desc')
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
            'index' => Pages\ListDirittoAnnualeBrackets::route('/'),
            'create' => Pages\CreateDirittoAnnualeBracket::route('/create'),
            'edit' => Pages\EditDirittoAnnualeBracket::route('/{record}/edit'),
        ];
    }
}
