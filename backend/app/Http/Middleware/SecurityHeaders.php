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
        // The public share pages render media + inline CSS, so they need a
        // relaxed policy: the sticker page (/s/<id>) shows an <img>; the Father's
        // Day MV page (/v/<id>) shows a <video> + poster. Everything else stays
        // locked to 'none'.
        $csp = ($request->is('s/*') || $request->is('v/*'))
            ? "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'self' 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'"
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
