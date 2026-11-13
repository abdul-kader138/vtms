<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\IncomeImportBatch;
use App\Services\Tax\Import\IncomeDataImportService;
use Illuminate\Console\Command;

class TaxImportIncomeData extends Command
{
    protected $signature = 'tax:import {path* : One or more files (CSV/XLS/XLSX list exports, FatturaPA .xml/.p7m, or .zip)}
                            {--customer= : Customer id; omit to match by VAT number / cash register serial}
                            {--dry-run : Only show what would be imported}';

    protected $description = 'Import income files (format and customer are detected automatically)';

    public function handle(IncomeDataImportService $importer): int
    {
        $forced = $this->option('customer') ? Customer::findOrFail($this->option('customer')) : null;
        $status = self::SUCCESS;

        foreach ($this->argument('path') as $path) {
            if (! is_readable($path)) {
                $this->error("File not readable: {$path}");
                $status = self::FAILURE;

                continue;
            }

            $analysis = $importer->analyze($path, basename($path), $forced);
            if ($analysis['error'] !== null) {
                $this->error(basename($path).': '.$analysis['error']);
                $status = self::FAILURE;

                continue;
            }

            $customer = $forced ?? Customer::findOrFail($analysis['customer_id']);
            $this->info(sprintf('%s [%s] → %s', basename($path), $analysis['format_label'], $customer->company ?: $customer->name));
            foreach ($analysis['warnings'] as $warning) {
                $this->warn('  '.$warning);
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf('  would import %d rows (€ %s), %d duplicates', $analysis['new'], number_format($analysis['total'], 2), $analysis['duplicates']));

                continue;
            }

            $batch = $importer->commit($path, basename($path), $customer, IncomeImportBatch::CHANNEL_CLI);
            $this->line(sprintf('  Imported: %d, duplicates skipped: %d, skipped: %d, failed: %d (batch #%d)', $batch->imported, $batch->duplicates, $batch->skipped, $batch->failed, $batch->id));
            foreach ($batch->errors ?? [] as $error) {
                $this->warn('  '.$error);
            }
        }

        return $status;
    }
}
