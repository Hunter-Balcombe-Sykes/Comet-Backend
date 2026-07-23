<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;

// Validates a workplace upsert. One shape covers both flows:
//   - Google Places autofill — visitor picks a result in the dashboard; the
//     name + structured address + phone + website all land in one go.
//   - Manual entry           — visitor types whatever fields they want by hand.
// The stored record is identical either way — there's no `source` flag.
class UpsertWorkplaceRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach ([
            'name', 'phone', 'website', 'contact_email',
            // Previous/old website (archive) + business category + editorial
            // description — also auto-filled from Google Business when empty.
            'previous_website', 'category', 'description',
            // Structured address components — populated either by Google
            // Places (parseAddressParts in /api/google/places/search) or
            // by manual entry in the workplace editor.
            'address_line1', 'city', 'state', 'postcode', 'country',
        ] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $value = trim($value);
                $trimmed[$key] = $value !== '' ? $value : null;
            }
        }

        $this->merge($trimmed);
    }

    public function rules(): array
    {
        return [
            // Business/workplace names cap at 15 chars (manual entry — rejected
            // outright; auto-adopted names are silently word-trimmed instead,
            // see App\Support\BusinessName::wordTrim).
            'name' => ['required', 'string', 'max:15'],
            // Structured fields are the only source of truth — no separate
            // freeform "display address" string is stored.
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postcode' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', ...$this->phoneRule()],
            'website' => ['nullable', 'url', 'max:2048'],
            'previous_website' => ['nullable', 'url', 'max:2048'],
            'category' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            // Public contact email — manual-only (Google Places never returns one).
            'contact_email' => ['nullable', 'email', 'max:255'],
            // Structured per-day opening hours. Keys are weekday slugs; each maps
            // to a list of {open,close} HHMM entries. Shape owned by the Brand Info
            // editor + the Google hours mapper — validated loosely here.
            'opening_hours' => ['nullable', 'array'],
        ];
    }
}
