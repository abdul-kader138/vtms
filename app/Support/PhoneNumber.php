<?php

namespace App\Support;

use Giggsey\Locale\Locale;
use Illuminate\Support\Str;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

final class PhoneNumber
{
    /**
     * With a $region (ISO 3166-1 alpha-2, e.g. "IT") the value may be a national
     * number. Without one it must carry its own "+" country code. Anything that
     * isn't valid comes back unchanged for ValidPhoneNumber to reject.
     */
    public static function normalize(mixed $value, mixed $region = null): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (! self::isValid($value, $region)) {
            return $value;
        }

        $util = PhoneNumberUtil::getInstance();

        return $util->format($util->parse($value, self::regionCode($region)), PhoneNumberFormat::E164);
    }

    public static function isValid(?string $value, mixed $region = null): bool
    {
        $value = trim((string) $value);

        if ($value === '') {
            return true;
        }

        $region = self::regionCode($region);

        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($value, $region);

            return $region === null
                ? $util->isValidNumber($number)
                : $util->isValidNumberForRegion($number, $region);
        } catch (NumberParseException) {
            return false;
        }
    }

    /** The region a stored number belongs to, used to preselect the country. */
    public static function regionOf(?string $value): ?string
    {
        try {
            $util = PhoneNumberUtil::getInstance();

            return $util->getRegionCodeForNumber($util->parse((string) $value, null));
        } catch (NumberParseException) {
            return null;
        }
    }

    /** A stored number without its country code, or the value itself if unparsable. */
    public static function national(?string $value): ?string
    {
        try {
            $util = PhoneNumberUtil::getInstance();

            return $util->format($util->parse((string) $value, null), PhoneNumberFormat::NATIONAL);
        } catch (NumberParseException) {
            return $value;
        }
    }

    public static function defaultRegion(): string
    {
        return app()->getLocale() === 'bn' ? 'BD' : 'IT';
    }

    /**
     * @return list<array{code: string, dial: int, name: string, example: string}>
     */
    public static function countries(): array
    {
        $util = PhoneNumberUtil::getInstance();
        $locale = app()->getLocale();

        return collect($util->getSupportedRegions())
            ->map(function (string $code) use ($util, $locale) {
                $example = $util->getExampleNumberForType($code, PhoneNumberType::MOBILE) ?? $util->getExampleNumber($code);

                return [
                    'code' => $code,
                    'dial' => $util->getCountryCodeForRegion($code),
                    'name' => Locale::getDisplayRegion('-'.$code, $locale) ?: $code,
                    'example' => $example ? $util->format($example, PhoneNumberFormat::NATIONAL) : '',
                ];
            })
            ->sortBy(fn (array $country) => Str::ascii($country['name']))
            ->values()
            ->all();
    }

    private static function regionCode(mixed $region): ?string
    {
        return is_string($region) && trim($region) !== '' ? strtoupper(trim($region)) : null;
    }
}
