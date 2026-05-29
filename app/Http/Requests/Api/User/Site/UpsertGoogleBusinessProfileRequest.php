<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;

// V2: Validates Google Business Profile upsert — place ID, name, address, coordinates, phone, website.
//
// Two entry paths share this request:
//   1. Google Places autofill — visitor picks a result; we get a place_id + name + full structured data.
//   2. Manual entry          — visitor types the workplace by hand; no place_id, just a name (and
//                              whatever address/contact details they provide). place_id is nullable
//                              for this case; name remains the minimum to identify the workplace.
class UpsertGoogleBusinessProfileRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach ([
            'place_id', 'name', 'address', 'phone', 'website',
            // Structured address components — populated either by Google
            // Places (parseAddressParts in /api/google/places/search) or
            // by manual entry in the workplace card.
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
            // Nullable so manual entries (no Google pick) can save. Still capped
            // at 255 so a Google ID never silently overflows the JSONB column.
            'place_id' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            // Structured fields — stored alongside the formatted `address`
            // so manual edits to a single component don't lose the whole
            // formatted string on round-trip.
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postcode' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', ...$this->phoneRule()],
            'website' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
