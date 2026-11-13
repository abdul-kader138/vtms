<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Models\IncomeImportBatch;
use App\Services\Tax\Import\IncomeDataImportService;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Upload → check (dry run, customer found from the file) → import, plus the
 * history of every import with one-click undo.
 */
class ImportIncome extends Page implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    public const UPLOAD_DIR = 'income-imports/uploads';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'filament.pages.import-income';

    /** @var array<string, mixed> */
    public array $data = ['files' => [], 'names' => [], 'customer_id' => null];

    /** @var list<array<string, mixed>> one entry per checked file */
    public array $previews = [];

    public static function getNavigationGroup(): ?string
    {
        return __('Tax Calculations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Import income');
    }

    public function getTitle(): string
    {
        return __('Import income');
    }

    public function getSubheading(): ?string
    {
        return __('Upload sales files from "Fatture e Corrispettivi" — the format and the customer are recognised automatically.');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                FileUpload::make('files')
                    ->label(__('Files'))
                    ->helperText(__('Invoices list or cash register export (CSV/Excel), e-invoices (.xml / .xml.p7m) or a ZIP of them. Up to 20 files, 20 MB each.'))
                    ->multiple()
                    ->maxFiles(20)
                    ->maxSize(20 * 1024)
                    ->disk('local')
                    ->directory(self::UPLOAD_DIR)
                    ->visibility('private')
                    ->storeFileNamesIn('names')
                    ->rules(['extensions:csv,txt,xls,xlsx,ods,xml,p7m,zip'])
                    ->required(),
                Select::make('customer_id')
                    ->label(__('Customer'))
                    ->placeholder(__('Detect from the file'))
                    ->helperText(__('Leave empty: invoices are matched by the seller VAT number, receipts by the cash register serial.'))
                    ->options(fn () => $this->customerOptions())
                    ->searchable(),
            ]);
    }

    public function check(): void
    {
        $data = $this->form->getState();
        $this->previews = [];

        foreach ($data['files'] ?? [] as $path) {
            $this->previews[] = $this->analyze($path, $data['names'][$path] ?? basename($path), $data['customer_id'] ?? null);
        }

        $this->form->fill(['customer_id' => $data['customer_id'] ?? null]);
    }

    /** Re-check one file after its customer was changed in the preview. */
    public function updatedPreviews(mixed $value, ?string $key = null): void
    {
        if ($key !== null && preg_match('/^(\d+)\.customer_id$/', $key, $m) && isset($this->previews[$m[1]])) {
            $p = $this->previews[$m[1]];
            $this->previews[$m[1]] = $this->analyze($p['path'], $p['file'], $value ?: null);
        }
    }

    public function import(): void
    {
        $importer = app(IncomeDataImportService::class);
        $done = [];
        $failed = [];

        foreach ($this->previews as $p) {
            if (! $this->importable($p) || ! $this->isUpload($p['path'])) {
                continue;
            }

            try {
                $batch = $importer->commit(Storage::disk('local')->path($p['path']), $p['file'], Customer::findOrFail($p['customer_id']), IncomeImportBatch::CHANNEL_UPLOAD, Auth::id(), $p['path']);
                $done[] = $batch;
            } catch (Throwable $e) {
                $failed[] = "{$p['file']}: {$e->getMessage()}";
            }
        }

        $this->discardUnimported(array_map(fn ($b) => $b->stored_path, $done));
        $this->previews = [];

        $rows = array_sum(array_map(fn ($b) => $b->imported, $done));
        $years = collect($done)->flatMap(fn ($b) => $b->recalculated_years ?? [])->unique()->sort()->implode(', ');

        Notification::make()
            ->title(trans_choice('{0} No files imported|{1} 1 file imported — :rows new rows|[2,*] :count files imported — :rows new rows', count($done), ['rows' => $rows]))
            ->body(implode("\n", array_filter([
                $years !== '' ? __('Recalculated: :years.', ['years' => $years]) : null,
                ...$failed,
            ])) ?: null)
            ->{$failed === [] ? 'success' : 'warning'}()
            ->persistent($failed !== [])
            ->send();
    }

    public function cancel(): void
    {
        $this->discardUnimported([]);
        $this->previews = [];
    }

    public function importableCount(): int
    {
        return count(array_filter($this->previews, fn ($p) => $this->importable($p)));
    }

    /** @return array<int, string> */
    public function customerOptions(): array
    {
        return Customer::orderBy('name')->get(['id', 'name', 'company', 'vat_no'])
            ->mapWithKeys(fn (Customer $c) => [$c->id => trim(($c->company ?: $c->name).($c->vat_no ? " · {$c->vat_no}" : ''))])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Import history'))
            ->query(IncomeImportBatch::query()->with(['customer', 'importedBy']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Imported'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('customer.name')->label(__('Customer'))
                    ->formatStateUsing(fn (IncomeImportBatch $r) => $r->customer?->company ?: $r->customer?->name)
                    ->url(fn (IncomeImportBatch $r) => $r->customer ? CustomerTaxDashboard::getUrl(['customer' => $r->customer_id, 'year' => $r->period_to?->year ?? now()->year]) : null)
                    ->searchable(),
                TextColumn::make('original_filename')->label(__('File'))->limit(32)->tooltip(fn (IncomeImportBatch $r) => $r->original_filename)->searchable(),
                TextColumn::make('channel')->label(__('How'))->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'inbox' => __('Inbox'), 'cli' => __('Command line'), default => __('Upload')
                    })
                    ->color(fn (string $state) => $state === 'inbox' ? 'info' : 'gray'),
                TextColumn::make('imported')->label(__('New rows'))->numeric()
                    ->description(fn (IncomeImportBatch $r) => $r->duplicates ? __(':n duplicates', ['n' => $r->duplicates]) : null),
                TextColumn::make('total_amount')->label(__('Amount'))->money('EUR'),
                TextColumn::make('period_from')->label(__('Period'))
                    ->formatStateUsing(fn (IncomeImportBatch $r) => $r->period_from ? $r->period_from->format('d/m/Y').' – '.$r->period_to?->format('d/m/Y') : '—'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (IncomeImportBatch $r) => match (true) {
                        $r->isRolledBack() => __('Undone'),
                        $r->failed > 0 || filled($r->warnings) => __('With notes'),
                        default => __('Imported'),
                    })
                    ->color(fn (IncomeImportBatch $r) => match (true) {
                        $r->isRolledBack() => 'gray',
                        $r->failed > 0 || filled($r->warnings) => 'warning',
                        default => 'success',
                    })
                    ->tooltip(fn (IncomeImportBatch $r) => collect($r->warnings ?? [])->merge($r->errors ?? [])->implode("\n") ?: null),
                TextColumn::make('importedBy.first_name')->label(__('By'))
                    ->formatStateUsing(fn (IncomeImportBatch $r) => $r->importedBy?->first_name ?? __('Automatic'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('customer_id')->label(__('Customer'))->options(fn () => $this->customerOptions())->searchable(),
                SelectFilter::make('channel')->label(__('How'))->options(['upload' => __('Upload'), 'inbox' => __('Inbox'), 'cli' => __('Command line')]),
            ])
            ->actions([
                TableAction::make('download')
                    ->label(__('Download file'))
                    ->tooltip(__('Download file'))
                    ->iconButton()
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (IncomeImportBatch $r) => filled($r->stored_path) && Storage::disk('local')->exists($r->stored_path))
                    ->action(fn (IncomeImportBatch $r) => Storage::disk('local')->download($r->stored_path, $r->original_filename)),
                TableAction::make('undo')
                    ->label(__('Undo'))
                    ->tooltip(__('Undo'))
                    ->iconButton()
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (IncomeImportBatch $r) => ! $r->isRolledBack() && $r->imported > 0)
                    ->requiresConfirmation()
                    ->modalHeading(__('Undo this import?'))
                    ->modalDescription(fn (IncomeImportBatch $r) => __('Removes the :n rows this file added for :customer and recalculates the affected years. Payments already recorded are not changed.', ['n' => $r->imported, 'customer' => $r->customer?->company ?: $r->customer?->name]))
                    ->action(function (IncomeImportBatch $r) {
                        app(IncomeDataImportService::class)->rollback($r);
                        Notification::make()->success()->title(__('Import undone'))->send();
                    }),
            ])
            ->emptyStateHeading(__('Nothing imported yet'))
            ->emptyStateDescription(__('Imports from this page, the inbox folder and the command line appear here.'))
            ->emptyStateIcon('heroicon-o-inbox');
    }

    private function analyze(string $path, string $name, mixed $customerId): array
    {
        abort_unless($this->isUpload($path), 403);

        $customer = $customerId ? Customer::find($customerId) : null;
        $result = app(IncomeDataImportService::class)->analyze(Storage::disk('local')->path($path), $name, $customer);

        return ['path' => $path] + $result;
    }

    /** Previews are client-side state: only ever touch files in the upload folder. */
    private function isUpload(string $path): bool
    {
        return str_starts_with($path, self::UPLOAD_DIR.'/') && ! str_contains($path, '..');
    }

    private function importable(array $p): bool
    {
        return $p['error'] === null && $p['customer_id'] !== null && $p['new'] > 0;
    }

    /** Delete uploaded files that didn't become an import (kept ones are the audit copy). */
    private function discardUnimported(array $keep): void
    {
        foreach ($this->previews as $p) {
            if (! in_array($p['path'], $keep, true) && $this->isUpload($p['path'])) {
                Storage::disk('local')->delete($p['path']);
            }
        }
    }
}
