<?php

namespace App\Http\Requests\Staff;

use App\DTOs\Moderation\DecisionDto;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a staff decision on a moderation case.
 *
 * CSAM override requires a second approver who must differ from the deciding staff member.
 * The `second_staff_approval_id` field is required for override_csam_auto_action and
 * validated against the deciding staff's ID via withValidator() (not the `different:` rule,
 * because $this->user() is null in the Supabase JWT context).
 */
class DecideOnCaseRequest extends FormRequest
{
    public const ALLOWED = [
        'dismiss', 'warn', 'hide_content', 'hide_site',
        'suspend_user', 'ban_user', 'override_csam_auto_action',
        'escalate_law_enforcement', 'escalate_esafety',
    ];

    public function rules(): array
    {
        return [
            'decision_type'            => ['required', 'string', 'in:' . implode(',', self::ALLOWED)],
            'reason'                   => ['required', 'string', 'min:10', 'max:2000'],
            'second_staff_approval_id' => [
                'required_if:decision_type,override_csam_auto_action',
                'nullable',
                'uuid',
            ],
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $approverId = $this->input('second_staff_approval_id');
            if (!$approverId) {
                return;
            }

            // Ensure the second approver is a real staff member.
            $exists = \App\Models\Core\Staff\PartnaStaff::query()->where('id', $approverId)->exists();
            if (!$exists) {
                $v->errors()->add('second_staff_approval_id', 'The second approver must be a valid staff member.');
                return;
            }

            // Two-staff rule: the second approver must differ from the deciding staff.
            $staff = $this->attributes->get('partna_staff');
            if ($staff && $approverId === $staff->id) {
                $v->errors()->add('second_staff_approval_id', 'The second approver must differ from the deciding staff member.');
            }
        });
    }

    public function toDto(): DecisionDto
    {
        return new DecisionDto(
            decisionType:          $this->string('decision_type')->toString(),
            reason:                $this->string('reason')->toString(),
            secondStaffApprovalId: $this->input('second_staff_approval_id'),
        );
    }
}
