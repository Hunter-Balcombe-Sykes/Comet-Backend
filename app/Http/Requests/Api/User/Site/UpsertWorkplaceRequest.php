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
            'name', 'address', 'phone', 'website',
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
