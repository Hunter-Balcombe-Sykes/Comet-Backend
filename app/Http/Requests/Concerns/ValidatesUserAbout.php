<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

// V2: Shared validation + normalization for the professional "about" payload
// (credentials + experience). Used by UpdateUserRequest (self-serve)
// and StaffUpdateUserRequest so both endpoints enforce identical shape.
trait ValidatesUserAbout
{
    /**
     * Merge these into the rules() array of the consuming Form Request.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function aboutRules(): array
    {
        $currentYear = (int) date('Y');

        return [
            'about' => ['sometimes', 'array'],
            'about.credentials' => ['sometimes', 'array', 'max:5'],
            'about.credentials.*' => ['array:title,issuer,year'],
            'about.credentials.*.title' => ['required', 'string', 'max:120'],
            'about.credentials.*.issuer' => ['sometimes', 'nullable', 'string', 'max:120'],
            'about.credentials.*.year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:'.($currentYear + 1)],

            'about.experience' => ['sometimes', 'array', 'max:5'],
            'about.experience.*' => ['array:role,place,start,end,description'],
            'about.experience.*.role' => ['required', 'string', 'max:120'],
            'about.experience.*.place' => ['sometimes', 'nullable', 'string', 'max:120'],
            'about.experience.*.start' => ['sometimes', 'nullable', 'string', 'date_format:Y-m'],
            'about.experience.*.end' => ['sometimes', 'nullable', 'string', 'date_format:Y-m'],
            'about.experience.*.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Call from the Form Request's prepareForValidation() before any other merges.
     * Strips HTML from free-text fields and coerces empty strings to null so the
     * JSONB payload stays clean.
     */
    protected function normalizeAboutPayload(): void
    {
        $about = $this->input('about');
        if (! is_array($about)) {
            return;
        }

        if (isset($about['credentials']) && is_array($about['credentials'])) {
            foreach ($about['credentials'] as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $about['credentials'][$i]['title'] = $this->cleanStringOrNull($row['title'] ?? null);
                $about['credentials'][$i]['issuer'] = $this->cleanStringOrNull($row['issuer'] ?? null);
                // year is left as-is; validator coerces / rejects
            }
        }

        if (isset($about['experience']) && is_array($about['experience'])) {
            foreach ($about['experience'] as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $about['experience'][$i]['role'] = $this->cleanStringOrNull($row['role'] ?? null);
                $about['experience'][$i]['place'] = $this->cleanStringOrNull($row['place'] ?? null);
                $about['experience'][$i]['description'] = $this->cleanStringOrNull($row['description'] ?? null);
                // start / end are kept as supplied; validator enforces Y-m format
                if (($about['experience'][$i]['start'] ?? null) === '') {
                    $about['experience'][$i]['start'] = null;
                }
                if (($about['experience'][$i]['end'] ?? null) === '') {
                    $about['experience'][$i]['end'] = null;
                }
            }
        }

        $this->merge(['about' => $about]);
    }

    /**
     * Cross-field rule: each experience entry's `end` must be >= `start` when both set.
     * Call from withValidator() in the Form Request.
     */
    protected function validateExperienceDateOrder(Validator $validator): void
    {
        $experience = (array) data_get($this->all(), 'about.experience', []);
        foreach ($experience as $i => $row) {
            $start = $row['start'] ?? null;
            $end = $row['end'] ?? null;
            if (is_string($start) && is_string($end) && $end < $start) {
                $validator->errors()->add(
                    "about.experience.$i.end",
                    'The end month must be on or after the start month.'
                );
            }
        }
    }

    /**
     * Delegates to BaseFormRequest::cleanString — kept here as a thin alias so
     * the trait's nested-walks (credentials.*, experience.*) can stay readable.
     * The trait is only ever used by classes that extend BaseFormRequest, so
     * static::cleanString resolves through the consuming class.
     */
    private function cleanStringOrNull(mixed $value): ?string
    {
        return static::cleanString(is_string($value) ? $value : null);
    }
}
