<?php

namespace App\Http\Requests;

use App\Enums\InvitationActivity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates a new invitation. The invitee must exist and not be the inviter; activity
 * must be a known together-activity; an optional schedule must be in the future with a
 * valid IANA timezone. Block / friend-only refusal is enforced downstream by
 * PrivacyGate in InvitationService (not a validation concern).
 */
class CreateInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'invitee_id'   => ['required', 'integer', Rule::exists('users', 'id'), Rule::notIn([$this->user()->id])],
            'activity'     => ['required', new Enum(InvitationActivity::class)],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'timezone'     => ['nullable', 'timezone'],
            'message'      => ['nullable', 'string', 'max:500'],
        ];
    }
}
