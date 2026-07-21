<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

// POST /api/platforms/menu/scan/apply — the FE10 contract: a batch of
// AI-extracted items from a user-uploaded menu photo/PDF. price/description/
// category are all optional per item (a scan doesn't always find every
// field); only name is required. Bounded to 200 items per request — this is
// a manual per-upload action, not a bulk import tool.
class ApplyMenuScanRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:200'],
            // required() already fails on a whitespace-only string (Laravel
            // trims before checking), so no separate non-empty rule is needed.
            'items.*.name' => ['required', 'string', 'max:160'],
            'items.*.description' => ['nullable', 'string'],
            // Bounded like a real menu price — min:0 rejects a scan misread as
            // negative, max:100000 catches a decimal-point misread (e.g. $1400.00
            // scanned as 140000) without constraining any real-world price.
            'items.*.price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'items.*.category' => ['nullable', 'string'],
            // Dietary markers (GF/V/VG…) — MenuScanApplier normalizes against
            // its canonical label vocabulary and drops anything unknown, so a
            // loose string rule here is enough; without ANY rule, validated()
            // stripped the key and manual scans silently lost their badges.
            'items.*.dietary' => ['sometimes', 'nullable', 'array', 'max:7'],
            'items.*.dietary.*' => ['string', 'max:20'],
        ];
    }
}
