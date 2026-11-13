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
 * Parses the device/cash-register "privati" export format (Id invio;Matricola dispositivo;...),
 * 9 semicolon-delimited columns. Format confirmed from VTMS's Transfers::import_file_to_db()
 * (behavior reference only — reimplemented from scratch, not copied).
 */
class PrivatiDeviceImportParser implements IncomeDataImportParser
{
    private const EXPECTED_HEADER = [
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

    public function parse(string $filePath, Customer $customer): Collection
    {
        $rows = $this->loadRows($filePath);

        $header = array_map('trim', array_shift($rows) ?? []);
        $this->assertHeader($header);

        return collect($rows)
            ->filter(fn (array $row) => count(array_filter($row, fn ($v) => $v !== null && $v !== '')) > 0)
            ->map(fn (array $row) => [
                'source' => IncomeSource::PrivatiDevice,
                'transaction_date' => Norm::dateTime($row[2] ?? null),
                'sales_amount' => Norm::decimal($row[4] ?? null),
                'taxable_sales' => Norm::decimal($row[5] ?? null),
                'sale_taxes' => Norm::decimal($row[6] ?? null),
                'reference_no' => null,
                'external_id' => Norm::stripQuotes($row[0] ?? null) ?: null,
                'meta' => [
                    'matricola_dispositivo' => Norm::stripQuotes($row[1] ?? null),
                    'data_trasmissione' => Norm::stripQuotes($row[3] ?? null),
                    'periodo_inattivita_da' => Norm::stripQuotes($row[7] ?? null),
                    'periodo_inattivita_a' => Norm::stripQuotes($row[8] ?? null),
                ],
            ])
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
                "Privati/device import: header row does not match the expected format.\n".
                'Expected: '.implode(' | ', self::EXPECTED_HEADER)."\n".
                'Got: '.implode(' | ', $header)
            );
        }
    }
}
