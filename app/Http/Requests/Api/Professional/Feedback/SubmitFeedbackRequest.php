<?php

namespace App\Http\Requests\Api\Professional\Feedback;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new feedback submission. Field caps mirror DB CHECK constraints
 * in 20260526210001_create_feedback_table.sql so a validation pass guarantees
 * the insert won't violate a constraint.
 *
 * Severity is required only when kind=bug; everything else accepts null.
 * request_id is treated as opaque correlation data — restricted character set
 * but no semantic validation (frontend chooses its own format).
 */
class SubmitFeedbackRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['bug', 'idea', 'praise', 'question', 'other'])],
            'severity' => [
                'nullable',
                'required_if:kind,bug',
                Rule::in(['low', 'medium', 'high', 'critical']),
            ],
            'message' => ['required', 'string', 'min:1', 'max:5000'],
            'page_url' => ['nullable', 'url:http,https', 'max:2048'],
            'user_agent' => ['nullable', 'string', 'max:1024'],
            'viewport' => ['nullable', 'string', 'regex:/^\d{1,5}x\d{1,5}$/'],
            'app_version' => ['nullable', 'string', 'max:64'],
            'request_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'reply_email' => ['nullable', 'email:rfc', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['message', 'page_url', 'user_agent', 'viewport', 'app_version', 'request_id', 'reply_email']);
    }
}
