<?php

namespace App\Services\Tax\Import;

use App\Models\Customer;
use App\Models\IncomeData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class IncomeDataImportService
{
    /**
     * @return array{imported: int, duplicates: int, failed: int, errors: array<int, string>}
     */
    public function import(Collection $rows, Customer $customer, ?int $importedByUserId = null): array
    {
        $imported = 0;
        $duplicates = 0;
        $failed = 0;
        $errors = [];

        DB::transaction(function () use ($rows, $customer, $importedByUserId, &$imported, &$duplicates, &$failed, &$errors) {
            foreach ($rows as $index => $row) {
                try {
                    $externalId = $row['external_id'] ?? null;

                    if ($externalId !== null) {
                        $exists = IncomeData::query()
                            ->where('source', $row['source'])
                            ->where('external_id', $externalId)
                            ->exists();

                        if ($exists) {
                            $duplicates++;

                            continue;
                        }
                    }

                    IncomeData::create([
                        'customer_id' => $customer->id,
                        'source' => $row['source'],
                        'transaction_date' => $row['transaction_date'],
                        'sales_amount' => $row['sales_amount'],
                        'taxable_sales' => $row['taxable_sales'],
                        'sale_taxes' => $row['sale_taxes'],
                        'reference_no' => $row['reference_no'] ?? null,
                        'external_id' => $externalId,
                        'meta' => $row['meta'] ?? [],
                        'imported_by' => $importedByUserId,
                        'tax_year' => $row['transaction_date']->year,
                    ]);

                    $imported++;
                } catch (Throwable $e) {
                    // A single malformed row shouldn't sink an otherwise-good import batch —
                    // collect the error and keep processing, so an admin re-uploading a mostly
                    // clean file doesn't lose everything over one bad row.
                    $failed++;
                    $errors[] = "Row {$index}: {$e->getMessage()}";
                }
            }
        });

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }
}
