<?php

declare(strict_types=1);

/**
 * Lang — translates the API's user-facing messages.
 *
 * The English sentence IS the key (like gettext): `Lang::t('Patient not found.')` returns the clinic's
 * language when lang/<code>.json has an entry for it, and the English sentence otherwise, so a message
 * nobody translated yet is never blank. Placeholders are {name}: Lang::t('Wait {n} seconds.', ['n' => 5]).
 * Counts use Lang::n(singular, plural, count) and the same plural rules as the app.
 *
 * Which language: the app sends `Accept-Language: <code>` (the clinic's language, English while that
 * language is not signed off), so even login errors before sign-in come back in the right language.
 * Response::send() runs every message and field error through here, so most call sites need no change.
 */
final class Lang
{
    private static ?string $code = null;
    private static array $table = [];

    /** The language of this request (one of LocaleHelper::LANGUAGES). */
    public static function code(): string
    {
        if (self::$code === null) {
            self::use(self::fromHeader((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')));
        }
        return self::$code;
    }

    /** Switch language (also for background work that has no request, e.g. a clinic's push text). */
    public static function use(string $code): void
    {
        $code = in_array($code, LocaleHelper::LANGUAGES, true) ? $code : 'en';
        self::$code  = $code;
        self::$table = [];
        if ($code !== 'en') {
            $file = ROOT_PATH . '/lang/' . $code . '.json';
            if (is_file($file)) {
                $t = json_decode((string) file_get_contents($file), true);
                if (is_array($t)) self::$table = $t;
            }
        }
    }

    /** "hi-IN,hi;q=0.9,en;q=0.8" -> "hi" (first language we have), else "en". */
    public static function fromHeader(string $header): string
    {
        foreach (explode(',', $header) as $part) {
            $c = strtolower(trim(explode(';', $part)[0]));
            $c = explode('-', $c)[0];
            if ($c !== '' && in_array($c, LocaleHelper::LANGUAGES, true)) return $c;
        }
        return 'en';
    }

    public static function t(string $english, array $params = []): string
    {
        self::code();
        $s = self::$table[$english] ?? $english;
        if (!is_string($s)) $s = $english;
        return self::fill($s, $params);
    }

    /** A message that depends on a count. {n} is filled in automatically. */
    public static function n(string $singular, string $plural, int|float $count, array $params = []): string
    {
        $code = self::code();
        $entry = self::$table[$plural] ?? null;
        if (is_array($entry)) {
            $s = $entry[self::category($code, $count)] ?? $entry['other'] ?? null;
        } elseif (is_string($entry)) {
            $s = $entry;
        } else {
            $s = null;
        }
        $s ??= ($count == 1 ? $singular : $plural);
        return self::fill($s, ['n' => $count] + $params);
    }

    /** CLDR plural category (same simplification as lib/core/i18n.dart). */
    public static function category(string $lang, int|float $n): string
    {
        $i = abs($n);
        switch ($lang) {
            case 'ar':
                if ($i == 0) return 'zero';
                if ($i == 1) return 'one';
                if ($i == 2) return 'two';
                $m = fmod($i, 100);
                if (floor($i) == $i && $m >= 3 && $m <= 10) return 'few';
                if (floor($i) == $i && $m >= 11 && $m <= 99) return 'many';
                return 'other';
            case 'hi': case 'bn': case 'gu': case 'kn': case 'pa': case 'si':
                return ($i == 0 || $i == 1) ? 'one' : 'other';
            case 'yo': case 'ig': case 'tw':
                return 'other';
            default:
                return $i == 1 ? 'one' : 'other';
        }
    }

    private static function fill(string $s, array $params): string
    {
        if (!$params || !str_contains($s, '{')) return $s;
        foreach ($params as $k => $v) $s = str_replace('{' . $k . '}', (string) $v, $s);
        return $s;
    }
}
