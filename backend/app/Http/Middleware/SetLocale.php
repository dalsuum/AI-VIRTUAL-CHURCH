<?php

namespace App\Http\Middleware;

use App\Http\Controllers\LocaleController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the active interface locale for every request and persist the choice,
 * so changing language once carries across the whole app (UI strings come from
 * the SPA; this drives server-generated text — validation, notifications, mail).
 *
 * Priority: explicit (?lang / X-Locale) → authenticated user's fav_language →
 * cookie → session → Accept-Language → configured fallback. Everything is
 * validated against the central registry (config/languages.php) before use, so
 * an unknown value can never set an invalid locale.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $codes = LocaleController::codes();

        $hasSession = $request->hasSession();

        $candidate = $request->query('lang')
            ?? $request->header('X-Locale')
            ?? optional($request->user())->fav_language
            ?? $request->cookie('locale')
            ?? ($hasSession ? $request->session()->get('locale') : null)
            ?? $request->getPreferredLanguage($codes); // Accept-Language → best match

        $locale = LocaleController::resolve($candidate);

        App::setLocale($locale);
        if ($hasSession) {
            $request->session()->put('locale', $locale);
        }

        $response = $next($request);

        // Remember the resolved locale for a year so guests keep their choice.
        return $response->withCookie(cookie()->forever('locale', $locale));
    }
}
