<?php

namespace App\Services\Tax\Import\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Reads the Agenzia delle Entrate CSV exports (and the same data saved as
 * XLS/XLSX) into plain rows, tolerating what real files contain:
 *  - a UTF-8 BOM, or Windows-1252 text;
 *  - Excel re-saves where every line became ONE quoted field
 *    ("'Fattura tra privati';Fattura;…""000000000122,95""…") — unwrapped
 *    and re-split.
 */
final class TabularFileReader
{
    /** @return list<list<string|null>> header first */
    public static function rows(string $path, ?string $originalName = null): array
    {
        $ext = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));

        if (in_array($ext, ['xls', 'xlsx', 'ods'], true)) {
            $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);

            return array_map(fn ($r) => array_map(fn ($v) => $v === null ? null : (string) $v, $r), $rows);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException(__('The file could not be read.'));
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $rows = [];
        $width = null;
        foreach (preg_split('/\r\n|\n|\r/', $content) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $fields = str_getcsv($line, ';', '"', '');
            $width ??= count($fields);

            if (count($fields) !== $width) {
                $fields = self::unwrapExcelLine($line, $width) ?? $fields;
            }

            $rows[] = $fields;
        }

        return $rows;
    }

    /**
     * Excel opening the ";" file as a "," CSV and saving it again turns
     *   'Fattura';…;"000000000122,95";…
     * into
     *   "'Fattura';…;""000000000122","95"";…"
     * — the line split at the decimal commas, each piece quoted. Joining the
     * comma-separated pieces back gives the original line.
     */
    private static function unwrapExcelLine(string $line, int $width): ?array
    {
        $original = implode(',', str_getcsv($line, ',', '"', ''));
        $fields = str_getcsv($original, ';', '"', '');

        return count($fields) === $width ? $fields : null;
    }

    /** Lower-cased, unquoted, BOM-free header cell for comparisons. */
    public static function headerCell(?string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value);

        // Excel re-saves can leave ",," after the last heading.
        return mb_strtolower(rtrim(trim(str_replace(['"', "\u{2019}"], ['', "'"], $value)), ', '));
    }
}
