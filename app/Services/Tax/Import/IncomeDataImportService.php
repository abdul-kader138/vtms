<?php

namespace App\Services\Tax\Import;

use App\Enums\IncomeSource;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\IncomeImportBatch;
use App\Models\TaxCalculation;
use App\Services\Tax\Import\Support\IncomeRow;
use App\Services\Tax\Import\Support\TabularFileReader;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Support\Tax\InvoiceDocumentType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Income import pipeline, shared by the upload screen, the customer
 * dashboard, the drop-folder (tax:import-inbox) and the CLI:
 *
 *   detect format → parse → match customer → dry-run preview → commit as
 *   one batch → recalculate the affected years → (undo the batch).
 *
 * Compared with VTMS (Transfers controller):
 *  - the format is detected from the file, not chosen by hand;
 *  - the customer is found from the seller VAT number / cash-register
 *    serial, and a file that belongs to someone else is refused;
 *  - duplicates are skipped row by row (VTMS rejected the whole file on the
 *    first one) and detected across formats via the fingerprint;
 *  - purchase invoices (customer is the buyer) are not counted as revenue;
 *  - years already calculated are recalculated automatically;
 *  - every import is recorded and can be undone as a unit.
 */
class IncomeDataImportService
{
    public function __construct(private readonly TaxCalculationOrchestrator $orchestrator) {}

    public function parserFor(string $path, string $originalName): IncomeDataImportParser
    {
        if (FatturaPaXmlImportParser::handles($originalName)) {
            return app(FatturaPaXmlImportParser::class);
        }

        if (! preg_match('/\.(csv|txt|xls|xlsx|ods)$/i', $originalName)) {
            throw new InvalidArgumentException(__('Unsupported file type. Upload a CSV/Excel export, a FatturaPA XML/P7M, or a ZIP of them.'));
        }

        $header = TabularFileReader::rows($path, $originalName)[0] ?? [];

        return match (true) {
            SdiFatturaImportParser::matchesHeader($header) => app(SdiFatturaImportParser::class),
            PrivatiDeviceImportParser::matchesHeader($header) => app(PrivatiDeviceImportParser::class),
            default => throw new InvalidArgumentException(__('Unrecognised file: expected the invoices list or the cash register export from "Fatture e Corrispettivi".')),
        };
    }

    /**
     * Dry run: what an import of this file would do. Never writes.
     *
     * @return array{file: string, hash: string, format: ?string, format_label: ?string, customer_id: ?int, match: ?string,
     *     rows: int, new: int, duplicates: int, purchases: int, skipped: int, credit_notes: int, total: float,
     *     period_from: ?string, period_to: ?string, years: list<int>, warnings: list<string>, error: ?string}
     */
    public function analyze(string $path, string $originalName, ?Customer $customer = null): array
    {
        $result = [
            'file' => $originalName, 'hash' => (string) hash_file('sha256', $path), 'format' => null, 'format_label' => null,
            'customer_id' => $customer?->id, 'match' => $customer ? 'selected' : null,
            'rows' => 0, 'new' => 0, 'duplicates' => 0, 'purchases' => 0, 'skipped' => 0, 'credit_notes' => 0, 'total' => 0.0,
            'period_from' => null, 'period_to' => null, 'years' => [], 'warnings' => [], 'error' => null,
        ];

        try {
            $parsed = $this->parserFor($path, $originalName)->parse($path, $originalName);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()] + $result;
        }

        $result['format'] = $parsed->format;
        $result['format_label'] = $parsed->label();
        $result['rows'] = $parsed->rows->count();
        $result['skipped'] = $parsed->skipped;
        $result['warnings'] = $parsed->warnings;

