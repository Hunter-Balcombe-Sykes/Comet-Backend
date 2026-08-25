<?php

namespace App\Http\Requests\Api\Internal;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// ManyChat marketing build. Source/account pairing rules are read from config,
// NOT hardcoded — CLAUDE.md's contract is "adding a source = one generator +
// config entry + CHECK migration", and a hardcoded in: list adds a fourth
// place to edit. Mirrors StaffCreatePreAccountBuildRequest.
class ManyChatBuildRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'account_type' => ['required', 'string', Rule::in(array_keys((array) config('partna.pre_account.sources', [])))],
            'source_type' => ['required', 'string', Rule::in(array_keys((array) config('partna.pre_account.generators', [])))],
            'source_ref' => ['required', 'string', 'max:300'],
            // A GBP place_id is opaque — the picker-known business name seeds
            // the subdomain/handle/display name. Same rule and same reason as
            // both sibling requests; dropping it seeds a handle from a raw
            // place ID.
            'source_name' => ['nullable', 'string', 'max:120', 'required_if:source_type,google_business'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            // Required: without it a lost response strands the build (spec §5.4).
            'idempotency_key' => ['required', 'string', 'max:191'],
        ];
    }
}
