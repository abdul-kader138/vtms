<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Tax\Import\IncomeDataImportService;
use Illuminate\Console\Command;

/**
 * VTMS (and this app before credit notes were handled) stored credit notes
 * ("Nota di credito") as positive revenue. This flips them to negative and
 * recalculates the affected years.
 */
class TaxFixCreditNotes extends Command
{
    protected $signature = 'tax:fix-credit-notes {--customer= : Only this customer id} {--dry-run : List them without changing anything}';

    protected $description = 'Turn credit notes stored as positive revenue into negative amounts and recalculate';

    public function handle(IncomeDataImportService $importer): int
    {
        $customer = $this->option('customer') ? Customer::findOrFail($this->option('customer')) : null;
        $rows = $importer->fixLegacyCreditNotes($customer, dryRun: (bool) $this->option('dry-run'));

        if ($rows->isEmpty()) {
            $this->info('No positive credit notes found.');

            return self::SUCCESS;
        }

        $this->table(['Customer', 'Date', 'Number', 'Amount'], $rows->map(fn ($r) => [
            $r->customer?->name, $r->transaction_date->format('d/m/Y'), $r->reference_no, number_format(abs((float) $r->sales_amount), 2),
        ]));

        $this->info($this->option('dry-run')
            ? "{$rows->count()} credit notes would be corrected. Run without --dry-run to fix them."
            : "{$rows->count()} credit notes corrected; calculated years were recalculated.");

        return self::SUCCESS;
    }
}
