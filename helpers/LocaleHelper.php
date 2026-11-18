<?php

declare(strict_types=1);

/**
 * The languages and currencies a clinic can choose, and how many decimals each currency uses.
 * (The app has the same table in lib/core/locale_catalog.dart: keep them in step.)
 */
final class LocaleHelper
{
    /** code => decimals */
    public const CURRENCIES = [
        'INR' => 2, 'NPR' => 2, 'LKR' => 2, 'NGN' => 2, 'EGP' => 2, 'GHS' => 2,
        'AED' => 2, 'SAR' => 2, 'OMR' => 3, 'QAR' => 2, 'KWD' => 3, 'BHD' => 3,
        'BDT' => 2, 'PKR' => 2, 'USD' => 2, 'EUR' => 2, 'GBP' => 2,
    ];

    /** The app languages. ur and ar are right-to-left. ha / yo / ig / tw are Hausa, Yoruba, Igbo and Twi (Akan). */
    public const LANGUAGES = ['en', 'hi', 'gu', 'mr', 'ta', 'te', 'bn', 'kn', 'ml', 'pa', 'ur', 'ne', 'si', 'ar', 'ha', 'yo', 'ig', 'tw'];

    /** country => [default currency, default language] used when a clinic picks its country. */
    public const COUNTRY_DEFAULTS = [
        'IN' => ['INR', 'en'], 'NP' => ['NPR', 'ne'], 'LK' => ['LKR', 'si'], 'NG' => ['NGN', 'en'], 'EG' => ['EGP', 'ar'],
        'GH' => ['GHS', 'en'], 'AE' => ['AED', 'ar'], 'SA' => ['SAR', 'ar'], 'OM' => ['OMR', 'ar'], 'QA' => ['QAR', 'ar'],
        'KW' => ['KWD', 'ar'], 'BH' => ['BHD', 'ar'], 'BD' => ['BDT', 'bn'], 'PK' => ['PKR', 'ur'], 'US' => ['USD', 'en'], 'GB' => ['GBP', 'en'],
    ];

    /** country => [dial code, shortest, longest national number WITHOUT the trunk 0]. Mirrors lib/core/locale_catalog.dart. */
    public const PHONE_RULES = [
        'IN' => ['91', 10, 10], 'NP' => ['977', 10, 10], 'LK' => ['94', 9, 9], 'NG' => ['234', 10, 10], 'EG' => ['20', 10, 10],
        'GH' => ['233', 9, 9], 'AE' => ['971', 9, 9], 'SA' => ['966', 9, 9], 'OM' => ['968', 8, 8], 'QA' => ['974', 8, 8],
        'KW' => ['965', 8, 8], 'BH' => ['973', 8, 8], 'BD' => ['880', 10, 10], 'PK' => ['92', 10, 10], 'US' => ['1', 10, 10], 'GB' => ['44', 10, 10],
    ];

    /** True when [$digits] (digits only, trunk 0 allowed) is a plausible mobile number in [$country]. */
    public static function mobileOk(string $country, string $digits): bool
    {
        [, $min, $max] = self::PHONE_RULES[$country] ?? self::PHONE_RULES['IN'];
        $digits = ltrim($digits, '0');
        return strlen($digits) >= $min && strlen($digits) <= $max;
    }

    /** The country of the doctor's primary clinic (IN until they choose). */
    public static function countryFor(int $doctorId): string
    {
        $row = Database::getInstance()->getRow('SELECT country FROM clinics WHERE doctor_id = :d AND deleted_at IS NULL ORDER BY is_primary DESC, id LIMIT 1', [':d' => $doctorId]);
        $c = (string) ($row['country'] ?? 'IN');
        return isset(self::PHONE_RULES[$c]) ? $c : 'IN';
    }

    private static array $dpCache = [];

    public static function decimals(string $currency): int
    {
        return self::CURRENCIES[$currency] ?? 2;
    }

    /** Decimals of the currency the doctor's clinic uses (INR if it has none yet). Cached for the request. */
    public static function dpFor(int $doctorId): int
    {
        if (!isset(self::$dpCache[$doctorId])) {
            $row = Database::getInstance()->getRow('SELECT currency FROM clinics WHERE doctor_id = :d AND deleted_at IS NULL ORDER BY is_primary DESC, id LIMIT 1', [':d' => $doctorId]);
            self::$dpCache[$doctorId] = self::decimals((string) ($row['currency'] ?? 'INR'));
        }
        return self::$dpCache[$doctorId];
    }

    public static function forgetCache(): void
    {
        self::$dpCache = [];
    }
}
