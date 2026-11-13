<?php

namespace App\Services\Tax\Import\Support;

use Carbon\CarbonInterface;

/** Shared helpers for the row arrays the parsers produce. */
final class IncomeRow
{
    /** "IT 01234567890" / "it01234567890" → "01234567890". */
    public static function vat(?string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));

        return preg_replace('/^IT(?=\d{11}$)/', '', $value);
    }

    /**
     * Format-independent business key for an invoice: the same document
     * from the CSV list and from its FatturaPA XML gets the same value.
     * Must match the backfill in the 2026_11_14_000002 migration.
     */
    public static function invoiceFingerprint(?string $sellerVat, bool $creditNote, ?string $number, ?CarbonInterface $date): ?string
    {
        $number = strtoupper(trim(str_replace("'", '', (string) $number)));

        if ($number === '' || $date === null) {
            return null;
        }

        return hash('sha256', implode('|', ['inv', self::vat($sellerVat), $creditNote ? 'C' : 'I', $number, $date->toDateString()]));
    }

    public static function deviceFingerprint(?string $idInvio): ?string
    {
        $idInvio = trim(str_replace("'", '', (string) $idInvio));

        return $idInvio === '' ? null : hash('sha256', 'dev|'.$idInvio);
    }
}
