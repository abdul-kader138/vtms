<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Tax\TaxYearDeletionService;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use RuntimeException;

/**
 * Removes a customer's tax/INPS/INAIL/Fattura Tra Privati calculations and
 * the payments they scheduled for one year, so it can be recalculated from
 * scratch — a CLI wrapper around TaxYearDeletionService::delete(), which
 * already refuses (rather than silently cascading) when the year is locked
 * or any of its payments (including ones it scheduled into next year) have
 * been paid, cancelled, receipted or self-reported. See that class's
 * docblock for why a plain row delete would be unsafe here.
 */
class TaxDeleteYear extends Command
{
    use ConfirmableTrait;

    protected $signature = 'tax:delete-year {customer : Customer ID or VAT number (vat_no)} {year : Tax year to remove} {--force : Skip the confirmation prompt}';

    protected $description = "Delete a customer's tax year so it can be recalculated";

    public function handle(TaxYearDeletionService $service): int
    {
        $identifier = $this->argument('customer');
        $customer = ctype_digit((string) $identifier)
            ? Customer::find((int) $identifier)
            : Customer::where('vat_no', $identifier)->first();

        if ($customer === null) {
            $this->error("No customer found for \"{$identifier}\".");

            return self::FAILURE;
        }

        $year = (int) $this->argument('year');
        $preview = $service->preview($customer, $year);

        if (! $preview['has_data']) {
            $this->info("{$customer->name}, {$year}: nothing to delete.");

            return self::SUCCESS;
        }

        $this->table(['Row', 'Count'], [
            ['Tax calculation', $preview['counts']['tax_calculation']],
            ['INPS calculation', $preview['counts']['inps_calculation']],
            ['INAIL calculation', $preview['counts']['inail_calculation']],
            ['Fattura Tra Privati calculation', $preview['counts']['fattura_tra_privati_calculation']],
            ['Diritto Annuale payment', $preview['counts']['diritto_annuale_payment']],
            ['Payments removed with them', $preview['counts']['payments']],
        ]);

        if ($preview['locked']) {
            $this->error("{$customer->name}, {$year}: this year is locked. Unlock it first (CustomerTaxDashboard) if it genuinely needs to be removed.");

            return self::FAILURE;
        }

        if (! $preview['deletable']) {
            $this->error("{$customer->name}, {$year}: blocked — the following payments have already been touched:");
            $this->table(
                ['Type', 'ID', 'Year', 'Amount', 'Status'],
                collect($preview['blockers'])->map(fn ($b) => [$b['type'], $b['id'], $b['year'], number_format($b['amount'], 2), $b['status']])
            );
            $this->line('Unmark them as paid first if this year genuinely needs to be removed.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed("This permanently removes {$customer->name}'s {$year} tax data.")) {
            return self::FAILURE;
        }

        try {
            $service->delete($customer, $year);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$customer->name}, {$year}: deleted. Run `php artisan tax:recalculate-forward {$customer->id} {$year}` to recalculate it.");

        if ($service->staleFollowingYear($customer, $year)) {
            $this->warn(($year + 1).' has figures computed using data from '.$year.' — recalculate it too.');
        }

        return self::SUCCESS;
    }
}
