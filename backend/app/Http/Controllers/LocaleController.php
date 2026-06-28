<?php

namespace App\Http\Controllers;

/**
 * Interface-locale registry surface. Reads config/languages.php (the single
 * source of truth) and exposes it to the SPA, plus small static helpers the
 * SetLocale middleware and request validation reuse so no language list is
 * duplicated across the codebase.
 */
class LocaleController extends Controller
{
    /** Enabled-locale codes, e.g. ['en','my','fr',...] — the validation allow-list. */
    public static function codes(): array
    {
        return array_keys(array_filter(
            (array) config('languages.list', []),
            fn ($l) => ($l['enabled'] ?? false) === true,
        ));
    }

    /** Resolve a requested locale to a valid enabled one, else the configured fallback. */
    public static function resolve(?string $locale): string
    {
        $locale = (string) $locale;

        return in_array($locale, self::codes(), true)
            ? $locale
            : (string) config('languages.fallback', 'en');
    }

    /** Public: the enabled locale registry for the language selector. */
    public function index()
    {
        $enabled = array_filter(
            (array) config('languages.list', []),
            fn ($l) => ($l['enabled'] ?? false) === true,
        );

        return [
            'fallback'  => (string) config('languages.fallback', 'en'),
            'languages' => $enabled,
        ];
    }
}
