<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\ForeignServicePurchase;
use App\Services\Tax\ForeignServiceIvaService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Digital services bought from abroad (Facebook/Google/Canva ads, SaaS
 * subscriptions...) — tracked so the non-refundable reverse-charge IVA cost
 * shows up in the hidden-costs panel. See App\Services\Tax\ForeignServiceIvaService.
 */
class ForeignServicePurchaseRelationManager extends RelationManager
{
    protected static string $relationship = 'foreignServicePurchases';

    protected static ?string $title = 'Foreign digital services';

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('purchase_date')->label(__('Purchase date'))->native(false)->displayFormat('d/m/Y')->required()->maxDate(now()),
            TextInput::make('provider')->label(__('Provider'))->placeholder(__('Facebook, Google Ads, Canva…'))->required()->maxLength(255),
            TextInput::make('description')->label(__('Description'))->maxLength(255),
            TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(0)->prefix('€')->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tax_year')->label(__('Year'))->sortable(),
                TextColumn::make('purchase_date')->label(__('Date'))->date('d/m/Y')->sortable(),
                TextColumn::make('provider')->label(__('Provider'))->searchable(),
                TextColumn::make('description')->label(__('Description'))->limit(40),
                TextColumn::make('amount')->label(__('Amount'))->money('EUR')->sortable(),
                TextColumn::make('iva')->label(__('Reverse-charge IVA (non-refundable)'))
                    ->state(fn (ForeignServicePurchase $record) => round((float) $record->amount * app(ForeignServiceIvaService::class)->rate() / 100, 2))
                    ->money('EUR'),
            ])
            ->filters([
                SelectFilter::make('tax_year')->label(__('Year'))->options(fn () => $this->getOwnerRecord()->foreignServicePurchases()->distinct()->orderByDesc('tax_year')->pluck('tax_year', 'tax_year')),
            ])
            ->defaultSort('purchase_date', 'desc')
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
