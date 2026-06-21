<?php

namespace App\Http\Controllers;

use App\Models\AiAuditLog;
use App\Models\AiPersona;
use App\Models\AiPromptTemplate;
use App\Models\AiProviderProfile;
use App\Models\AiTool;
use App\Models\AiUsageLedger;
use App\Models\ModuleManifest;
use App\Models\StudyMessage;
use App\Models\StudySession;
use App\Services\PermissionService;
use App\Services\StudyTiers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI Core / Bible Study admin console API. Every method enforces a permission
 * server-side (study.view for reads, study.manage for writes) and records an audit
 * entry on writes. Server-only fields (system_prompt, tradition_tag, template body,
 * provider keys) are managed write-only and never returned — model $hidden + the
 * explicit selects below guarantee it.
 */
class StudyAdminController extends Controller
{
    private string $module;

    public function __construct()
    {
        $this->module = config('bible_study.module', 'bible_study');
    }

    private function canView(Request $r): void { PermissionService::require($r->user(), 'study.view'); }
    private function canManage(Request $r): void { PermissionService::require($r->user(), 'study.manage'); }

    private function recordAudit(Request $r, string $action, string $type, ?int $id, ?array $before, ?array $after): void
    {
        AiAuditLog::record($r->user()->id, $action, $type, $id, $before, $after, $r->ip());
    }

    // ── Personas ────────────────────────────────────────────────────────────
    public function personas(Request $r): JsonResponse
    {
        $this->canView($r);
        // toArray() honours $hidden, so system_prompt/tradition_tag never leak; we
        // surface the editable fields plus booleans an admin needs to see.
        $rows = AiPersona::where('module', $this->module)
            ->orderBy('language')->orderByDesc('weight')->get()
            ->map(fn (AiPersona $p) => [
                'id' => $p->id, 'language' => $p->language, 'display_name' => $p->display_name,
                'avatar_ref' => $p->avatar_ref, 'weight' => $p->weight,
                'is_moderator' => $p->is_moderator, 'enabled' => $p->enabled,
                'has_system_prompt' => $p->system_prompt !== null && $p->system_prompt !== '',
                'has_tradition_tag' => $p->tradition_tag !== null && $p->tradition_tag !== '',
            ]);

        return response()->json($rows);
    }

    public function storePersona(Request $r): JsonResponse
    {
        $this->canManage($r);
        $data = $r->validate([
            'language' => ['required', 'string', 'max:12'],
            'display_name' => ['required', 'string', 'max:120'],
            'avatar_ref' => ['nullable', 'string', 'max:255'],
            'tradition_tag' => ['nullable', 'string', 'max:120'],
            'system_prompt' => ['required', 'string'],
            'weight' => ['required', 'integer', 'min:0', 'max:100'],
            'is_moderator' => ['boolean'],
        ]);
        $persona = AiPersona::create([...$data, 'module' => $this->module, 'enabled' => true]);
        $this->recordAudit($r, 'persona.create', 'ai_persona', $persona->id, null, $data);

        return response()->json(['id' => $persona->id], 201);
    }

