<?php

namespace App\Http\Requests\Api\User\Feedback;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new feedback submission. Field caps mirror DB CHECK constraints
 * in 20260526210001_create_feedback_table.sql so a validation pass guarantees
 * the insert won't violate a constraint (`type`/`area`/`target` have no DB
 * CHECK — see 20260711153000_feedback_type_area_target.sql header for why —
 * so this FormRequest is their sole enforcement point).
 *
 * OV-D: `type` (error/good/bad_ui/idea) is the taxonomy the dashboard
 * feedback picker submits and is now REQUIRED; `area` (free-form
 * feature/page/tool string) is REQUIRED; `target` (structured companion to
 * `area`) is optional and size-capped. `kind`/`severity` are the legacy
 * taxonomy — `kind` flips from required to optional here (FeedbackService
 * derives a value from `type` when omitted, since core.feedback.kind stays
 * NOT NULL at the DB layer); `severity` keeps its original
 * required_if:kind,bug rule verbatim, so it only fires for a caller that
 * explicitly still sends kind=bug.
 *
 * Severity is required only when kind=bug; everything else accepts null.
 * request_id is treated as opaque correlation data — restricted character set
 * but no semantic validation (frontend chooses its own format).
 */
class SubmitFeedbackRequest extends BaseFormRequest
{
    /** Max encoded byte size for the optional `target` JSON blob. */
    private const TARGET_MAX_BYTES = 4096;

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['error', 'good', 'bad_ui', 'idea'])],
            'area' => ['required', 'string', 'min:1', 'max:120'],
            'target' => ['nullable', 'array', function ($attribute, $value, $fail): void {
                if (strlen(json_encode($value)) > self::TARGET_MAX_BYTES) {
                    $fail('The target field must not exceed '.self::TARGET_MAX_BYTES.' bytes when encoded as JSON.');
                }
            }],

            'kind' => ['nullable', Rule::in(['bug', 'idea', 'praise', 'question', 'other'])],
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
        $this->trimStrings(['area', 'message', 'page_url', 'user_agent', 'viewport', 'app_version', 'request_id', 'reply_email']);
    }
}
