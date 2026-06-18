<?php

namespace App\Http\Requests;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation rules for AdminController::updateSettings(). Authorization is
 * enforced in the controller via PermissionService, so this request only
 * validates the (large, partial) settings payload.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled in the controller by PermissionService.
        return true;
    }

    public function rules(): array
    {
        return [
            // Per-language narration voice. English supports all providers; Myanmar and
            // Tedim support edge_tts (Microsoft cloud, free) or mms_tts (local MMS, free).
            'narration_mode_en'  => ['sometimes', 'string', 'in:' . implode(',', Setting::NARRATION_MODES)],
            'narration_mode_my'  => ['sometimes', 'string', 'in:edge_tts,mms_tts,off'],
            'narration_mode_td'  => ['sometimes', 'string', 'in:edge_tts,mms_tts,off'],
            // When on, a worshipper new to a mood is served a random song already
            // composed for it instead of generating (and paying for) a fresh one.
            'music_reuse'     => ['sometimes', 'boolean'],
            // Where the worker stores generated audio: local disk or S3.
            'storage_backend' => ['sometimes', 'string', 'in:' . implode(',', Setting::STORAGE_BACKENDS)],
            // The moods offered in the intake form. Free text — a new mood flows
            // through the whole pipeline (LLM tone, music prompt, hymn matching).
            'moods'           => ['sometimes', 'array', 'min:1'],
            'moods.*'         => ['string', 'max:100'],
            // Which music sources worshippers may choose; a non-empty subset.
            'music_sources'   => ['sometimes', 'array', 'min:1'],
            'music_sources.*' => ['string', 'in:' . implode(',', Setting::MUSIC_SOURCES)],
            // Edge TTS voice (used when narration_mode = 'edge_tts').
            'edge_tts_voice'  => ['sometimes', 'string', 'in:' . implode(',', Setting::EDGE_TTS_VOICES)],
            // Voicebox TTS model (used when narration_mode = 'voicebox').
            // Current Docker image exposes Qwen model sizes through POST /generate.
            'voicebox_engine' => ['sometimes', 'string', 'in:qwen,qwen_1_7b'],
            // Whether the "schedule it" option appears in the intake form.
            'scheduling_enabled' => ['sometimes', 'boolean'],
            // The music source pre-selected in the intake form.
            'default_music_source' => ['sometimes', 'string', 'in:' . implode(',', Setting::MUSIC_SOURCES)],
            // Toggle avatar video rendering on/off without touching env vars.
            // avatar_enabled controls the D-ID (cloud) engine; local_avatar_enabled
            // controls the self-hosted open-source engine. When both are on the local
            // engine wins (resolved worker-side in avatar.select_engine).
            'avatar_enabled'      => ['sometimes', 'boolean'],
            'local_avatar_enabled' => ['sometimes', 'boolean'],
            // Toggle karaoke-style word highlighting in the service player.
            'text_highlight_enabled' => ['sometimes', 'boolean'],
            // Per-language narration toggles. All languages default on.
            // Myanmar and Tedim can use native local MMS-TTS through mms_tts mode.
            'narration_en'        => ['sometimes', 'boolean'],
            'narration_my'        => ['sometimes', 'boolean'],
            'narration_td'        => ['sometimes', 'boolean'],
            // Which service languages appear as tabs in the intake form.
            'lang_en'             => ['sometimes', 'boolean'],
            'lang_my'             => ['sometimes', 'boolean'],
            'lang_td'             => ['sometimes', 'boolean'],
            // Cards shown during the preparation countdown.
            'countdown_content_enabled' => ['sometimes', 'boolean'],
            'countdown_content_source'  => ['sometimes', 'string', 'in:banners,testimonies,online,both,all,off'],
            'countdown_banners'         => ['sometimes', 'array', 'max:12'],
            'countdown_banners.*.text'  => ['required_with:countdown_banners', 'string', 'max:300'],
            'countdown_banners.*.source'=> ['nullable', 'string', 'max:80'],
            // Keywords rejected from YouTube results to enforce Christian-only content.
            'content_filter_keywords'   => ['sometimes', 'array'],
            'content_filter_keywords.*' => ['string', 'max:100'],
            // Orchestration mode: 'pipeline' = hard-coded Python flow (default);
            // 'agent' = AI agent that reasons about segment order and retries.
            'orchestration_mode' => ['sometimes', 'string', 'in:pipeline,agent'],
            // Which LLM powers the AI agent (only used when orchestration_mode = 'agent').
            'agent_provider'     => ['sometimes', 'string', 'in:claude,gemini,chatgpt'],
            // Premium GPU via RunPod Serverless
            'runpod_enabled'     => ['sometimes', 'boolean'],
            // Ad slot — raw HTML/embed pasted by the admin (Google Ads, custom banner, etc.)
            'ad_slot_enabled' => ['sometimes', 'boolean'],
            'ad_slot_html'    => ['sometimes', 'nullable', 'string', 'max:8000'],
            // Show the ads box on the public Live Sticker page.
            'sticker_ads_enabled' => ['sometimes', 'boolean'],
            // AI chord detection for the song editor. Off = manual ChordPro only.
            // The model id/endpoint can be set here or fall back to env (AI_CHORD_MODEL).
            'ai_chords_enabled' => ['sometimes', 'boolean'],
            'ai_chords_model'   => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
