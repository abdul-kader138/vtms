<?php

namespace App\Services\Tax\Import;

use App\Enums\IncomeSource;
use App\Models\Customer;
use App\Services\Tax\Import\Support\ImportValueNormalizer as Norm;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parses the SDI electronic-invoice export format (Tipo fattura;Tipo documento;...;Bollo virtuale),
 * 17 semicolon-delimited columns. Format confirmed from VTMS's Transfers::read_and_save_excel()
 * (behavior reference only — reimplemented from scratch, not copied).
 */
class SdiFatturaImportParser implements IncomeDataImportParser
{
    private const EXPECTED_HEADER = [
        'Tipo fattura', 'Tipo documento', 'Numero fattura / Documento',
        'Data emissione', 'Data trasmissione', 'Codice fiscale fornitore',
        'Partita IVA fornitore', 'Denominazione fornitore',
        'Codice fiscale cliente', 'Partita IVA cliente', 'Denominazione cliente',
        'Imponibile/Importo (totale in euro)', 'Imposta (totale in euro)',
        'Sdi/file', 'Fatture consegnate', 'Data consegna/Presa visione', 'Bollo virtuale',
    ];

    public function parse(string $filePath, Customer $customer): Collection
    {
        $rows = $this->loadRows($filePath);

        $header = array_map('trim', array_shift($rows) ?? []);
        $this->assertHeader($header);

        return collect($rows)
            ->filter(fn (array $row) => count(array_filter($row, fn ($v) => $v !== null && $v !== '')) > 0)
            ->map(function (array $row) {
                $imponibile = Norm::decimal($row[11] ?? null);

                return [
                    'source' => IncomeSource::SdiFattura,
                    'transaction_date' => Norm::dateOnly($row[3] ?? null),
                    // Confirmed from the legacy mapping: both sales_amount and taxable_sales
                    // are populated from the same "Imponibile/Importo" column for this format.
                    'sales_amount' => $imponibile,
                    'taxable_sales' => $imponibile,
                    'sale_taxes' => Norm::decimal($row[12] ?? null),
                    'reference_no' => Norm::stripQuotes($row[2] ?? null) ?: null,
                    'external_id' => Norm::stripQuotes($row[13] ?? null) ?: null,
                    'meta' => [
                        'tipo_fattura' => Norm::stripQuotes($row[0] ?? null),
                        'tipo_documento' => Norm::stripQuotes($row[1] ?? null),
                        'numero_fattura' => Norm::stripQuotes($row[2] ?? null),
                        'data_trasmissione' => Norm::stripQuotes($row[4] ?? null),
                        'codice_fiscale_fornitore' => Norm::stripQuotes($row[5] ?? null),
                        'partita_iva_fornitore' => Norm::stripQuotes($row[6] ?? null),
                        'denominazione_fornitore' => Norm::stripQuotes($row[7] ?? null),
                        'codice_fiscale_cliente' => Norm::stripQuotes($row[8] ?? null),
                        'partita_iva_cliente' => Norm::stripQuotes($row[9] ?? null),
                        'denominazione_cliente' => Norm::stripQuotes($row[10] ?? null),
                        'sdi_file' => Norm::stripQuotes($row[13] ?? null),
                        'fatture_consegnate' => Norm::stripQuotes($row[14] ?? null),
                        'data_consegna' => Norm::stripQuotes($row[15] ?? null),
                        'bollo_virtuale' => Norm::stripQuotes($row[16] ?? null),
                    ],
                ];
            })
            ->filter(fn (array $row) => $row['transaction_date'] !== null)
            ->values();
    }

    private function loadRows(string $filePath): array
    {
        if (str_ends_with(strtolower($filePath), '.csv')) {
            $reader = new Csv();
            $reader->setDelimiter(';');
            $reader->setEnclosure('"');
            $spreadsheet = $reader->load($filePath);
        } else {
            $spreadsheet = IOFactory::load($filePath);
        }

        return $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
    }

    private function assertHeader(array $header): void
    {
        $header = array_slice($header, 0, count(self::EXPECTED_HEADER));

        if ($header !== self::EXPECTED_HEADER) {
            throw new InvalidArgumentException(
                "SDI fattura import: header row does not match the expected format.\n".
                'Expected: '.implode(' | ', self::EXPECTED_HEADER)."\n".
                'Got: '.implode(' | ', $header)
            );
        }
    }
}
