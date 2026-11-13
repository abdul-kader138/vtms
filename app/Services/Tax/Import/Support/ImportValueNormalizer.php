<?php

namespace App\Services\Tax\Import\Support;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ImportValueNormalizer
{
    public static function stripQuotes(?string $value): string
    {
        return trim(str_replace(["'", '"'], '', (string) $value));
    }

    /**
     * "000000000122,95" / "1.234,56" (Italian) → 122.95 / 1234.56.
     * A value without a comma is a plain number ("122.95" from an XLSX cell
     * or an XML amount) and keeps its decimal point.
     */
    public static function decimal(?string $value): float
    {
        $value = str_replace([' ', "\u{00A0}", '€'], '', self::stripQuotes($value));

        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    public static function dateOnly(?string $value): ?Carbon
    {
        return self::date($value, ['d/m/Y', 'Y-m-d', 'd/m/Y H:i:s', 'd-m-Y'])?->startOfDay();
    }

    public static function dateTime(?string $value): ?Carbon
    {
        return self::date($value, ['d/m/Y H:i:s', 'd/m/Y H:i', 'Y-m-d H:i:s', 'd/m/Y']);
    }

    /** @param list<string> $formats */
    private static function date(?string $value, array $formats): ?Carbon
    {
        $value = self::stripQuotes($value);

        if ($value === '') {
            return null;
        }

        // Excel serial date from an XLS/XLSX re-save.
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
        }

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }
            if ($date !== false && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }
}
