<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\VocabEntry;
use App\Models\Vocabulary;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\Rule;

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
                foreach (Vocabulary::LANGUAGE_COLUMNS as $col) {
                    $sub->orWhere($col, 'like', "%{$search}%");
                }
            });
        }

        $words = $q->get(array_merge(['id'], Vocabulary::LANGUAGE_COLUMNS, ['category']));

        return response()->json(['vocabulary' => $words]);
    }

    /**
     * Learner view: the AI-generated entry for one curated concept in one language.
     * Returns the cached entry when ready; otherwise enqueues generation (reusing the
     * existing `vocabulary` seed as the concept) and replies 202 while it is produced.
     * Public, like {@see index} — this is published reference content.
     */
    public function learn(Request $request, Vocabulary $vocabulary): JsonResponse
    {
        $lang = $request->validate([
            'lang' => ['required', 'string', Rule::in(Setting::LANGUAGES)],
        ])['lang'];

        $entry = VocabEntry::firstOrCreate(
            ['vocabulary_id' => $vocabulary->id, 'language' => $lang],
        );

        if ($entry->payload !== null) {
            return response()->json(['status' => 'ready', 'entry' => $entry]);
        }

        Redis::rpush('ai:history', json_encode([
            'mode'          => 'vocab_generate',
            'vocabulary_id' => $vocabulary->id,
            'language'      => $lang,
            'concept'       => $vocabulary->english,
            'zolai'         => $vocabulary->zolai,
        ]));

        return response()->json(['status' => 'generating', 'entry' => $entry], 202);
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
            'falam'    => ['nullable', 'string', 'max:255'],
            'hakha'    => ['nullable', 'string', 'max:255'],
            'matu'     => ['nullable', 'string', 'max:255'],
            'mizo'     => ['nullable', 'string', 'max:255'],
            'paite'    => ['nullable', 'string', 'max:255'],
            'sizang'   => ['nullable', 'string', 'max:255'],
            'burmese'  => ['nullable', 'string', 'max:255'],
            'hebrew'   => ['nullable', 'string', 'max:255'],
            'english'  => [$required, 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
        ]);

        foreach (array_merge(Vocabulary::LANGUAGE_COLUMNS, ['category']) as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $data[$field] !== null ? trim($data[$field]) : null;
            }
        }

        return $data;
    }
}
