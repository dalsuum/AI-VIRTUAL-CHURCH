<?php

namespace App\Http\Controllers;

/**
 * Public share pages for Live Stickers — SELF-CONTAINED & REMOVABLE.
 *
 * Served from the MAIN domain (aivirtual.church/s/<id>) via an nginx mapping, so
 * links shared to Facebook / X / etc. show a clean Open-Graph preview of the
 * sticker on the public domain — never the api.* hostname. The image is also
 * re-served here (/si/<id>/<n>) so og:image stays on the main domain.
 *
 * Remove with the rest of the Live Sticker feature (routes/web.php block +
 * the /s//si nginx locations).
 */

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShareController extends Controller
{
    private const DIR = 'stickers';

    /** Open-Graph HTML page for one finished sticker. */
    public function page(string $jobId, int $n = 1): Response
    {
        $jobId = $this->safeId($jobId);
        $n     = max(1, min(5, $n));
        $base  = rtrim(env('FRONTEND_URL', 'https://aivirtual.church'), '/');
        $maker = "{$base}/#stickers";

        if (! $jobId || ! Storage::exists(self::DIR . "/jobs/{$jobId}/sticker_{$n}.png")) {
            // Expired/unknown — bounce to the maker instead of a dead page.
            return response('', 302)->header('Location', $maker);
        }

        $title = $this->pageTitle();
        $img   = "{$base}/si/{$jobId}/{$n}";
        $desc  = 'Made with AI Virtual Church — create your own free sticker!';

        $e = fn ($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$e($title)}</title>
<meta property="og:type" content="website">
<meta property="og:title" content="{$e($title)}">
<meta property="og:description" content="{$e($desc)}">
<meta property="og:image" content="{$e($img)}">
<meta property="og:image:width" content="768">
<meta property="og:image:height" content="768">
<meta property="og:url" content="{$e("{$base}/s/{$jobId}/{$n}")}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$e($title)}">
<meta name="twitter:description" content="{$e($desc)}">
<meta name="twitter:image" content="{$e($img)}">
<style>
  body{margin:0;min-height:100vh;display:flex;flex-direction:column;align-items:center;
       justify-content:center;gap:1.25rem;background:#0f1115;color:#fff;
       font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:2rem}
  img{max-width:min(90vw,420px);height:auto;filter:drop-shadow(0 12px 30px rgba(0,0,0,.5))}
  h1{font-size:1.4rem;margin:0;text-align:center}
  a.cta{background:#dc2626;color:#fff;text-decoration:none;font-weight:700;
        padding:.85rem 1.6rem;border-radius:12px}
</style>
</head>
<body>
  <h1>{$e($title)}</h1>
  <img src="{$e($img)}" alt="{$e($title)}">
  <a class="cta" href="{$e($maker)}">🎨 Make your own sticker</a>
</body>
</html>
HTML;

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Re-serve a finished sticker PNG on the main domain (for og:image). */
    public function image(string $jobId, int $n): BinaryFileResponse|Response
    {
        $jobId = $this->safeId($jobId);
        $n     = max(1, min(5, $n));
        $rel   = self::DIR . "/jobs/{$jobId}/sticker_{$n}.png";
        if (! $jobId || ! Storage::exists($rel)) {
            return response('', 404);
        }

        return response()->file(Storage::path($rel), [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /** The admin-configured sticker page title (the static sticker title). */
    private function pageTitle(): string
    {
        $rel = self::DIR . '/config.json';
        if (Storage::exists($rel)) {
            $c = json_decode((string) Storage::get($rel), true);
            if (is_array($c) && ! empty($c['title'])) {
                return (string) $c['title'];
            }
        }
        return 'My Live Sticker';
    }

    private function safeId(string $id): ?string
    {
        return preg_match('/^[0-9a-f-]{36}$/i', $id) ? $id : null;
    }
}
