<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// Public, unauthenticated pre-account (site-first signup) build request.
// authorize() is inherited final from BaseFormRequest (always true) — abuse
// is handled by the pre-account-build throttle + PreAccountBuildService's
// per-IP outstanding-build cap, not request-level authorization.
class CreatePreAccountBuildRequest extends BaseFormRequest
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
        ];
    }
}