        $matched = $this->matchCustomer($parsed);
        if ($customer === null) {
            $customer = $matched['customer'];
            $result['customer_id'] = $customer?->id;
            $result['match'] = $matched['how'];
            if ($customer === null) {
                $result['error'] = $matched['problem'] ?? __('Could not tell which customer this file belongs to — choose one.');
            }
        } elseif ($problem = $this->ownershipProblem($parsed, $customer, $matched['customer'])) {
            $result['error'] = $problem;
        }

        if ($customer === null || $result['error'] !== null) {
            return $result;
        }

        $plan = $this->plan($parsed->rows, $customer);
        $new = $plan['new'];

        $result['new'] = $new->count();
        $result['duplicates'] = $plan['duplicates'];
        $result['purchases'] = $plan['purchases'] + $plan['foreign'];
        $result['credit_notes'] = $new->filter(fn ($r) => InvoiceDocumentType::isCreditNote($r['document_type']))->count();
        $result['total'] = round((float) $new->sum('sales_amount'), 2);
        $result['period_from'] = $new->min('transaction_date')?->toDateString();
        $result['period_to'] = $new->max('transaction_date')?->toDateString();
        $result['years'] = $new->map(fn ($r) => $r['transaction_date']->year)->unique()->sort()->values()->all();

        if ($plan['purchases'] > 0) {
            $result['warnings'][] = __(':n purchase invoices (the customer is the buyer) are not revenue and will be skipped.', ['n' => $plan['purchases']]);
        }
        if ($plan['foreign'] > 0) {
            $result['warnings'][] = __(':n invoices were issued by another VAT number and will be skipped.', ['n' => $plan['foreign']]);
        }
        if ($future = $new->filter(fn ($r) => $r['transaction_date']->isFuture())->count()) {
            $result['warnings'][] = __(':n rows are dated in the future.', ['n' => $future]);
        }
        if ($previous = $this->previousImportOf($result['hash'], $customer)) {
            $result['warnings'][] = __('This exact file was already imported on :date.', ['date' => $previous->created_at->format('d/m/Y H:i')]);
        }
        if ($result['new'] > 0) {
            [$recalc, $manual] = $this->recalculationPlan($customer, $result['years']);
            if ($recalc !== []) {
                $result['warnings'][] = __('Already-calculated years will be recalculated: :years.', ['years' => implode(', ', $recalc)]);
            }
            if ($manual !== []) {
                $result['warnings'][] = __('Year :years was entered by hand and will not be recalculated automatically.', ['years' => implode(', ', $manual)]);
            }
        }

