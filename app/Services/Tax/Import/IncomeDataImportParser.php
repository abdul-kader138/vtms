<?php

namespace App\Services\Tax\Import;

use App\Models\Customer;
use Illuminate\Support\Collection;

interface IncomeDataImportParser
{
    /**
     * @return Collection<int, array{
     *     source: \App\Enums\IncomeSource,
     *     transaction_date: \Carbon\Carbon,
     *     sales_amount: float,
     *     taxable_sales: float,
     *     sale_taxes: float,
     *     reference_no: ?string,
     *     external_id: ?string,
     *     meta: array<string, mixed>,
     * }>
     */
    public function parse(string $filePath, Customer $customer): Collection;
}
