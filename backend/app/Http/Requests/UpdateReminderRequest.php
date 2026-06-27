<?php

namespace App\Http\Requests;

use App\Domains\Bible\Models\ReminderSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update of the user's daily-reading reminder settings. Times are validated as
 * H:i; the timezone must be a valid IANA name; channels must be a subset of the allowed
 * set. All fields optional (PATCH semantics).
 */
class UpdateReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'enabled'      => ['sometimes', 'boolean'],
            'morning_at'   => ['sometimes', 'nullable', 'date_format:H:i'],
            'afternoon_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'evening_at'   => ['sometimes', 'nullable', 'date_format:H:i'],
            'timezone'     => ['sometimes', 'nullable', 'timezone'],
            'channels'     => ['sometimes', 'array'],
            'channels.*'   => [Rule::in(ReminderSetting::CHANNELS)],
        ];
    }
}
