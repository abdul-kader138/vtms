<?php

namespace App\Console\Commands;

use App\Enums\IncomeSource;
use App\Models\Customer;
use App\Services\Tax\Import\IncomeDataImportService;
use App\Services\Tax\Import\PrivatiDeviceImportParser;
use App\Services\Tax\Import\SdiFatturaImportParser;
use Illuminate\Console\Command;

class TaxImportIncomeData extends Command
{
    protected $signature = 'tax:import {customer} {source} {path}';

    protected $description = 'Import a customer income-data ledger file (sdi_fattura or privati_device format)';

    public function handle(): int
    {
        $customer = Customer::findOrFail($this->argument('customer'));
        $source = IncomeSource::from($this->argument('source'));
        $path = $this->argument('path');

        if (! is_readable($path)) {
            $this->error("File not readable: {$path}");

            return self::FAILURE;
        }

        $parser = match ($source) {
            IncomeSource::SdiFattura => app(SdiFatturaImportParser::class),
            IncomeSource::PrivatiDevice => app(PrivatiDeviceImportParser::class),
        };

        $rows = $parser->parse($path, $customer);

        $result = app(IncomeDataImportService::class)->import($rows, $customer);

        $this->info("Imported: {$result['imported']}, Duplicates skipped: {$result['duplicates']}, Failed: {$result['failed']}");

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
