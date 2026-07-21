<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// OV-A: public marketing early-access form. type partna|business; the person
// names the 2–3 platforms they already use (free text — matched to registry
// keys later by the invite flow, not validated against it here so the form
// never fights the visitor). `website` is the honeypot; form_started_at_ms
// powers the timing check — both mirror PublicWaitlistSignupRequest.
class PublicEarlyAccessSignupRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStrings(['email', 'workplace_or_industry']);
        $this->lowercaseStrings(['email']);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:320'],
            'type' => ['required', 'string', Rule::in(['partna', 'business'])],
            'workplace_or_industry' => ['nullable', 'string', 'max:160'],
            'platforms' => ['required', 'array', 'min:2', 'max:3'],
            'platforms.*' => ['string', 'max:120'],

            // Source-first build: which existing platform machinery generates
            // the dark site (registry key) + the raw ref (handle/place id) it
            // scrapes. Required — a resolvable source is how the visitor gets
            // a site to later claim.
            'source_type' => ['required', 'string', Rule::in(array_keys(config('partna.pre_account.generators', [])))],
            'source_ref' => ['required', 'string', 'max:300'],

            // Bot protection (never surfaced in UI copy).
            'website' => ['nullable', 'string', 'max:255'],
            'form_started_at_ms' => ['nullable', 'integer'],
        ];
    }
}
