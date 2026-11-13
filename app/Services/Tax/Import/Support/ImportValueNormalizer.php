<?php

namespace App\Services\Tax\Import\Support;

use Carbon\Carbon;

class ImportValueNormalizer
{
    public static function stripQuotes(?string $value): string
    {
        return trim(str_replace("'", '', (string) $value));
    }

    /**
     * Converts an Italian-formatted decimal string like "000000000122,95" to 122.95.
     */
    public static function decimal(?string $value): float
    {
        $value = str_replace(['.', ','], ['', '.'], (string) $value);

        return (float) $value;
    }

    public static function dateOnly(?string $value): ?Carbon
    {
        $value = self::stripQuotes($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function dateTime(?string $value): ?Carbon
    {
        $value = self::stripQuotes($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y H:i:s', $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
