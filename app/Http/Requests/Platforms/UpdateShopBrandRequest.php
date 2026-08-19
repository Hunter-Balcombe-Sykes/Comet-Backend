<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShopBrandRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // All optional — PATCH applies only what's present. discountCode
            // was 'present' when it was the endpoint's only field; 'sometimes'
            // keeps existing dashboard callers valid while allowing
            // settings-only updates.
            'discountCode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'selectionMode' => ['sometimes', 'string', 'in:manual,latest'],
            // Per-store auto-latest (2026-08-17, Sell opt-in): whether THIS
            // store's newest product publishes automatically. Distinct from
            // the site-wide /shop/settings autoLatest, which writes every
            // store at once.
            'autoLatest' => ['sometimes', 'boolean'],
            // Full URL or bare query — the controller parses out the query
            // suffix; empty/null clears the stored referral.
            'referralUrl' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
