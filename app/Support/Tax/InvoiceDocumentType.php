<?php

namespace App\Support\Tax;

/**
 * FatturaPA document types (TipoDocumento). The XML carries the TD code; the
 * "Fatture e Corrispettivi" CSV list carries an Italian label such as
 * "Nota di credito" or "Fattura differita (TD24)". Both map to one code so
 * revenue sign and duplicate detection work the same for either format.
 */
final class InvoiceDocumentType
{
    /** Codes that reduce revenue: credit notes (ordinary and simplified). */
    public const CREDIT_NOTES = ['TD04', 'TD08'];

    private const LABELS = [
        'fattura' => 'TD01',
        'acconto/anticipo su fattura' => 'TD02',
        'acconto/anticipo su parcella' => 'TD03',
        'nota di credito' => 'TD04',
        'nota di debito' => 'TD05',
        'parcella' => 'TD06',
        'fattura semplificata' => 'TD07',
        'nota di credito semplificata' => 'TD08',
        'nota di debito semplificata' => 'TD09',
    ];

    /** TD code for a CSV label or XML code; the trimmed label if unknown. */
    public static function normalize(?string $value): ?string
    {
        $value = trim(str_replace("'", '', (string) $value));

        if ($value === '') {
            return null;
        }
        if (preg_match('/\b(TD\d{2})\b/i', $value, $m)) {
            return strtoupper($m[1]);
        }

        return self::LABELS[mb_strtolower($value)] ?? mb_substr($value, 0, 40);
    }

    public static function isCreditNote(?string $code): bool
    {
        return in_array($code, self::CREDIT_NOTES, true);
    }

    public static function label(?string $code): string
    {
        return match ($code) {
            null => '—',
            'TD01' => __('Invoice'),
            'TD04', 'TD08' => __('Credit note'),
            'TD05', 'TD09' => __('Debit note'),
            'TD24' => __('Deferred invoice'),
            'TD06' => __('Fee note (parcella)'),
            default => $code,
        };
    }
}