        return $result;
    }

    /** Import one file as a batch, then recalculate affected calculated years. */
    public function commit(string $path, string $originalName, Customer $customer, string $channel = IncomeImportBatch::CHANNEL_UPLOAD, ?int $userId = null, ?string $storedPath = null): IncomeImportBatch
    {
        $parsed = $this->parserFor($path, $originalName)->parse($path, $originalName);

        if ($problem = $this->ownershipProblem($parsed, $customer, $this->matchCustomer($parsed)['customer'])) {
            throw new InvalidArgumentException($problem);
        }

        $plan = $this->plan($parsed->rows, $customer);
        // Decided before inserting: "has income" must reflect the ledger as it was.
        [$recalcYears, $manualYears] = $this->recalculationPlan($customer, $plan['new']->map(fn ($r) => $r['transaction_date']->year)->unique()->all());
        $failed = 0;
        $errors = [];
        $imported = collect();

        $batch = DB::transaction(function () use ($parsed, $plan, $customer, $channel, $userId, $storedPath, $path, $originalName, &$failed, &$errors, $imported) {
            $batch = IncomeImportBatch::create([
                'customer_id' => $customer->id,
                'format' => $parsed->format,
                'channel' => $channel,
                'original_filename' => mb_substr($originalName, 0, 255),
                'file_hash' => hash_file('sha256', $path),
                'stored_path' => $storedPath,
                'rows_read' => $parsed->rows->count(),
                'imported_by' => $userId,
                'warnings' => $parsed->warnings ?: null,
            ]);

            foreach ($plan['new'] as $i => $row) {
                try {
                    // Savepoint per row: one bad row doesn't sink the file.
                    DB::transaction(fn () => IncomeData::create([
                        'customer_id' => $customer->id,
                        'source' => $row['source'],
                        'document_type' => $row['document_type'],
                        'transaction_date' => $row['transaction_date'],
                        'sales_amount' => $row['sales_amount'],
                        'taxable_sales' => $row['taxable_sales'],
                        'sale_taxes' => $row['sale_taxes'],
                        'reference_no' => $row['reference_no'],
                        'external_id' => $row['external_id'],
                        'fingerprint' => $row['fingerprint'],
                        'meta' => $row['meta'],
                        'imported_by' => $userId,
                        'import_batch_id' => $batch->id,
                        'tax_year' => $row['transaction_date']->year,
                    ]));
                    $imported->push($row);
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = __('Row :n (:ref): :error', ['n' => $i + 1, 'ref' => $row['reference_no'] ?? $row['external_id'] ?? '—', 'error' => $e->getMessage()]);
                }
            }

            $batch->update([
                'imported' => $imported->count(),
                'duplicates' => $plan['duplicates'],
                'skipped' => $parsed->skipped + $plan['purchases'] + $plan['foreign'],
                'failed' => $failed,
                'credit_notes' => $imported->filter(fn ($r) => InvoiceDocumentType::isCreditNote($r['document_type']))->count(),
                'total_amount' => round((float) $imported->sum('sales_amount'), 2),
                'period_from' => $imported->min('transaction_date'),
                'period_to' => $imported->max('transaction_date'),
                'errors' => $errors ?: null,
            ]);

            return $batch;
        });

        if ($manualYears !== []) {
            $batch->update(['warnings' => array_merge($batch->warnings ?? [], [__('Year :years was entered by hand and was not recalculated — recalculate it from the tax dashboard once its income is complete.', ['years' => implode(', ', $manualYears)])])]);
        }
        $this->recalculate($batch, $customer, array_intersect($recalcYears, $imported->map(fn ($r) => $r['transaction_date']->year)->unique()->all()));

        return $batch->fresh();
    }

    /** Remove every row a batch added and recalculate the years it touched. */
    public function rollback(IncomeImportBatch $batch): void
    {
        if ($batch->isRolledBack()) {
            return;
        }

        DB::transaction(function () use ($batch) {
            $batch->rows()->delete();
            $batch->update(['rolled_back_at' => now()]);
        });

        // Only the years this import recalculated: a hand-entered year it
        // didn't touch must stay as typed.
        $this->recalculate($batch, $batch->customer, $batch->recalculated_years ?? []);
    }

    /**
     * Credit notes imported before credit notes were handled (by VTMS or an
     * earlier version of this importer) are stored as positive revenue.
     * Flip them and recalculate.
     *
     * @return Collection<int, IncomeData> the rows that were (or, dry run, would be) fixed
     */
    public function fixLegacyCreditNotes(?Customer $customer = null, bool $dryRun = false): Collection
    {
        $rows = IncomeData::query()
            ->with('customer')
            ->whereIn('document_type', InvoiceDocumentType::CREDIT_NOTES)
            ->where('sales_amount', '>', 0)
            ->when($customer, fn ($q) => $q->where('customer_id', $customer->id))
            ->get();

        if ($dryRun || $rows->isEmpty()) {
            return $rows;
        }

        DB::transaction(fn () => $rows->each(fn (IncomeData $r) => $r->update([
            'sales_amount' => -abs((float) $r->sales_amount),
            'taxable_sales' => -abs((float) $r->taxable_sales),
            'sale_taxes' => -abs((float) $r->sale_taxes),
        ])));

        foreach ($rows->groupBy('customer_id') as $customerRows) {
            $customer = $customerRows->first()->customer;
            $this->recalculate(null, $customer, $this->recalculationPlan($customer, $customerRows->pluck('tax_year')->unique()->all())[0]);
        }

        return $rows;
    }

    /**
     * Who the file belongs to, from its seller VAT numbers / cash-register
     * serials (serials are learnt from earlier imports).
     *
     * @return array{customer: ?Customer, how: ?string, problem: ?string}
     */
    public function matchCustomer(ParsedFile $parsed): array
    {
        if ($parsed->sellerVats !== []) {
            $owners = Customer::whereNotNull('vat_no')->get(['id', 'name', 'company', 'vat_no'])
                ->filter(fn (Customer $c) => in_array(IncomeRow::vat($c->vat_no), $parsed->sellerVats, true))
                ->values();

            if ($owners->count() === 1) {
                return ['customer' => Customer::find($owners->first()->id), 'how' => 'vat', 'problem' => null];
            }
            if ($owners->count() > 1) {
                return ['customer' => null, 'how' => null, 'problem' => __('This file holds invoices of several customers (:names). Import one customer per file.', ['names' => $owners->map(fn ($c) => $c->company ?: $c->name)->join(', ')])];
            }

            return ['customer' => null, 'how' => null, 'problem' => __('No customer has VAT number :vat — add it to the customer, or choose the customer.', ['vat' => implode(', ', $parsed->sellerVats)])];
        }

        if ($parsed->deviceSerials !== []) {
            $ids = IncomeData::where('source', IncomeSource::PrivatiDevice)
                ->where(fn ($q) => collect($parsed->deviceSerials)->each(fn ($s) => $q->orWhere('meta->matricola_dispositivo', $s)))
                ->distinct()
                ->pluck('customer_id');

            if ($ids->count() === 1) {
                return ['customer' => Customer::find($ids->first()), 'how' => 'device', 'problem' => null];
            }
            if ($ids->count() > 1) {
                return ['customer' => null, 'how' => null, 'problem' => __('Cash register :serial was used by more than one customer — choose the customer.', ['serial' => implode(', ', $parsed->deviceSerials)])];
            }

            return ['customer' => null, 'how' => null, 'problem' => __('Cash register :serial has not been imported before — choose the customer once; later files are matched automatically.', ['serial' => implode(', ', $parsed->deviceSerials)])];
        }

        return ['customer' => null, 'how' => null, 'problem' => null];
    }

    /** Why this file must not go into this customer's ledger, if it mustn't. */
    private function ownershipProblem(ParsedFile $parsed, Customer $customer, ?Customer $owner): ?string
    {
        if ($owner !== null && $owner->id !== $customer->id) {
            return __('This file belongs to :owner, not :customer.', ['owner' => $owner->company ?: $owner->name, 'customer' => $customer->company ?: $customer->name]);
        }

        $vat = IncomeRow::vat($customer->vat_no);
        if ($vat !== '' && $parsed->rows->isNotEmpty()
            && $parsed->rows->every(fn ($r) => ($r['meta']['partita_iva_cliente'] ?? '') === $vat && ($r['meta']['partita_iva_fornitore'] ?? '') !== $vat)) {
            return __('These are invoices received by :customer (purchases), not sales.', ['customer' => $customer->company ?: $customer->name]);
        }

        return null;
    }

    /**
     * Split parsed rows into new rows, duplicates (already stored, or
     * repeated in the file) and purchases (customer is the buyer).
     *
     * @return array{new: Collection, duplicates: int, purchases: int, foreign: int}
     */
    private function plan(Collection $rows, Customer $customer): array
    {
        $vat = IncomeRow::vat($customer->vat_no);
        $purchases = 0;
        $foreign = 0;

        // Only invoices this customer issued are its revenue.
        $rows = $rows->reject(function ($r) use ($vat, &$purchases, &$foreign) {
            $seller = $r['meta']['partita_iva_fornitore'] ?? '';
            if ($vat === '' || $seller === '' || $seller === $vat) {
                return false;
            }
            ($r['meta']['partita_iva_cliente'] ?? '') === $vat ? $purchases++ : $foreign++;

            return true;
        });

        $existingFingerprints = collect();
        foreach ($rows->pluck('fingerprint')->filter()->unique()->chunk(500) as $chunk) {
            $existingFingerprints = $existingFingerprints->merge(
                IncomeData::where('customer_id', $customer->id)->whereIn('fingerprint', $chunk->values())->pluck('fingerprint')
            );
        }
        $existingIds = collect();
        foreach ($rows->filter(fn ($r) => $r['external_id'] !== null)->groupBy(fn ($r) => $r['source']->value) as $source => $group) {
            foreach ($group->pluck('external_id')->unique()->chunk(500) as $chunk) {
                $existingIds = $existingIds->merge(
                    IncomeData::where('source', $source)->whereIn('external_id', $chunk->values())->pluck('external_id')->map(fn ($id) => $source.'|'.$id)
                );
            }
        }
        $existingFingerprints = $existingFingerprints->flip();
        $existingIds = $existingIds->flip();

        $seen = [];
        $new = collect();
        $duplicates = 0;

        foreach ($rows as $r) {
            $idKey = $r['external_id'] !== null ? $r['source']->value.'|'.$r['external_id'] : null;
            $keys = array_filter([$r['fingerprint'] ? 'f:'.$r['fingerprint'] : null, $idKey ? 'e:'.$idKey : null]);

            $isDuplicate = ($r['fingerprint'] && $existingFingerprints->has($r['fingerprint']))
                || ($idKey && $existingIds->has($idKey))
                || array_intersect_key($seen, array_flip($keys)) !== [];

            if ($isDuplicate) {
                $duplicates++;

                continue;
            }

            foreach ($keys as $k) {
                $seen[$k] = true;
            }
            $new->push($r);
        }

        return ['new' => $new, 'duplicates' => $duplicates, 'purchases' => $purchases, 'foreign' => $foreign];
    }

    private function previousImportOf(string $hash, Customer $customer): ?IncomeImportBatch
    {
        return IncomeImportBatch::where('customer_id', $customer->id)->where('file_hash', $hash)
            ->whereNull('rolled_back_at')->latest()->first();
    }

    /**
     * Of the given years, which already-calculated ones to recalculate, and
     * which to leave alone because they were entered by hand (a calculation
     * exists but the ledger has no income for that year — recomputing from
     * a partial import would overwrite the typed-in figures).
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private function recalculationPlan(Customer $customer, array $years): array
    {
        $calculated = TaxCalculation::where('customer_id', $customer->id)->whereIn('tax_year', $years)
            ->orderBy('tax_year')->pluck('tax_year')->map(fn ($y) => (int) $y)->all();
        $withIncome = IncomeData::where('customer_id', $customer->id)->whereIn('tax_year', $calculated)
            ->distinct()->pluck('tax_year')->map(fn ($y) => (int) $y)->all();

        return [array_values(array_intersect($calculated, $withIncome)), array_values(array_diff($calculated, $withIncome))];
    }

    /** Re-run the calculation for each given year. */
    private function recalculate(?IncomeImportBatch $batch, Customer $customer, array $years): void
    {
        $done = [];
        $warnings = [];
        sort($years);
        foreach ($years as $year) {
            try {
                $this->orchestrator->runForCustomerAndYear($customer->fresh(), $year);
                $done[] = $year;
            } catch (Throwable $e) {
                $warnings[] = __('Recalculating :year failed: :error', ['year' => $year, 'error' => $e->getMessage()]);
            }
        }

        $batch?->update([
            'recalculated_years' => array_values(array_unique(array_merge($batch->recalculated_years ?? [], $done))) ?: null,
            'warnings' => array_merge($batch->warnings ?? [], $warnings) ?: null,
        ]);
    }
}
