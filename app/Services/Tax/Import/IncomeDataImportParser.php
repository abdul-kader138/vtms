<?php

namespace App\Services\Tax\Import;

interface IncomeDataImportParser
{
    /**
     * Each row in the result:
     *   source: \App\Enums\IncomeSource, document_type: ?string (TD code),
     *   transaction_date: \Carbon\Carbon, sales_amount: float (negative for
     *   credit notes), taxable_sales: float, sale_taxes: float,
     *   reference_no: ?string, external_id: ?string, fingerprint: ?string,
     *   meta: array<string, mixed>.
     */
    public function parse(string $filePath, ?string $originalName = null): ParsedFile;
}
