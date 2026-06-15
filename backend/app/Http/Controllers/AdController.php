<?php

namespace App\Http\Controllers;

use App\Models\Ad;
use App\Models\AdImpression;
use App\Models\AdSlide;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdController extends Controller
{
    // ── Staff reads ──────────────────────────────────────────────────────────

    /** GET /admin/ads — list all ads with slide count and impression stats. */
    public function index(): JsonResponse
    {
        PermissionService::require(request()->user(), 'ads.view');

        $ads = Ad::withCount('slides')
            ->with('impressions')
            ->get()
            ->map(function (Ad $ad) {
                $imps   = $ad->impressions;
                $clicks = $imps->where('clicked', true)->count();
                $revenue =
                    ($imps->count() * $ad->price_per_impression)
                    + ($clicks * $ad->price_per_click);

                return array_merge($ad->toArray(), [
                    'total_impressions' => $imps->count(),
                    'total_clicks'      => $clicks,
                    'total_revenue'     => round($revenue, 4),
                ]);
            });

        return response()->json(['ads' => $ads]);
    }

    /** GET /admin/ads/{ad} — full ad with slides. */
    public function show(Ad $ad): JsonResponse
    {
        PermissionService::require(request()->user(), 'ads.view');

        return response()->json(['ad' => $ad->load('slides')]);
    }

    // ── Admin writes ─────────────────────────────────────────────────────────

    /** POST /admin/ads */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'                => ['required', 'string', 'max:150'],
            'type'                 => ['sometimes', 'in:slideshow,html'],
            'status'               => ['sometimes', 'in:draft,active,paused'],
            'locations'            => ['required', 'array', 'min:1'],
            'locations.*'          => ['in:start,between,end'],
            'target_language'      => ['nullable', 'in:en,my,td'],
            'target_moods'         => ['sometimes', 'array'],
            'currency'             => ['sometimes', 'string', 'size:3'],
            'price_per_impression' => ['sometimes', 'numeric', 'min:0'],
            'price_per_click'      => ['sometimes', 'numeric', 'min:0'],
            'slide_duration'       => ['sometimes', 'integer', 'min:1', 'max:60'],
            'html_content'         => ['nullable', 'string', 'max:20000'],
        ]);

        $ad = Ad::create($data);

        return response()->json(['ad' => $ad->load('slides')], 201);
    }

    /** PATCH /admin/ads/{ad} */
    public function update(Request $request, Ad $ad): JsonResponse
    {
        $data = $request->validate([
            'title'                => ['sometimes', 'string', 'max:150'],
            'type'                 => ['sometimes', 'in:slideshow,html'],
            'status'               => ['sometimes', 'in:draft,active,paused'],
            'locations'            => ['sometimes', 'array', 'min:1'],
            'locations.*'          => ['in:start,between,end'],
            'target_language'      => ['sometimes', 'nullable', 'in:en,my,td'],
            'target_moods'         => ['sometimes', 'array'],
            'currency'             => ['sometimes', 'string', 'size:3'],
            'price_per_impression' => ['sometimes', 'numeric', 'min:0'],
            'price_per_click'      => ['sometimes', 'numeric', 'min:0'],
            'slide_duration'       => ['sometimes', 'integer', 'min:1', 'max:60'],
            'html_content'         => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        $ad->update($data);

        return response()->json(['ad' => $ad->fresh('slides')]);
    }

    /** DELETE /admin/ads/{ad} */
    public function destroy(Ad $ad): JsonResponse
    {
        // Remove slide images from public storage first.
        foreach ($ad->slides as $slide) {
            if ($slide->image_path) {
                Storage::disk('public')->delete($slide->image_path);
            }
        }
        $ad->delete();

        return response()->json(['ok' => true]);
    }

    // ── Slide management ─────────────────────────────────────────────────────

    /** POST /admin/ads/{ad}/slides */
    public function storeSlide(Request $request, Ad $ad): JsonResponse
    {
        $data = $request->validate([
            'type'             => ['sometimes', 'in:image,html'],
            'html_content'     => ['nullable', 'string', 'max:20000'],
            'link_url'         => ['nullable', 'url', 'max:500'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:300'],
        ]);

        $maxOrder = $ad->slides()->max('sort_order') ?? -1;
        $data['sort_order'] = $maxOrder + 1;
        $data['ad_id']      = $ad->id;

        $slide = AdSlide::create($data);

        return response()->json(['slide' => $slide], 201);
    }

    /** PATCH /admin/ads/{ad}/slides/{slide} */
    public function updateSlide(Request $request, Ad $ad, AdSlide $slide): JsonResponse
    {
        abort_unless($slide->ad_id === $ad->id, 404);

        $data = $request->validate([
            'html_content'     => ['sometimes', 'nullable', 'string', 'max:20000'],
            'link_url'         => ['sometimes', 'nullable', 'url', 'max:500'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:300'],
            'sort_order'       => ['sometimes', 'integer', 'min:0'],
        ]);

        $slide->update($data);

        return response()->json(['slide' => $slide]);
    }

    /** DELETE /admin/ads/{ad}/slides/{slide} */
    public function destroySlide(Ad $ad, AdSlide $slide): JsonResponse
    {
        abort_unless($slide->ad_id === $ad->id, 404);

        if ($slide->image_path) {
            Storage::disk('public')->delete($slide->image_path);
        }
        $slide->delete();

        return response()->json(['ok' => true]);
    }

    /** POST /admin/ads/{ad}/slides/{slide}/image */
    public function uploadSlideImage(Request $request, Ad $ad, AdSlide $slide): JsonResponse
    {
        abort_unless($slide->ad_id === $ad->id, 404);

        $request->validate([
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        // Remove old image if present.
        if ($slide->image_path) {
            Storage::disk('public')->delete($slide->image_path);
        }

        $path = $request->file('image')->store("ads/{$ad->id}", 'public');

        $slide->update([
            'image_path' => $path,
            'type'       => 'image',
        ]);

        return response()->json([
            'slide' => $slide,
            'url'   => Storage::disk('public')->url($slide->image_path),
        ]);
    }

    /** POST /admin/ads/{ad}/reorder */
    public function reorderSlides(Request $request, Ad $ad): JsonResponse
    {
        $data = $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach ($data['order'] as $index => $slideId) {
            AdSlide::where('id', $slideId)
                ->where('ad_id', $ad->id)
                ->update(['sort_order' => $index]);
        }

        return response()->json(['ok' => true]);
    }

    // ── Public endpoints (no auth) ───────────────────────────────────────────

    /** GET /ads/active?language=en&mood=grateful */
    public function activeForService(Request $request): JsonResponse
    {
        $language = $request->query('language');
        $mood     = $request->query('mood');

        $ads = Ad::where('status', 'active')
            ->with(['slides' => function ($q) {
                $q->orderBy('sort_order');
            }])
            ->get()
            ->filter(function (Ad $ad) use ($language, $mood) {
                // Language: null = all, or exact match.
                if ($ad->target_language !== null && $ad->target_language !== $language) {
                    return false;
                }
                // Moods: empty array = all, or intersection must be non-empty.
                $targetMoods = $ad->target_moods ?? [];
                if (!empty($targetMoods) && $mood && !in_array($mood, $targetMoods)) {
                    return false;
                }
                return true;
            })
            ->values()
            ->map(function (Ad $ad) {
                $adArr = $ad->toArray();
                $adArr['slides'] = collect($ad->slides)->map(function (AdSlide $slide) {
                    $arr = $slide->toArray();
                    if ($slide->image_path) {
                        $arr['image_url'] = Storage::disk('public')->url($slide->image_path);
                    }
                    return $arr;
                })->values();
                return $adArr;
            });

        return response()->json(['ads' => $ads]);
    }

    /** POST /ads/track */
    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ad_id'         => ['required', 'exists:ads,id'],
            'location'      => ['required', 'in:start,between,end'],
            'duration_ms'   => ['sometimes', 'integer', 'min:0'],
            'clicked'       => ['sometimes', 'boolean'],
            'session_token' => ['sometimes', 'nullable', 'string', 'max:64'],
            'language'      => ['sometimes', 'nullable', 'string', 'max:5'],
            'mood'          => ['sometimes', 'nullable', 'string', 'max:80'],
            'ad_slide_id'   => ['sometimes', 'nullable', 'exists:ad_slides,id'],
        ]);

        AdImpression::create($data);

        return response()->json(['ok' => true]);
    }

    // ── Analytics ────────────────────────────────────────────────────────────

    /** GET /admin/ads-analytics */
    public function analytics(): JsonResponse
    {
        PermissionService::require(request()->user(), 'ads.analytics');

        $stats = DB::table('ad_impressions')
            ->join('ads', 'ads.id', '=', 'ad_impressions.ad_id')
            ->select([
                'ad_impressions.ad_id',
                'ads.title',
                'ads.currency',
                'ads.price_per_impression',
                'ads.price_per_click',
                DB::raw('COUNT(*) as impressions'),
                DB::raw('SUM(ad_impressions.clicked) as clicks'),
                DB::raw('SUM(ad_impressions.duration_ms) as total_duration_ms'),
            ])
            ->groupBy(
                'ad_impressions.ad_id',
                'ads.title',
                'ads.currency',
                'ads.price_per_impression',
                'ads.price_per_click'
            )
            ->get()
            ->map(function ($row) {
                $revenue = ($row->impressions * $row->price_per_impression)
                         + ($row->clicks * $row->price_per_click);
                return [
                    'ad_id'            => $row->ad_id,
                    'title'            => $row->title,
                    'currency'         => $row->currency,
                    'impressions'      => (int) $row->impressions,
                    'clicks'           => (int) $row->clicks,
                    'total_duration_ms'=> (int) $row->total_duration_ms,
                    'revenue'          => round($revenue, 4),
                ];
            });

        return response()->json(['stats' => $stats]);
    }
}
