<?php

namespace App\Services;

use App\Models\AiPersona;
use App\Models\AiPromptTemplate;
use App\Models\AiProviderProfile;
use App\Models\ModuleManifest;
use App\Models\StudyMessage;
use App\Models\StudySession;
use Illuminate\Support\Facades\Redis;

/**
 * Composes a discussion job SERVER-SIDE and hands it to the worker via Redis.
 *
 * SECURITY: the job is the trust boundary. User text is a single field
 * ('question'); everything else — personas, system prompts, role templates,
 * provider model — is resolved here from the DB, never from the client. Provider
 * CREDENTIALS never travel in the payload (the worker reads OPENROUTER_API_KEY from
 * env); only the non-secret model id is sent.
 *
 * Personas are lazy-loaded for (module, language) only — never all languages.
 */
class StudyDispatchService
{
    private string $module;

    public function __construct()
    {
        $this->module = config('bible_study.module', 'bible_study');
    }

    /** Push a discussion round for the latest user message. */
    public function dispatchRound(StudySession $session, string $question): void
    {
        $manifest = ModuleManifest::where('key', $this->module)->first();
        abort_unless($manifest && $manifest->isActive(), 503, 'Bible Study is not available.');

        $job = [
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'mode'          => 'discuss',
            'session_id'    => $session->id,
            'language'      => $session->language,
            'language_name' => $this->languageName($session->language),
            'translation'  => $session->translation,
            'agent_count'  => $manifest->clampAgentCount((int) $session->agent_count),
            'round_no'     => $this->nextRoundNumber($session),
            'base_turn'    => (int) StudyMessage::where('session_id', $session->id)->max('turn'),
            'question'     => $question,
            'personas'     => $this->personas($session->language),
            'templates'    => $this->templates($session->language),
            'provider'     => $this->provider($manifest),
        ];

        Redis::rpush(config('bible_study.queue', 'ai:study'), json_encode($job));
    }

    /** Push the end-of-discussion summary job. */
    public function dispatchSummary(StudySession $session): void
    {
        $turns = StudyMessage::where('session_id', $session->id)
            ->whereIn('role', ['moderator', 'pastor', 'synthesis'])
            ->orderBy('turn')
            ->get(['role', 'content'])
            ->map(fn (StudyMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        $manifest = ModuleManifest::where('key', $this->module)->first();

        $job = [
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'mode'          => 'summary',
            'session_id'    => $session->id,
            'language'      => $session->language,
            'language_name' => $this->languageName($session->language),
            'translation'  => $session->translation,
            'personas'     => $this->personas($session->language),
            'templates'    => $this->templates($session->language),
            'turns'        => $turns,
            'provider'     => $manifest ? $this->provider($manifest) : [],
        ];

        Redis::rpush(config('bible_study.queue', 'ai:study'), json_encode($job));
    }

    /** Lazy-load enabled personas for one language (incl. module='*' shared). */
    private function personas(string $language): array
    {
        return AiPersona::forModuleLanguage($this->module, $language)
            ->get()
            ->map(fn (AiPersona $p) => [
                'id'            => $p->id,
                'display_name'  => $p->display_name,
                'system_prompt' => $p->system_prompt,   // server-only; stays in job, not client
                'tradition_tag' => $p->tradition_tag,   // lens, server-only
                'weight'        => $p->weight,
                'is_moderator'  => (bool) $p->is_moderator,
                'enabled'       => true,
            ])->all();
    }

    private function templates(string $language): array
    {
        $out = [];
        $rows = AiPromptTemplate::where('module', $this->module)
            ->where('language', $language)
            ->where('enabled', true)
            ->get();
        foreach ($rows as $t) {
            $out[$t->role] = [
                'body'        => $t->body,
                'temperature' => (float) $t->temperature,
                'max_tokens'  => (int) $t->max_tokens,
            ];
        }

        return $out;
    }

    /** Non-secret provider config (model id only; key resolved worker-side from env). */
    private function provider(ModuleManifest $manifest): array
    {
        $name = $manifest->config['default_provider'] ?? null;
        $profile = $name
            ? AiProviderProfile::where('name', $name)->where('enabled', true)->first()
            : AiProviderProfile::where('enabled', true)->first();

        return $profile ? ['type' => $profile->type, 'model' => $profile->model] : [];
    }

    private function nextRoundNumber(StudySession $session): int
    {
        return (int) StudyMessage::where('session_id', $session->id)
            ->where('role', 'user')->count();
    }

    private function languageName(string $code): string
    {
        return [
            'en' => 'English', 'my' => 'Burmese (Myanmar)', 'td' => 'Tedim (Zolai)',
            'cnh' => 'Hakha Chin', 'cfm' => 'Falam Chin', 'lus' => 'Mizo', 'hlt' => 'Matu Chin',
        ][$code] ?? 'English';
    }
}
