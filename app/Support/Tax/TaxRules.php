<?php

namespace App\Support\Tax;

use App\Models\Setting;

/**
 * Which rule set the engine follows. "legacy" reproduces the original VTMS
 * figures exactly (80% advances split 40/40, balance on 16 Feb, annual
 * stamp duty, no INPS deduction). "law" follows the Regime Forfettario
 * rules: INPS paid in the year deducted, 100% advances split 40/60 with the
 * €51.65 / €257.52 thresholds, balance and Diritto Annuale on 30 June,
 * quarterly stamp duty, deadlines moved off weekends/holidays.
 */
class TaxRules
{
    public const LEGACY = 'legacy';

    public const LAW = 'law';

    public const SETTING = 'tax_rules';

    /** Below this, no advance is due. */
    public const ADVANCE_MINIMUM = 51.65;

    /** Below this, the whole advance is paid once, on 30 November. */
    public const ADVANCE_SINGLE_PAYMENT_LIMIT = 257.52;

    /** Stamp duty for Q1 (and Q1+Q2) may be deferred while at or under this. */
    public const STAMP_DUTY_DEFERRAL_LIMIT = 5000.00;

    public static function mode(): string
    {
        return Setting::get(self::SETTING, self::LEGACY) === self::LAW ? self::LAW : self::LEGACY;
    }

    public static function isLaw(): bool
    {
        return self::mode() === self::LAW;
    }

    public static function options(): array
    {
        return [
            self::LAW => __('Italian law (recommended)'),
            self::LEGACY => __('VTMS legacy'),
        ];
    }
}
