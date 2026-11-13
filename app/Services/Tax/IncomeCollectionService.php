<?php

namespace App\Services\Tax;

use App\Models\IncomeData;
use App\Models\TaxCalculation;
use App\Support\Tax\TaxRules;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records when invoices were actually paid (or that they weren't yet) and,
 * under the cash-basis law rules, recalculates every year that moves.
 */
class IncomeCollectionService
{
    public function __construct(private readonly TaxCalculationOrchestrator $orchestrator) {}

    /**
     * @param  Collection<int, IncomeData>  $rows
     * @return array{updated: int, recalculated: list<string>, failed: list<string>}
     */
    public function setCollectedOn(Collection $rows, ?CarbonInterface $date): array
    {
        // customer_id => years whose revenue changes (old and new).
        $touched = [];

        DB::transaction(function () use ($rows, $date, &$touched) {
            foreach ($rows as $row) {
                $before = $row->income_year;
                $row->update(['collected_on' => $date?->toDateString()]);

                foreach ([$before, $row->income_year] as $year) {
                    if ($year !== null) {
                        $touched[$row->customer_id][(int) $year] = true;
                    }
                }
            }
        });

        $recalculated = [];
        $failed = [];

        // Legacy rules count the document year, which didn't change.
        if (TaxRules::isLaw()) {
            foreach ($touched as $customerId => $years) {
                $calculated = TaxCalculation::where('customer_id', $customerId)->whereIn('tax_year', array_keys($years))
                    ->with('customer')->orderBy('tax_year')->get();

                foreach ($calculated as $calculation) {
                    try {
                        $this->orchestrator->runForCustomerAndYear($calculation->customer, $calculation->tax_year);
                        $recalculated[] = ($calculation->customer->company ?: $calculation->customer->name).' '.$calculation->tax_year;
                    } catch (Throwable $e) {
                        $failed[] = $calculation->tax_year.': '.$e->getMessage();
                    }
                }
            }
        }

        return ['updated' => $rows->count(), 'recalculated' => $recalculated, 'failed' => $failed];
    }
}
