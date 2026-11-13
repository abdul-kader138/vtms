<?php

namespace App\Services\Tax\Import;

use App\Enums\IncomeSource;
use App\Services\Tax\Import\Support\ImportValueNormalizer as Norm;
use App\Services\Tax\Import\Support\IncomeRow;
use App\Services\Tax\Import\Support\TabularFileReader;
use InvalidArgumentException;

/**
 * Daily totals sent by a telematic cash register (corrispettivi), exported
 * from "Fatture e Corrispettivi" (Id invio;Matricola dispositivo;…), 9
 * semicolon-separated columns — VTMS's Transfers::import_file_to_db() /
 * read_and_save_csv_privati().
 *
 * As in VTMS's later version, days with no sales and no VAT (the register
 * sends a zero record on closed days) are skipped.
 */
class PrivatiDeviceImportParser implements IncomeDataImportParser
{
    public const HEADER = [
        'Id invio',
        'Matricola dispositivo',
        'Data e ora rilevazione',
        'Data e ora trasmissione',
        'Ammontare delle vendite (totale in euro)',
        'Imponibile vendite (totale in euro)',
        'Imposta vendite (totale in euro)',
        "Periodo di inattivita' da",
        "Periodo di inattivita' a",
    ];

    public static function matchesHeader(array $header): bool
    {
        // Compare the first 7 columns: the two "inattività" headings vary
        // in accent/apostrophe between exports.
        $n = 7;

        return array_map([TabularFileReader::class, 'headerCell'], array_slice($header, 0, $n))
            === array_map('mb_strtolower', array_slice(self::HEADER, 0, $n));
    }

    public function parse(string $filePath, ?string $originalName = null): ParsedFile
    {
        $rows = TabularFileReader::rows($filePath, $originalName);
        $header = array_shift($rows) ?? [];

        if (! self::matchesHeader($header)) {
            throw new InvalidArgumentException(__('This is not a cash register export: the columns do not match "Id invio; Matricola dispositivo; …".'));
        }

        $zeroDays = 0;
        $invalid = 0;
        $parsed = collect();

        foreach ($rows as $row) {
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $date = Norm::dateTime($row[2] ?? null);
            if ($date === null) {
                $invalid++;

                continue;
            }

            $sales = Norm::decimal($row[4] ?? null);
            $vat = Norm::decimal($row[6] ?? null);
            if ($sales == 0.0 && $vat == 0.0) {
                $zeroDays++;

                continue;
            }

            $id = Norm::stripQuotes($row[0] ?? null) ?: null;

            $parsed->push([
                'source' => IncomeSource::PrivatiDevice,
                'document_type' => null,
                'transaction_date' => $date,
                'sales_amount' => $sales,
                'taxable_sales' => Norm::decimal($row[5] ?? null),
                'sale_taxes' => $vat,
                'reference_no' => null,
                'external_id' => $id,
                'fingerprint' => IncomeRow::deviceFingerprint($id),
                'meta' => [
                    'format' => 'device_csv',
                    'matricola_dispositivo' => Norm::stripQuotes($row[1] ?? null),
                    'data_trasmissione' => Norm::stripQuotes($row[3] ?? null),
                    'periodo_inattivita_da' => Norm::stripQuotes($row[7] ?? null),
                    'periodo_inattivita_a' => Norm::stripQuotes($row[8] ?? null),
                ],
            ]);
        }

        $warnings = [];
        if ($zeroDays > 0) {
            $warnings[] = __(':n days with no sales were skipped.', ['n' => $zeroDays]);
        }
        if ($invalid > 0) {
            $warnings[] = __(':n rows without a valid date were skipped.', ['n' => $invalid]);
        }

        return new ParsedFile(
            format: 'device_csv',
            rows: $parsed,
            deviceSerials: $parsed->pluck('meta.matricola_dispositivo')->filter()->unique()->values()->all(),
            skipped: $zeroDays + $invalid,
            warnings: $warnings,
        );
    }
}
