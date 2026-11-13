<?php

namespace App\Support\Tax;

use App\Enums\CustomerType;
use App\Enums\TaxPaymentType;
use Illuminate\Database\Eloquent\Model;

/**
 * F24 tax codes (codice tributo / causale) a payment is made under, so the
 * customer or accountant can fill the F24 form straight from the schedule.
 * Only codes that are unambiguous for Regime Forfettario are returned.
 */
class F24Code
{
    public static function for(string $type, Model $payment): ?string
    {
        return match ($type) {
            'tax' => match ($payment->payment_type) {
                TaxPaymentType::Balance => '1792',
                TaxPaymentType::FirstAdvance => '1790',
                TaxPaymentType::SecondAdvance => '1791',
                default => null,
            },
            'fattura_tra_privati' => $payment->quarter ? (string) (2520 + (int) $payment->quarter) : '2521–2524',
            'diritto_annuale' => '3850',
            // INPS causali (inps.it, "F24 per artigiani e commercianti"):
            // fixed contributions on the minimale AF/CF; income above it
            // (balance and advances) AP/CP — APR/CPR only for an instalment
            // plan. Gestione Separata professionals: PXX, or P10 with a
            // pension / other compulsory cover.
            'inps' => match ($payment->customer?->customer_type) {
                CustomerType::Artigiani => (int) $payment->installment_number >= 5 ? 'AP' : 'AF',
                CustomerType::Commercianti => (int) $payment->installment_number >= 5 ? 'CP' : 'CF',
                CustomerType::GestioneSeparata => $payment->customer->gs_other_pension ? 'P10' : 'PXX',
                default => null,
            },
            default => null,
        };
    }

    public static function section(string $type): string
    {
        return match ($type) {
            'tax', 'fattura_tra_privati' => __('Erario'),
            'diritto_annuale' => __('IMU e altri tributi locali'),
            'inps' => __('INPS'),
            'inail' => __('Altri enti — INAIL'),
            default => '',
        };
    }
}