    public function updatePersona(Request $r, AiPersona $persona): JsonResponse
    {
        $this->canManage($r);
        abort_unless($persona->module === $this->module, 404);
        $data = $r->validate([
            'display_name' => ['sometimes', 'string', 'max:120'],
            'avatar_ref' => ['nullable', 'string', 'max:255'],
            'tradition_tag' => ['nullable', 'string', 'max:120'],
            'system_prompt' => ['sometimes', 'string'],
            'weight' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_moderator' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $persona->update($data);
        $this->recordAudit($r, 'persona.update', 'ai_persona', $persona->id, null, $data);

        return response()->json(['ok' => true]);
    }

    public function destroyPersona(Request $r, AiPersona $persona): JsonResponse
    {
        $this->canManage($r);
        abort_unless($persona->module === $this->module, 404);
        $id = $persona->id;
        $persona->delete();
        $this->recordAudit($r, 'persona.delete', 'ai_persona', $id, null, null);

        return response()->json(['ok' => true]);
    }

    // ── Prompt templates ──────────────────────────────────────────────────────
    public function prompts(Request $r): JsonResponse
    {
        $this->canView($r);
        $rows = AiPromptTemplate::where('module', $this->module)
            ->orderBy('language')->orderBy('role')->get()
            ->map(fn (AiPromptTemplate $t) => [
                'id' => $t->id, 'language' => $t->language, 'role' => $t->role,
                'temperature' => $t->temperature, 'max_tokens' => $t->max_tokens, 'enabled' => $t->enabled,
            ]);

        return response()->json($rows);
    }

    public function updatePrompt(Request $r, AiPromptTemplate $template): JsonResponse
    {
        $this->canManage($r);
        abort_unless($template->module === $this->module, 404);
        $data = $r->validate([
            'body' => ['sometimes', 'string'],
            'temperature' => ['sometimes', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['sometimes', 'integer', 'min:64', 'max:8192'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        // Flag instruction-like edits that target system layers (audit, not block).
        $warnings = [];
        if (isset($data['body'])) {
            foreach (['ignore previous', 'reveal system', 'override moderator', 'system prompt'] as $p) {
                if (stripos($data['body'], $p) !== false) {
                    $warnings[] = $p;
                }
            }
        }
        $template->update($data);
        $this->recordAudit($r, 'prompt.update', 'ai_prompt_template', $template->id, null,
            ['fields' => array_keys($data), 'warnings' => $warnings]);

        return response()->json(['ok' => true, 'warnings' => $warnings]);
    }

    // ── Provider profiles ─────────────────────────────────────────────────────
    public function providers(Request $r): JsonResponse
    {
        $this->canView($r);
        // key_set accessor is appended; key_ciphertext/key_ref are $hidden.
        return response()->json(AiProviderProfile::all());
    }

    public function storeProvider(Request $r): JsonResponse
    {
        $this->canManage($r);
        $data = $r->validate([
            'name' => ['required', 'string', 'max:80', 'unique:ai_provider_profiles,name'],
            'type' => ['required', 'in:' . implode(',', AiProviderProfile::TYPES)],
            'base_url' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:160'],
            'api_key' => ['nullable', 'string'],   // write-only → encrypted column
            'key_ref' => ['nullable', 'string', 'max:120'],
            'enabled' => ['boolean'],
        ]);
        $profile = AiProviderProfile::create([
            'name' => $data['name'], 'type' => $data['type'],
            'base_url' => $data['base_url'] ?? null, 'model' => $data['model'] ?? null,
            'key_ciphertext' => $data['api_key'] ?? null,  // encrypted by cast
            'key_ref' => $data['key_ref'] ?? null, 'enabled' => $data['enabled'] ?? true,
        ]);
        // Audit redacts key fields automatically.
        $this->recordAudit($r, 'provider.create', 'ai_provider_profile', $profile->id, null,
            ['name' => $data['name'], 'type' => $data['type'], 'key_ciphertext' => $data['api_key'] ?? null]);

        return response()->json(['id' => $profile->id], 201);
    }

    public function updateProvider(Request $r, AiProviderProfile $provider): JsonResponse
    {
        $this->canManage($r);
        $data = $r->validate([
            'base_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:160'],
            'api_key' => ['sometimes', 'nullable', 'string'],
            'key_ref' => ['sometimes', 'nullable', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $update = $data;
        if (array_key_exists('api_key', $update)) {
            // Empty string = clear; non-empty = rotate. Absent = leave unchanged.
            $update['key_ciphertext'] = $update['api_key'] !== '' ? $update['api_key'] : null;
            unset($update['api_key']);
        }
        $provider->update($update);
        $this->recordAudit($r, 'provider.update', 'ai_provider_profile', $provider->id, null,
            ['fields' => array_keys($data)]);

        return response()->json(['ok' => true]);
    }

    public function destroyProvider(Request $r, AiProviderProfile $provider): JsonResponse
    {
        $this->canManage($r);
        $id = $provider->id;
        $provider->delete();
        $this->recordAudit($r, 'provider.delete', 'ai_provider_profile', $id, null, null);

        return response()->json(['ok' => true]);
    }

    // ── Tools (read-only registry view) ───────────────────────────────────────
    public function tools(Request $r): JsonResponse
    {
        $this->canView($r);
        return response()->json(AiTool::all(['id', 'name', 'json_schema', 'scopes', 'enabled']));
    }

    // ── Manifest ──────────────────────────────────────────────────────────────
    public function manifest(Request $r): JsonResponse
    {
        $this->canView($r);
        return response()->json(ModuleManifest::where('key', $this->module)->firstOrFail());
    }

    public function updateManifest(Request $r): JsonResponse
    {
        $this->canManage($r);
        $manifest = ModuleManifest::where('key', $this->module)->firstOrFail();
        $data = $r->validate([
            'enabled' => ['sometimes', 'boolean'],
            'default_agent_count' => ['sometimes', 'integer', 'min:2', 'max:7'],
            'min_agent_count' => ['sometimes', 'integer', 'min:2', 'max:7'],
            'max_agent_count' => ['sometimes', 'integer', 'min:2', 'max:7'],
            'memory_strategy' => ['sometimes', 'in:' . implode(',', ModuleManifest::MEMORY_STRATEGIES)],
        ]);

        // Fail-closed activation: refuse to enable unless every language has at least
        // one moderator + one pastor and the four role templates.
        if (($data['enabled'] ?? $manifest->enabled)) {
            $errors = $this->validateActivation($manifest);
            if ($errors) {
                $manifest->update(['status' => 'invalid']);
                return response()->json(['errors' => $errors], 422);
            }
            $data['status'] = 'active';
            $data['validated_at'] = now();
        }

        $manifest->update($data);
        $this->recordAudit($r, 'manifest.update', 'module_manifest', $manifest->id, null, $data);

        return response()->json(['ok' => true, 'status' => $manifest->status]);
    }

    private function validateActivation(ModuleManifest $manifest): array
    {
        $errors = [];
        foreach ($manifest->languages ?? [] as $lang) {
            $mods = AiPersona::forModuleLanguage($this->module, $lang)->where('is_moderator', true)->count();
            $pastors = AiPersona::forModuleLanguage($this->module, $lang)->where('is_moderator', false)->count();
            if ($mods < 1) $errors[] = "[$lang] needs a moderator persona.";
            if ($pastors < 2) $errors[] = "[$lang] needs at least 2 pastor personas.";
            foreach (['frame', 'pastor', 'synthesis', 'summary'] as $role) {
                $exists = AiPromptTemplate::where(['module' => $this->module, 'language' => $lang, 'role' => $role, 'enabled' => true])->exists();
                if (! $exists) $errors[] = "[$lang] missing '$role' template.";
            }
        }

        return $errors;
    }

    // ── Per-tier pastor caps (guest / member / premium) ───────────────────────
    public function tiers(Request $r): JsonResponse
    {
        $this->canView($r);
        return response()->json(['caps' => StudyTiers::caps(), 'tiers' => StudyTiers::TIERS]);
    }

    public function updateTiers(Request $r): JsonResponse
    {
        $this->canManage($r);
        $data = $r->validate([
            'guest'   => ['sometimes', 'integer', 'min:2', 'max:7'],
            'member'  => ['sometimes', 'integer', 'min:2', 'max:7'],
            'premium' => ['sometimes', 'integer', 'min:2', 'max:7'],
        ]);
        $caps = StudyTiers::save($data);
        $this->recordAudit($r, 'study.tiers.update', 'setting', null, null, $caps);

        return response()->json(['ok' => true, 'caps' => $caps]);
    }

    // ── Sessions / usage / audit (reads) ──────────────────────────────────────
    public function sessions(Request $r): JsonResponse
    {
        $this->canView($r);
        return response()->json(
            StudySession::query()->latest()->paginate(30, ['id', 'user_id', 'language', 'translation', 'state', 'agent_count', 'created_at'])
        );
    }

    public function sessionDetail(Request $r, StudySession $session): JsonResponse
    {
        $this->canView($r);
        $session->load(['messages' => fn ($q) => $q->orderBy('turn'), 'summary']);
        return response()->json($session);
    }

    public function usage(Request $r): JsonResponse
    {
        $this->canView($r);
        $rows = AiUsageLedger::where('module', $this->module)
            ->selectRaw('DATE(created_at) as day, SUM(prompt_tokens) as prompt_tokens, SUM(completion_tokens) as completion_tokens, COUNT(*) as turns')
            ->groupBy('day')->orderByDesc('day')->limit(60)->get();

        return response()->json($rows);
    }

    public function audit(Request $r): JsonResponse
    {
        $this->canView($r);
        return response()->json(
            AiAuditLog::with('actor:id,name')->latest()->paginate(50)
        );
    }
}
