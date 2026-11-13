<?php

namespace App\Services\Tax;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Ravvedimento operoso: what a late payment costs if the customer settles
 * it spontaneously on a given day (art. 13 D.Lgs. 472/1997, sanctions of
 * art. 13 D.Lgs. 471/1997 as reformed by D.Lgs. 87/2024).
 *
 * Penalty for a late payment, on the amount paid late:
 *  - base 25% for payments due from 1 Sep 2024 (30% before);
 *  - halved when paid within 90 days, and within 14 days only 1/15 of
 *    that per day of delay;
 *  - reduced by ravvedimento: 1/10 within 30 days, 1/9 within 90, 1/8
 *    within a year, 1/7 within two years, 1/6 after.
 * Interest: legal rate of each calendar year, day by day.
 *
 * "Within a year" stands in for "by the tax-return deadline", which is
 * close to it for these payments. INPS and INAIL are excluded: those
 * bodies compute their own late charges (sanzioni civili).
 */
class RavvedimentoService
{
    public const REFORM_DATE = '2024-09-01';

    /** Legal interest rate (%) by year — D.M. Economia each December (2026: D.M. 10/12/2025, 1.60%). */
    public const DEFAULT_LEGAL_RATES = [2022 => 1.25, 2023 => 5.00, 2024 => 2.50, 2025 => 2.00, 2026 => 1.60];

    /**
     * F24 codes for the interest and penalty of each payment kind. Stamp
     * duty on e-invoices: 2525 penalty, 2526 interest (Ris. 42/E/2019).
     */
    private const CODES = [
        'tax' => ['interest' => '1793', 'sanction' => '8913'],
        'diritto_annuale' => ['interest' => '3851', 'sanction' => '3852'],
        'fattura_tra_privati' => ['interest' => '2526', 'sanction' => '2525'],
    ];

    public static function supports(string $type): bool
    {
        return isset(self::CODES[$type]);
    }

    /**
     * @return array{applicable: bool, reason: ?string, days: int, sanction_percent: float, reduction: string,
     *     sanction: float, interest: float, total: float, interest_code: ?string, sanction_code: ?string}
     */
    public function calculate(string $type, float $amount, CarbonInterface $due, CarbonInterface $payOn): array
    {
        $due = CarbonImmutable::instance($due)->startOfDay();
        $payOn = CarbonImmutable::instance($payOn)->startOfDay();
        $days = (int) max(0, $due->diffInDays($payOn, false));

        $result = [
            'applicable' => false, 'reason' => null, 'days' => $days, 'sanction_percent' => 0.0, 'reduction' => '—',
            'sanction' => 0.0, 'interest' => 0.0, 'total' => round($amount, 2),
            'interest_code' => self::CODES[$type]['interest'] ?? null, 'sanction_code' => self::CODES[$type]['sanction'] ?? null,
        ];

        if (! self::supports($type)) {
            return ['reason' => __('INPS and INAIL calculate their own late-payment charges; pay the amount and they will bill the rest.')] + $result;
        }
        if ($days === 0 || $amount <= 0) {
            return $result;
        }

        $base = $due->gte(self::REFORM_DATE) ? 25.0 : 30.0;
        $penalty = $days <= 90 ? $base / 2 : $base;
        if ($days <= 14) {
            $penalty *= $days / 15;
        }

        [$divisor, $label] = match (true) {
            $days <= 30 => [10, '1/10'],
            $days <= 90 => [9, '1/9'],
            $days <= 365 => [8, '1/8'],
            $days <= 730 => [7, '1/7'],
            default => [6, '1/6'],
        };

        $sanctionPercent = $penalty / $divisor;
        $sanction = round($amount * $sanctionPercent / 100, 2);
        $interest = $this->interest($amount, $due, $payOn);

        return [
            'applicable' => true,
            'sanction_percent' => round($sanctionPercent, 4),
            'reduction' => $label,
            'sanction' => $sanction,
            'interest' => $interest,
            'total' => round($amount + $sanction + $interest, 2),
        ] + $result;
    }

    /** Legal interest from the day after the deadline to the payment day, at each year's rate. */
    public function interest(float $amount, CarbonImmutable $due, CarbonImmutable $payOn): float
    {
        $rates = self::rates();
        $total = 0.0;
        $from = $due;

        while ($from->lt($payOn)) {
            $yearEnd = $from->endOfYear()->startOfDay();
            $to = $payOn->lt($yearEnd) ? $payOn : $yearEnd;
            $days = (int) $from->diffInDays($to);
            if ($days === 0) {
                $days = 1;
                $to = $from->addDay();
            }

            $rate = $rates[$to->year] ?? end($rates);
            $total += $amount * $rate / 100 * $days / 365;
            $from = $to;
        }

        return round($total, 2);
    }

    /** @return array<int, float> */
    public static function rates(): array
    {
        $stored = Setting::get('legal_interest_rates');
        $rates = is_array($stored) && $stored !== [] ? $stored : self::DEFAULT_LEGAL_RATES;
        $rates = array_map('floatval', $rates);
        ksort($rates);

        return $rates;
    }
}
