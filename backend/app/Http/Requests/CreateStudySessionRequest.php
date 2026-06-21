<?php

namespace App\Http\Requests;

use App\Models\ModuleManifest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new discussion session. Fail-closed: language/translation must be in
 * the served sets, agent_count is bounded to the manifest's 2–7 window, and the
 * question is length-capped. The question is conversation DATA only — it never
 * configures anything.
 */
class CreateStudySessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // guests are authenticated users too
    }

    public function rules(): array
    {
        $manifest = ModuleManifest::where('key', config('bible_study.module'))->first();
        $languages = $manifest?->languages ?? ['en'];
        $min = max(ModuleManifest::AGENT_COUNT_MIN, (int) ($manifest->min_agent_count ?? 2));
        $max = min(ModuleManifest::AGENT_COUNT_MAX, (int) ($manifest->max_agent_count ?? 7));

        return [
            'language'    => ['required', 'string', Rule::in($languages)],
            'translation' => ['required', 'string', 'max:12'],
            'style'       => ['nullable', 'string', 'max:40'],
            'agent_count' => ['required', 'integer', "min:$min", "max:$max"],
            'question'    => ['required', 'string', 'min:3', 'max:2000'],
            'contact_email' => ['nullable', 'email', 'max:190'],
        ];
    }
}
