<?php

namespace App\Http\Requests;

use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Partial update of the authenticated user's privacy settings. Only the stable Phase 1
 * settings are exposed here; notification-channel preferences belong with the later
 * reminder/notification-settings work. Every field is optional (PATCH semantics).
 */
class UpdatePrivacyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'profile_visibility'  => ['sometimes', new Enum(Visibility::class)],
            'activity_visibility' => ['sometimes', new Enum(Visibility::class)],
            'presence_visibility' => ['sometimes', new Enum(Visibility::class)],
            'friend_only_mode'    => ['sometimes', 'boolean'],
            'incognito'           => ['sometimes', 'boolean'],
        ];
    }
}
