<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Enums\IncomeSource;
use App\Filament\Pages\ImportIncome;
use App\Models\IncomeData;
use App\Services\Tax\IncomeCollectionService;
use App\Support\Tax\InvoiceDocumentType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ledger of a customer's imported income rows. Rows themselves come from
 * the importer (app/Services/Tax/Import/*); here staff can record when an
 * invoice was actually collected (cash basis) or delete a bad row.
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
                TextColumn::make('document_type')->label(__('Document'))->badge()
                    ->formatStateUsing(fn (?string $state) => InvoiceDocumentType::label($state))
                    ->color(fn (?string $state) => InvoiceDocumentType::isCreditNote($state) ? 'danger' : 'gray')
                    ->placeholder(__('Receipts')),
                TextColumn::make('transaction_date')->label(__('Date'))->date('d/m/Y')->sortable(),
                TextColumn::make('collected_on')->label(__('Collected'))->date('d/m/Y')->sortable()
                    ->placeholder(__('Not collected'))
                    ->color(fn (IncomeData $record) => $record->collected_on === null ? 'warning' : ($record->collected_on->year !== $record->transaction_date->year ? 'info' : null))
                    ->tooltip(fn (IncomeData $record) => $record->collected_on && $record->collected_on->year !== $record->transaction_date->year
                        ? __('Counts as :year revenue (cash basis).', ['year' => $record->collected_on->year]) : null),
                TextColumn::make('sales_amount')->label(__('Sales amount'))->money('EUR')->sortable()
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : null),
                TextColumn::make('taxable_sales')->label(__('Taxable sales'))->money('EUR')->sortable(),
                TextColumn::make('reference_no')->label(__('Reference'))->toggleable(),
                TextColumn::make('external_id')->label(__('External ID'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('importBatch.original_filename')->label(__('Imported from'))->limit(28)
                    ->description(fn ($record) => $record->importBatch?->created_at?->format('d/m/Y H:i'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tax_year')->label(__('Year'))->options(fn () => $this->getOwnerRecord()->incomeData()->distinct()->orderByDesc('tax_year')->pluck('tax_year', 'tax_year')),
                SelectFilter::make('source')->label(__('Source'))->options([
                    IncomeSource::SdiFattura->value => 'SDI fattura',
                    IncomeSource::PrivatiDevice->value => 'Privati/device',
                ]),
                Filter::make('not_collected')->label(__('Not collected yet'))->toggle()
                    ->query(fn ($query) => $query->whereNull('collected_on')),
                Filter::make('credit_notes')->label(__('Credit notes only'))->toggle()
                    ->query(fn ($query) => $query->whereIn('document_type', InvoiceDocumentType::CREDIT_NOTES)),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('importBatch'))
            ->headerActions([
                Action::make('import')->label(__('Import income'))->icon('heroicon-m-arrow-up-tray')
                    ->url(ImportIncome::getUrl()),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->actions([
                Action::make('collected')
                    ->label(__('Collection date'))
                    ->tooltip(__('Collection date'))
                    ->iconButton()
                    ->icon('heroicon-m-banknotes')
                    ->color('gray')
                    ->fillForm(fn (IncomeData $record) => ['collected' => $record->collected_on !== null, 'collected_on' => $record->collected_on ?? $record->transaction_date])
                    ->form($this->collectionForm())
                    ->action(fn (IncomeData $record, array $data) => $this->saveCollection(collect([$record]), $data)),
                DeleteAction::make()->iconButton(),
            ])
            ->bulkActions([
                BulkAction::make('collected')
                    ->label(__('Set collection date'))
                    ->icon('heroicon-m-banknotes')
                    ->form($this->collectionForm())
                    ->fillForm(['collected' => true, 'collected_on' => now()->toDateString()])
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records, array $data) => $this->saveCollection($records, $data)),
                DeleteBulkAction::make(),
            ]);
    }

    private function collectionForm(): array
    {
        return [
            Placeholder::make('why')->hiddenLabel()
                ->content(__('Flat-tax income is taxed when it is collected. An invoice dated December but paid in January counts in January\'s year.')),
            Toggle::make('collected')->label(__('Collected'))->live()->default(true),
            DatePicker::make('collected_on')->label(__('Collected on'))
                ->native(false)->displayFormat('d/m/Y')->maxDate(now())
                ->visible(fn (Get $get) => (bool) $get('collected'))
                ->required(fn (Get $get) => (bool) $get('collected')),
        ];
    }

    private function saveCollection(Collection $records, array $data): void
    {
        $result = app(IncomeCollectionService::class)->setCollectedOn(
            $records,
            ($data['collected'] ?? false) ? Carbon::parse($data['collected_on']) : null,
        );

        Notification::make()
            ->{$result['failed'] === [] ? 'success' : 'warning'}()
            ->title(trans_choice('{1} Collection date saved|[2,*] Collection date saved for :count rows', $result['updated']))
            ->body(implode("\n", array_filter([
                $result['recalculated'] !== [] ? __('Recalculated: :years.', ['years' => implode(', ', $result['recalculated'])]) : null,
                ...$result['failed'],
            ])) ?: null)
            ->send();
    }
}
