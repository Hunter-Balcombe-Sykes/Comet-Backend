<?php

namespace App\Http\Requests\Api\Staff\EarlyAccess;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * OV-A: staff invite send — bulk/single, two input shapes usable together:
 *   ids[]     — existing waitlist/invited rows to (re-)invite
 *   entries[] — manual invitees created (or updated) on the fly; each may carry
 *               integrations prefills ({instagram, other[{platform,url}]}) and
 *               an architecture hint, stored on invite_meta for the signup flow.
 */
class StaffEarlyAccessInviteRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required_without:entries', 'array', 'max:200'],
            'ids.*' => ['uuid', Rule::exists('pgsql.core.early_access_signups', 'id')],

            'entries' => ['required_without:ids', 'array', 'max:50'],
            'entries.*.email' => ['required', 'email:rfc', 'max:320'],
            'entries.*.type' => ['required', 'string', Rule::in(['partna', 'business'])],
            'entries.*.workplace_or_industry' => ['nullable', 'string', 'max:160'],
            'entries.*.platforms' => ['nullable', 'array', 'max:10'],
            'entries.*.platforms.*' => ['string', 'max:120'],
            'entries.*.integrations' => ['nullable', 'array'],
            'entries.*.integrations.instagram' => ['nullable', 'string', 'max:255'],
            'entries.*.integrations.other' => ['nullable', 'array', 'max:10'],
            'entries.*.integrations.other.*.platform' => ['required_with:entries.*.integrations.other', 'string', 'max:60'],
            'entries.*.integrations.other.*.url' => ['required_with:entries.*.integrations.other', 'string', 'max:2048'],
            'entries.*.architecture' => ['nullable', 'array'],
        ];
    }
}
