<?php

namespace App\Http\Requests\Api\Staff\UserSite;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// Staff/ManyChat marketing build request — same source/account_type pairing
// rules as the public CreatePreAccountBuildRequest, plus the staff-only
// publish + expires_days knobs. authorize() is inherited final from
// BaseFormRequest; staff access is enforced by route middleware + the
// staffCreate policy call in the controller.
class StaffCreatePreAccountBuildRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'account_type' => ['required', 'string', Rule::in(array_keys(config('partna.pre_account.sources', [])))],
            'source_type' => ['required', 'string', Rule::in(array_keys(config('partna.pre_account.generators', [])))],
            'source_ref' => ['required', 'string', 'max:300'],
            // F1: a GBP place_id is opaque — the picker-known business name seeds
            // the subdomain/handle/display name.
            'source_name' => ['nullable', 'string', 'max:120', 'required_if:source_type,google_business'],
            'publish' => ['sometimes', 'boolean'],
            'expires_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'contact_email' => ['nullable', 'email:rfc', 'max:320'],
        ];
    }
}
