<?php

namespace App\Support\Tax;

/**
 * Coefficients of profitability by ATECO group — Legge 190/2014, allegato 4
 * (as amended by Legge 145/2018).
 */
enum AtecoGroup: string
{
    case Food = 'food';
    case Trade = 'trade';
    case StreetFood = 'street_food';
    case StreetOther = 'street_other';
    case Construction = 'construction';
    case Intermediaries = 'intermediaries';
    case Hospitality = 'hospitality';
    case Professional = 'professional';
    case Other = 'other';

    public function coefficient(): float
    {
        return match ($this) {
            self::Food, self::Trade, self::StreetFood, self::Hospitality => 40.0,
            self::StreetOther => 54.0,
            self::Intermediaries => 62.0,
            self::Other => 67.0,
            self::Professional => 78.0,
            self::Construction => 86.0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Food => __('Food and beverage industry'),
            self::Trade => __('Wholesale and retail trade'),
            self::StreetFood => __('Street trade — food and beverages'),
            self::StreetOther => __('Street trade — other products'),
            self::Construction => __('Construction and real estate'),
            self::Intermediaries => __('Trade intermediaries'),
            self::Hospitality => __('Accommodation and food service'),
            self::Professional => __('Professional, scientific, technical, health, education, finance and insurance'),
            self::Other => __('Other economic activities'),
        };
    }

    /** Best match for an ATECO code like "43.21.01", or null if unparseable. */
    public static function forAtecoCode(?string $code): ?self
    {
        $code = trim((string) $code);

        if (! preg_match('/^(\d{2})(?:\.(\d))?(\d)?/', $code, $m)) {
            return null;
        }

        $division = (int) $m[1];
        $group = isset($m[2]) && $m[2] !== '' ? (int) ($m[1].$m[2]) : null; // e.g. 46.1 → 461
        $class = isset($m[3]) && $m[3] !== '' ? (int) ($m[1].$m[2].$m[3]) : null; // e.g. 47.81 → 4781

        return match (true) {
            in_array($division, [10, 11], true) => self::Food,
            in_array($division, [41, 42, 43, 68], true) => self::Construction,
            $group === 461 => self::Intermediaries,
            $class === 4781 => self::StreetFood,
            $class !== null && $class >= 4782 && $class <= 4789 => self::StreetOther,
            $division === 45, $division === 46, $division === 47 => self::Trade,
            in_array($division, [55, 56], true) => self::Hospitality,
            in_array($division, [64, 65, 66, 69, 70, 71, 72, 73, 74, 75, 85, 86, 87, 88], true) => self::Professional,
            default => self::Other,
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $g) => [$g->value => $g->label().' — '.$g->coefficient().'%'])->all();
    }
}
