<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A follow-up message. `content` is conversation DATA only and is length-capped; it
 * is passed to the orchestrator as the worshipper's untrusted question, never as
 * configuration.
 */
class PostStudyMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }
}
