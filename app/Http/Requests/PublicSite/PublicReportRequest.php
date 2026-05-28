<?php

namespace App\Http\Requests\PublicSite;

use App\DTOs\Moderation\PublicReportDto;
use Illuminate\Foundation\Http\FormRequest;

class PublicReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint — no per-user authz; rate-limits + Turnstile do the gating.
        return true;
    }

    public function rules(): array
    {
        return [
            'target_type'     => ['required', 'string', 'in:Site'],
            'target_handle'   => ['required', 'string', 'min:1', 'max:60'],
            'reason_code'     => ['required', 'string', 'in:' . implode(',', self::ALLOWED_REASON_CODES)],
            'details'         => ['nullable', 'string', 'max:4000'],
            'reporter_email'  => ['nullable', 'email', 'max:255'],
            'turnstile_token' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_type.in'           => 'Reports against this type of target are not supported yet.',
            'reason_code.in'           => "That reason isn't supported. Please pick one of the options.",
            'details.max'              => 'Please keep details under 4000 characters.',
            'turnstile_token.required' => 'Please complete the verification and try again.',
        ];
    }

    public function toDto(): PublicReportDto
    {
        return new PublicReportDto(
            targetType:    $this->string('target_type')->toString(),
            targetHandle:  strtolower(trim($this->string('target_handle')->toString())),
            reasonCode:    $this->string('reason_code')->toString(),
            details:       $this->input('details'),
            reporterEmail: $this->input('reporter_email'),
            reporterIp:    $this->ip(),
        );
    }

    public const ALLOWED_REASON_CODES = [
        'spam', 'harassment', 'impersonation', 'illegal_content', 'sexual_content',
        'self_harm', 'hate_speech', 'intellectual_property', 'fake_profile', 'other',
    ];
}
