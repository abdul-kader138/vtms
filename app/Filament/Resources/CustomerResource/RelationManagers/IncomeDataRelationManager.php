<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Enums\IncomeSource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-mostly ledger view of a customer's imported income_data rows.
 * Delete is allowed so an admin can remove a bad import row by hand;
 * everything else (the rows themselves) is written by the importer in
 * app/Services/Tax/Import/*, not edited here.
 */
class IncomeDataRelationManager extends RelationManager
{
    protected static string $relationship = 'incomeData';

    protected static ?string $title = 'Income data';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tax_year')->label(__('Year'))->sortable(),
                TextColumn::make('source')->label(__('Source'))->badge()->formatStateUsing(fn (IncomeSource $state) => match ($state) {
                    IncomeSource::SdiFattura => 'SDI fattura',
                    IncomeSource::PrivatiDevice => 'Privati/device',
                })->sortable(),
                TextColumn::make('transaction_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('sales_amount')->label(__('Sales amount'))->money('EUR')->sortable(),
                TextColumn::make('taxable_sales')->label(__('Taxable sales'))->money('EUR')->sortable(),
                TextColumn::make('reference_no')->label(__('Reference'))->toggleable(),
                TextColumn::make('external_id')->label(__('External ID'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tax_year')->label(__('Year'))->options(fn () => $this->getOwnerRecord()->incomeData()->distinct()->orderByDesc('tax_year')->pluck('tax_year', 'tax_year')),
                SelectFilter::make('source')->label(__('Source'))->options([
                    IncomeSource::SdiFattura->value => 'SDI fattura',
                    IncomeSource::PrivatiDevice->value => 'Privati/device',
                ]),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->actions([
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
