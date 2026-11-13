<?php

namespace App\Support\Tax;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Tax deadlines that fall on a Saturday, Sunday or national holiday move to
 * the next working day (art. 7, c. 1, lett. h, DL 70/2011).
 */
class ItalianCalendar
{
    public static function deadline(int $year, int $month, int $day): CarbonImmutable
    {
        return self::nextBusinessDay(CarbonImmutable::create($year, $month, $day));
    }

    public static function nextBusinessDay(CarbonInterface $date): CarbonImmutable
    {
        $date = CarbonImmutable::instance($date)->startOfDay();

        while ($date->isWeekend() || self::isHoliday($date)) {
            $date = $date->addDay();
        }

        return $date;
    }

    public static function isHoliday(CarbonInterface $date): bool
    {
        $fixed = ['01-01', '01-06', '04-25', '05-01', '06-02', '08-15', '11-01', '12-08', '12-25', '12-26'];

        if (in_array($date->format('m-d'), $fixed, true)) {
            return true;
        }

        return $date->isSameDay(self::easter($date->year)->addDay()); // Pasquetta
    }

    /** Anonymous Gregorian algorithm — avoids depending on ext-calendar. */
    public static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}
