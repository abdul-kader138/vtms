<?php

namespace App\Services\Tax;

use App\Enums\TaxPaymentType;
use App\Models\DirittoAnnualePayment;
use App\Models\FatturaTraPrivatiPayment;
use App\Models\InailPayment;
use App\Models\InpsPayment;
use App\Models\TaxPayment;
use Illuminate\Database\Eloquent\Model;

/**
 * The five payment tables share the same status/amount/paid/receipt columns.
 * This maps the short type slug used in URLs and Livewire action arguments
 * to its model, so callers don't each keep their own copy of the list.
 */
class PaymentRegistry
{
    public const MODELS = [
        'tax' => TaxPayment::class,
        'inps' => InpsPayment::class,
        'inail' => InailPayment::class,
        'diritto_annuale' => DirittoAnnualePayment::class,
        'fattura_tra_privati' => FatturaTraPrivatiPayment::class,
    ];

    public const LABELS = [
        'tax' => 'Flat tax (imposta sostitutiva)',
        'inps' => 'INPS',
        'inail' => 'INAIL',
        'diritto_annuale' => 'Diritto Annuale',
        'fattura_tra_privati' => 'Fattura Tra Privati (stamp duty)',
    ];

    /** @return class-string<Model>|null */
    public static function modelFor(string $type): ?string
    {
        return self::MODELS[$type] ?? null;
    }

    public static function find(string $type, int $id, ?int $customerId = null): ?Model
    {
        $model = self::modelFor($type);

        if ($model === null) {
            return null;
        }

        $payment = $model::find($id);

        if ($payment && $customerId !== null && $payment->customer_id !== $customerId) {
            return null;
        }

        return $payment;
    }

    public static function describe(string $type, Model $payment): string
    {
        $label = __(self::LABELS[$type] ?? $type);

        return match ($type) {
            'tax' => $label.' — '.match ($payment->payment_type) {
                TaxPaymentType::Balance => __('balance'),
                TaxPaymentType::FirstAdvance => __('1st advance'),
                TaxPaymentType::SecondAdvance => __('2nd advance'),
            },
            'inps' => $label.' — '.match ((int) $payment->installment_number) {
                PaymentScheduleService::INPS_EXCESS_BALANCE => __('balance on income above the minimum'),
                PaymentScheduleService::INPS_EXCESS_FIRST_ADVANCE => __('1st advance on income above the minimum'),
                PaymentScheduleService::INPS_EXCESS_SECOND_ADVANCE => __('2nd advance on income above the minimum'),
                default => __('installment :n', ['n' => $payment->installment_number]),
            },
            'fattura_tra_privati' => $payment->quarter ? $label.' — '.__('Q:n', ['n' => $payment->quarter]) : $label,
            default => $label,
        };
    }
}
