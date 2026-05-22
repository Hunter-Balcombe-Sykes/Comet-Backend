<?php

namespace App\Http\Requests\Api\Staff;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// Validates POST /staff/commissions/adjust (LEDGER-1).
//
// Contract notes:
//  - {reason} requires >= 20 chars to force a meaningful audit trail — drive-by
//    "fix" notes are explicitly disallowed (callers must explain what / why).
//  - {amount_cents} is signed (positive = credit affiliate, negative = clawback)
//    and must be non-zero; the service enforces non-zero again as a second guard.
//  - {reference} is the idempotency token; uniqueness is enforced at the DB
//    layer via commission_movements.idempotency_key.
class PostCommissionAdjustmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'brand_professional_id' => ['required', 'uuid', 'exists:core.users,id'],
            'affiliate_professional_id' => ['required', 'uuid', 'different:brand_professional_id', 'exists:core.users,id'],
            'amount_cents' => ['required', 'integer', 'not_in:0', 'between:-100000000,100000000'],
            'currency_code' => ['sometimes', 'string', 'size:3', Rule::in(['AUD'])],
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
            'reference' => ['required', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Reason must be at least 20 characters — explain what was mis-attributed and why.',
            'affiliate_professional_id.different' => 'Brand and affiliate must be different professionals.',
            'amount_cents.not_in' => 'Adjustment amount must be non-zero.',
        ];
    }
}
