<?php

namespace App\Services\Tax\Import;

use Illuminate\Support\Collection;

/**
 * What a parser read from one file: normalized income rows plus the
 * identifiers that say whose file it is (seller VAT numbers, cash-register
 * serials) so it can be matched to a customer without asking.
 */
final class ParsedFile
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows  see IncomeDataImportParser
     * @param  list<string>  $sellerVats  normalized (IncomeRow::vat)
     * @param  list<string>  $deviceSerials
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $format,
        public readonly Collection $rows,
        public readonly array $sellerVats = [],
        public readonly array $deviceSerials = [],
        public readonly int $skipped = 0,
        public readonly array $warnings = [],
    ) {}

    public function label(): string
    {
        return match ($this->format) {
            'sdi_csv' => __('Invoices list (Fatture e Corrispettivi)'),
            'device_csv' => __('Cash register receipts (corrispettivi)'),
            'fatturapa_xml' => __('E-invoice XML (FatturaPA)'),
            default => $this->format,
        };
    }
}
