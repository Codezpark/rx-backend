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

    /** The app languages. ur and ar are right-to-left. */
    public const LANGUAGES = ['en', 'hi', 'gu', 'mr', 'ta', 'te', 'bn', 'kn', 'ml', 'pa', 'ur', 'ne', 'si', 'ar'];

    /** country => [default currency, default language] used when a clinic picks its country. */
    public const COUNTRY_DEFAULTS = [
        'IN' => ['INR', 'en'], 'NP' => ['NPR', 'ne'], 'LK' => ['LKR', 'si'], 'NG' => ['NGN', 'en'], 'EG' => ['EGP', 'ar'],
        'GH' => ['GHS', 'en'], 'AE' => ['AED', 'ar'], 'SA' => ['SAR', 'ar'], 'OM' => ['OMR', 'ar'], 'QA' => ['QAR', 'ar'],
        'KW' => ['KWD', 'ar'], 'BH' => ['BHD', 'ar'], 'BD' => ['BDT', 'bn'], 'PK' => ['PKR', 'ur'], 'US' => ['USD', 'en'], 'GB' => ['GBP', 'en'],
    ];

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
