<?php

namespace App\Services\Tax\Import;

use App\Enums\IncomeSource;
use App\Services\Tax\Import\Support\ImportValueNormalizer as Norm;
use App\Services\Tax\Import\Support\IncomeRow;
use App\Services\Tax\Import\Support\TabularFileReader;
use App\Support\Tax\InvoiceDocumentType;
use InvalidArgumentException;

/**
 * The invoice list exported from Agenzia delle Entrate "Fatture e
 * Corrispettivi" (Tipo fattura;Tipo documento;…;Bollo virtuale), 17
 * semicolon-separated columns — the format VTMS imported in
 * Transfers::read_and_save_excel().
 *
 * Differences from VTMS (both bugs there):
 *  - credit notes ("Nota di credito", TD04/TD08) are stored NEGATIVE — the
 *    export lists them as positive amounts, and VTMS added them to revenue;
 *  - BOM, Windows-1252 and Excel-re-saved files are accepted.
 */
class SdiFatturaImportParser implements IncomeDataImportParser
{
    public const HEADER = [
        'Tipo fattura', 'Tipo documento', 'Numero fattura / Documento',
        'Data emissione', 'Data trasmissione', 'Codice fiscale fornitore',
        'Partita IVA fornitore', 'Denominazione fornitore',
        'Codice fiscale cliente', 'Partita IVA cliente', 'Denominazione cliente',
        'Imponibile/Importo (totale in euro)', 'Imposta (totale in euro)',
        'Sdi/file', 'Fatture consegnate', 'Data consegna/Presa visione', 'Bollo virtuale',
    ];

    /** Column 16 in the list of RECEIVED invoices (purchases) instead of "Data consegna/Presa visione". */
    private const RECEIVED_DATE = 'data ricezione';

    /** Matches the issued AND the received list; parse() refuses the latter. */
    public static function matchesHeader(array $header): bool
    {
        $actual = array_map([TabularFileReader::class, 'headerCell'], array_slice($header, 0, count(self::HEADER)));
        $expected = array_map('mb_strtolower', self::HEADER);

        if (($actual[15] ?? null) === self::RECEIVED_DATE) {
            $actual[15] = $expected[15];
        }

        return $actual === $expected;
    }

    public function parse(string $filePath, ?string $originalName = null): ParsedFile
    {
        $rows = TabularFileReader::rows($filePath, $originalName);
        $header = array_shift($rows) ?? [];

        if (! self::matchesHeader($header)) {
            throw new InvalidArgumentException(__('This is not an invoices list export: the columns do not match "Tipo fattura; Tipo documento; …; Bollo virtuale".'));
        }
        if (TabularFileReader::headerCell($header[15] ?? null) === self::RECEIVED_DATE) {
            throw new InvalidArgumentException(__('This is the list of received invoices (purchases). Only issued invoices are revenue — export "Fatture emesse" instead.'));
        }

        $skipped = 0;
        $parsed = collect();

        foreach ($rows as $row) {
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $date = Norm::dateOnly($row[3] ?? null);
            if ($date === null) {
                $skipped++;

                continue;
            }

            $type = InvoiceDocumentType::normalize($row[1] ?? null);
            $sign = InvoiceDocumentType::isCreditNote($type) ? -1 : 1;
            $taxable = $sign * abs(Norm::decimal($row[11] ?? null));
            $vat = $sign * abs(Norm::decimal($row[12] ?? null));
            $number = Norm::stripQuotes($row[2] ?? null);
            $sellerVat = IncomeRow::vat($row[6] ?? null);

            $parsed->push([
                'source' => IncomeSource::SdiFattura,
                'document_type' => $type,
                'transaction_date' => $date,
                // As in VTMS: sales and taxable sales both come from
                // "Imponibile/Importo" for this format.
                'sales_amount' => $taxable,
                'taxable_sales' => $taxable,
                'sale_taxes' => $vat,
                'reference_no' => $number ?: null,
                'external_id' => Norm::stripQuotes($row[13] ?? null) ?: null,
                'fingerprint' => IncomeRow::invoiceFingerprint($sellerVat, $sign < 0, $number, $date),
                'meta' => [
                    'format' => 'sdi_csv',
                    'tipo_fattura' => Norm::stripQuotes($row[0] ?? null),
                    'tipo_documento' => Norm::stripQuotes($row[1] ?? null),
                    'numero_fattura' => $number,
                    'data_trasmissione' => Norm::stripQuotes($row[4] ?? null),
                    'codice_fiscale_fornitore' => Norm::stripQuotes($row[5] ?? null),
                    'partita_iva_fornitore' => $sellerVat,
                    'denominazione_fornitore' => Norm::stripQuotes($row[7] ?? null),
                    'codice_fiscale_cliente' => Norm::stripQuotes($row[8] ?? null),
                    'partita_iva_cliente' => IncomeRow::vat($row[9] ?? null),
                    'denominazione_cliente' => Norm::stripQuotes($row[10] ?? null),
                    'sdi_file' => Norm::stripQuotes($row[13] ?? null),
                    'fatture_consegnate' => Norm::stripQuotes($row[14] ?? null),
                    'data_consegna' => Norm::stripQuotes($row[15] ?? null),
                    'bollo_virtuale' => Norm::stripQuotes($row[16] ?? null),
                ],
            ]);
        }

        return new ParsedFile(
            format: 'sdi_csv',
            rows: $parsed,
            sellerVats: $parsed->pluck('meta.partita_iva_fornitore')->filter()->unique()->values()->all(),
            skipped: $skipped,
            warnings: $skipped > 0 ? [__(':n rows without a valid date were skipped.', ['n' => $skipped])] : [],
        );
    }
}
