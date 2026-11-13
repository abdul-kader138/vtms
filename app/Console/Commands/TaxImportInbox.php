<?php

namespace App\Console\Commands;

use App\Filament\Pages\ImportIncome;
use App\Models\Customer;
use App\Models\IncomeImportBatch;
use App\Models\User;
use App\Services\Tax\Import\IncomeDataImportService;
use App\Services\Tax\Import\Support\IncomeRow;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hands-free import: anything dropped into the inbox folder (by hand, by a
 * synced cloud folder, SFTP, an e-mail-to-folder rule, or an e-invoicing
 * provider's export) is imported on the next run.
 *
 *   storage/app/private/income-imports/inbox/            → customer found from the file
 *   storage/app/private/income-imports/inbox/{id|VAT}/   → that customer
 *
 * Imported files move to processed/{Y}/{m}/; files that can't be imported
 * move to failed/ with a .txt next to them saying why. Staff get one
 * notification per run.
 */
class TaxImportInbox extends Command
{
    public const ROOT = 'income-imports';

    protected $signature = 'tax:import-inbox {--dry-run : Show what would be imported without importing or moving anything}';

    protected $description = 'Import income files dropped into the income-imports/inbox folder';

    public function handle(IncomeDataImportService $importer): int
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::ROOT.'/inbox');

        if (! $this->option('dry-run')) {
            $this->pruneAbandonedUploads();
        }

        $files = collect($disk->allFiles(self::ROOT.'/inbox'))
            ->reject(fn ($f) => str_starts_with(basename($f), '.'))
            ->values();

        if ($files->isEmpty()) {
            $this->info('Inbox is empty.');

            return self::SUCCESS;
        }

        $imported = [];
        $failed = [];

        foreach ($files as $file) {
            $name = basename($file);
            $path = $disk->path($file);
            $folder = trim(Str::after(dirname($file), self::ROOT.'/inbox'), '/');

            try {
                $customer = $folder !== '' ? $this->customerForFolder($folder) : null;
                if ($folder !== '' && $customer === null) {
                    throw new \RuntimeException(__('No customer with id or VAT number ":folder".', ['folder' => $folder]));
                }

                $analysis = $importer->analyze($path, $name, $customer);
                $this->line(sprintf('%s → %s: %s', $name, $analysis['customer_id'] ? (Customer::find($analysis['customer_id'])?->name ?? '?') : '—', $analysis['error'] ?? "{$analysis['new']} new, {$analysis['duplicates']} duplicates"));

                if ($analysis['error'] !== null) {
                    throw new \RuntimeException($analysis['error']);
                }
                if ($this->option('dry-run')) {
                    continue;
                }

                $customer ??= Customer::findOrFail($analysis['customer_id']);
                $target = sprintf('%s/processed/%s/%s', self::ROOT, now()->format('Y/m'), $this->uniqueName($name));
                $batch = $importer->commit($path, $name, $customer, IncomeImportBatch::CHANNEL_INBOX, storedPath: $target);
                $disk->move($file, $target);
                $imported[] = $batch;
            } catch (Throwable $e) {
                $this->error("{$name}: {$e->getMessage()}");
                if ($this->option('dry-run')) {
                    continue;
                }
                $target = self::ROOT.'/failed/'.$this->uniqueName($name);
                $disk->move($file, $target);
                $disk->put($target.'.txt', now()->toDateTimeString()."\n".$e->getMessage()."\n");
                $failed[] = ['file' => $name, 'error' => $e->getMessage()];
            }
        }

        if (! $this->option('dry-run')) {
            $this->notifyStaff($imported, $failed);
        }

        $this->info(sprintf('Imported %d file(s), %d failed.', count($imported), count($failed)));

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Uploads checked on the import page but never imported (the browser was
     * closed before "Import" / "Start over") — removed after a day. Files
     * that became an import stay as its audit copy.
     */
    private function pruneAbandonedUploads(): void
    {
        $disk = Storage::disk('local');
        $kept = IncomeImportBatch::whereNotNull('stored_path')->pluck('stored_path')->flip();

        foreach ($disk->files(ImportIncome::UPLOAD_DIR) as $file) {
            if (! $kept->has($file) && $disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }

    private function customerForFolder(string $folder): ?Customer
    {
        if (ctype_digit($folder) && strlen($folder) < 11 && ($c = Customer::find((int) $folder))) {
            return $c;
        }

        $vat = IncomeRow::vat($folder);

        return Customer::whereNotNull('vat_no')->get()->first(fn (Customer $c) => IncomeRow::vat($c->vat_no) === $vat);
    }

    private function uniqueName(string $name): string
    {
        return now()->format('Ymd-His').'-'.Str::random(4).'-'.$name;
    }

    /** @param list<IncomeImportBatch> $imported */
    private function notifyStaff(array $imported, array $failed): void
    {
        if ($imported === [] && $failed === []) {
            return;
        }

        $staff = User::role('super_admin')->get();
        if ($staff->isEmpty()) {
            return;
        }

        $rows = collect($imported)->sum('imported');
        $lines = collect($imported)->map(fn (IncomeImportBatch $b) => "{$b->original_filename}: ".__(':n new rows', ['n' => $b->imported]))
            ->merge(collect($failed)->map(fn ($f) => "✗ {$f['file']}: {$f['error']}"))
            ->take(8)
            ->implode("\n");

        Notification::make()
            ->title($failed === []
                ? __('Income inbox: :files files imported (:rows rows)', ['files' => count($imported), 'rows' => $rows])
                : __('Income inbox: :ok imported, :failed failed', ['ok' => count($imported), 'failed' => count($failed)]))
            ->body($lines)
            ->icon($failed === [] ? 'heroicon-o-inbox-arrow-down' : 'heroicon-o-exclamation-triangle')
            ->iconColor($failed === [] ? 'success' : 'danger')
            ->actions([
                NotificationAction::make('view')->label(__('Open imports'))->url(ImportIncome::getUrl(panel: 'admin')),
            ])
            ->sendToDatabase($staff);
    }
}
