<?php

namespace App\Http\Requests\Api\Staff\Feedback;

use App\Http\Requests\BaseFormRequest;
use App\Models\Core\Feedback;
use Illuminate\Validation\Rule;

// Staff triage write: status is the ONLY editable field (2026-07-17 design —
// internal_notes/tags stay dormant until a staff-UI need appears).
class StaffFeedbackUpdateRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(Feedback::STATUSES)],
        ];
    }
}
