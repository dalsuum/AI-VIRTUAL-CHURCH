<?php

namespace App\Http\Controllers;

use App\Models\Vocabulary;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD for the Zolai ↔ Burmese ↔ English vocabulary reference. The public list
 * feeds the #vocabulary page; writes sit behind the admin `vocabulary.manage`
 * permission and are driven by the admin console Vocabulary tab.
 */
class VocabularyController extends Controller
{
    /**
     * Public list for the reference page. Optional ?category=… and ?search=….
     * No auth — this is published reference content.
     */
    public function index(Request $request): JsonResponse
    {
        $q = Vocabulary::query()->orderBy('id');

        $category = trim((string) $request->query('category', ''));
        if ($category !== '' && strtolower($category) !== 'all') {
            $q->where('category', $category);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $q->where(function ($sub) use ($search) {
                $sub->where('zolai', 'like', "%{$search}%")
                    ->orWhere('burmese', 'like', "%{$search}%")
                    ->orWhere('english', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $words = $q->get(['id', 'zolai', 'burmese', 'english', 'category', 'notes']);

        return response()->json(['vocabulary' => $words]);
    }

    public function store(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'vocabulary.manage');

        $word = Vocabulary::create($this->validated($request));

        return response()->json(['ok' => true, 'word' => $word], 201);
    }

    public function update(Request $request, Vocabulary $vocabulary): JsonResponse
    {
        PermissionService::require($request->user(), 'vocabulary.manage');

        $vocabulary->fill($this->validated($request, true));
        $vocabulary->save();

        return response()->json(['ok' => true, 'word' => $vocabulary->fresh()]);
    }

    public function destroy(Request $request, Vocabulary $vocabulary): JsonResponse
    {
        PermissionService::require($request->user(), 'vocabulary.manage');

        $vocabulary->delete();

        return response()->json(['ok' => true]);
    }

    /** Validate + trim. $partial=true allows PATCH to send only changed fields. */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'zolai'    => [$required, 'string', 'max:255'],
            'burmese'  => ['nullable', 'string', 'max:255'],
            'english'  => [$required, 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'notes'    => ['nullable', 'string', 'max:500'],
        ]);

        foreach (['zolai', 'burmese', 'english', 'category', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $data[$field] !== null ? trim($data[$field]) : null;
            }
        }

        return $data;
    }
}
