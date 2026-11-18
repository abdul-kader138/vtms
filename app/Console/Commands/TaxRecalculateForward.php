<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Support\Tax\TaxRules;
use Illuminate\Console\Command;

/**
 * Walks a customer's tax years forward from a given year, recalculating
 * each one that already has data — see
 * TaxCalculationOrchestrator::runForwardFromYear() for why this is safe to
 * chain (paid payments are never rewritten) and why one year's edit can
 * ripple past year + 1 (advances/excess-income installments scheduled
 * forward into the next year).
 */
class TaxRecalculateForward extends Command
{
    protected $signature = 'tax:recalculate-forward {customer : Customer ID or VAT number (vat_no)} {year : Year to start from}';

    protected $description = "Recalculate a customer's tax year and every following year that already has data";

    public function handle(TaxCalculationOrchestrator $orchestrator): int
    {
        $identifier = $this->argument('customer');
        $customer = ctype_digit((string) $identifier)
            ? Customer::find((int) $identifier)
            : Customer::where('vat_no', $identifier)->first();

        if ($customer === null) {
            $this->error("No customer found for \"{$identifier}\".");

            return self::FAILURE;
        }

        $startYear = (int) $this->argument('year');

        // TaxRules is a single global toggle (Setting::get('tax_rules')),
        // not per customer — see TaxCalculationOrchestrator::ruleMismatch().
        // Recalculating under a different rule set than this year's figures
        // were actually computed under would silently overwrite them with
        // a different methodology, so refuse rather than guess.
        if ($mismatch = $orchestrator->ruleMismatch($customer, $startYear)) {
            $this->error("{$customer->name}, {$startYear}: last computed under \"{$mismatch}\" rules, but the app is currently set to \"".TaxRules::mode().'" (Admin → Tax Settings). Not recalculating — switch the setting first if that\'s really intended.');

            return self::FAILURE;
        }

        $results = $orchestrator->runForwardFromYear($customer, $startYear);

        if ($results === []) {
            $this->info("{$customer->name}: no calculation exists for {$startYear} or later — nothing to recalculate.");

            return self::SUCCESS;
        }

        $this->table(
            ['Year', 'Changed', 'Tax due', 'Advances made', 'Balance', 'INPS after discount'],
            collect($results)->map(fn ($r) => [
                $r['year'],
                $r['changed'] ? 'yes' : 'no',
                $this->diffCell($r['before']['tax_due'], $r['after']['tax_due']),
                $this->diffCell($r['before']['advance_payments_made'], $r['after']['advance_payments_made']),
                $this->diffCell($r['before']['balance_payment'], $r['after']['balance_payment']),
                $this->diffCell($r['before']['inps_amount_after_discount'], $r['after']['inps_amount_after_discount']),
            ])
        );

        $changedYears = collect($results)->where('changed', true)->pluck('year')->implode(', ');
        $this->info($changedYears === ''
            ? "{$customer->name}: recalculated ".count($results).' year(s), nothing actually changed.'
            : "{$customer->name}: recalculated ".count($results)." year(s), figures changed for: {$changedYears}.");

        return self::SUCCESS;
    }

    private function diffCell(?float $before, ?float $after): string
    {
        if ($before === $after) {
            return $after === null ? '—' : number_format($after, 2);
        }

        return number_format($before ?? 0, 2).' → '.number_format($after ?? 0, 2);
    }
}
