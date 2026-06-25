<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin CRUD + import/export for the categorized YouTube content filter.
 *
 * Keywords are grouped into categories, each of which declares a blocking
 * scope ('both' | 'music' | 'sermon'). The worker pipeline reads the flattened,
 * scope-aware lists via the public /config endpoint to skip matching YouTube
 * worship/sermon results. Persistence lives in Setting (key:
 * content_filter_categories), kept in sync with the legacy flat key.
 */
class ContentFilterController extends Controller
{
    /** Read the full categorized filter plus available scopes. */
    public function index(): JsonResponse
    {
        return response()->json([
            'categories' => Setting::filterCategories(),
            'scopes'     => Setting::FILTER_SCOPES,
            'types'      => Setting::FILTER_TYPES,
        ]);
    }

    /** Replace the entire filter — used by JSON restore/import. */
    public function replace(Request $request): JsonResponse
    {
        $data = $request->validate([
            'categories'                => ['present', 'array'],
            'categories.*.id'           => ['nullable', 'string', 'max:60'],
            'categories.*.label'        => ['required', 'string', 'max:80'],
            'categories.*.description'  => ['nullable', 'string', 'max:200'],
            'categories.*.scope'        => ['nullable', 'string', 'in:both,music,sermon'],
            'categories.*.type'         => ['nullable', 'string', 'in:block,allow'],
            'categories.*.keywords'     => ['nullable', 'array'],
            'categories.*.keywords.*'   => ['string', 'max:100'],
        ]);

        $categories = Setting::setCategories($data['categories']);

        return response()->json(['ok' => true, 'categories' => $categories]);
    }

    /** Add a new (empty) category. */
    public function addCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label'       => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:200'],
            'scope'       => ['nullable', 'string', 'in:both,music,sermon'],
            'type'        => ['nullable', 'string', 'in:block,allow'],
        ]);

        $categories = Setting::filterCategories();
        $categories[] = [
            'label'       => $data['label'],
            'description' => $data['description'] ?? '',
            'scope'       => $data['scope'] ?? 'both',
            'type'        => $data['type'] ?? 'block',
            'keywords'    => [],
        ];

        return $this->persist($categories);
    }

    /** Rename / re-scope an existing category. */
    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'label'       => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'scope'       => ['sometimes', 'string', 'in:both,music,sermon'],
            'type'        => ['sometimes', 'string', 'in:block,allow'],
        ]);

        $categories = Setting::filterCategories();
        $found = false;
        foreach ($categories as &$cat) {
            if ($cat['id'] === $id) {
                $found = true;
                if (array_key_exists('label', $data))       $cat['label'] = $data['label'];
                if (array_key_exists('description', $data))  $cat['description'] = (string) $data['description'];
                if (array_key_exists('scope', $data))        $cat['scope'] = $data['scope'];
                if (array_key_exists('type', $data))         $cat['type'] = $data['type'];
            }
        }
        unset($cat);

        if (! $found) {
            return response()->json(['message' => 'Category not found.'], 404);
        }

        return $this->persist($categories);
    }

    /** Delete a whole category and its keywords. */
    public function deleteCategory(string $id): JsonResponse
    {
        $categories = array_values(array_filter(
            Setting::filterCategories(),
            fn ($cat) => $cat['id'] !== $id,
        ));

        return $this->persist($categories);
    }

    /** Add a keyword to a category. */
    public function addKeyword(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
        ]);
        $keyword = mb_strtolower(trim($data['keyword']));

        if ($keyword === '') {
            return response()->json(['message' => 'Keyword cannot be blank.'], 422);
        }

        $categories = Setting::filterCategories();
        $found = false;
        foreach ($categories as &$cat) {
            if ($cat['id'] === $id) {
                $found = true;
                if (in_array($keyword, $cat['keywords'], true)) {
                    return response()->json(['message' => "\"{$keyword}\" is already in this category."], 422);
                }
                $cat['keywords'][] = $keyword;
            }
        }
        unset($cat);

        if (! $found) {
            return response()->json(['message' => 'Category not found.'], 404);
        }

        return $this->persist($categories);
    }

    /** Rename a keyword within a category. */
    public function updateKeyword(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:100'],
            'to'   => ['required', 'string', 'max:100'],
        ]);
        $from = mb_strtolower(trim($data['from']));
        $to   = mb_strtolower(trim($data['to']));

        if ($to === '') {
            return response()->json(['message' => 'Keyword cannot be blank.'], 422);
        }

        $categories = Setting::filterCategories();
        $found = false;
        foreach ($categories as &$cat) {
            if ($cat['id'] === $id) {
                $found = true;
                $cat['keywords'] = array_values(array_unique(array_map(
                    fn ($kw) => $kw === $from ? $to : $kw,
                    $cat['keywords'],
                )));
            }
        }
        unset($cat);

        if (! $found) {
            return response()->json(['message' => 'Category not found.'], 404);
        }

        return $this->persist($categories);
    }

    /** Remove a keyword from a category. */
    public function deleteKeyword(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
        ]);
        $keyword = mb_strtolower(trim($data['keyword']));

        $categories = Setting::filterCategories();
        foreach ($categories as &$cat) {
            if ($cat['id'] === $id) {
                $cat['keywords'] = array_values(array_filter(
                    $cat['keywords'],
                    fn ($kw) => $kw !== $keyword,
                ));
            }
        }
        unset($cat);

        return $this->persist($categories);
    }

    /** Download the filter as JSON (also the restore format). */
    public function exportJson(): JsonResponse
    {
        return response()->json([
            'version'    => 1,
            'exported_at' => now()->toIso8601String(),
            'categories' => Setting::filterCategories(),
        ], 200, [
            'Content-Disposition' => 'attachment; filename="content-filter-' . now()->format('Y-m-d') . '.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** Download the filter as CSV (category,scope,keyword per row). */
    public function exportCsv(): StreamedResponse
    {
        $categories = Setting::filterCategories();
        $filename = 'content-filter-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($categories) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['category', 'type', 'scope', 'keyword']);
            foreach ($categories as $cat) {
                $type = $cat['type'] ?? 'block';
                if (empty($cat['keywords'])) {
                    fputcsv($out, [$cat['label'], $type, $cat['scope'], '']);
                    continue;
                }
                foreach ($cat['keywords'] as $kw) {
                    fputcsv($out, [$cat['label'], $type, $cat['scope'], $kw]);
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Persist and return the normalized categories. */
    private function persist(array $categories): JsonResponse
    {
        $saved = Setting::setCategories($categories);

        return response()->json(['ok' => true, 'categories' => $saved]);
    }
}
