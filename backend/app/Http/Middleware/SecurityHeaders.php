<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        // The public sticker share page (/s/<id>) renders an <img> + inline CSS,
        // so it needs a relaxed policy; everything else stays locked to 'none'.
        $csp = $request->is('s/*')
            ? "default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'"
            : "default-src 'none'; frame-ancestors 'none'";
        $response->headers->set('Content-Security-Policy', $csp);

        // Only set HSTS over a real HTTPS connection so local dev isn't poisoned.
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
