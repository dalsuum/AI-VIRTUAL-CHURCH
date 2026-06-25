<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the Bible Study chat slice. The controller's ONLY job is request → validation
 * → orchestrator, so all input rules live here. User text is bounded; session_id is optional
 * (resume) and ownership is enforced downstream by the ConversationStore, not trusted here.
 */
final class SendStudyChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'message'    => ['required', 'string', 'max:4000'],
            'session_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
