<?php

namespace App\Http\Requests\Api\User\Uploads;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\AcceptsLegacyPoolField;
use Illuminate\Validation\Rule;

// V2: Validates media reordering — usage (content), optional media type filter, and distinct UUID array.
// Accepts the legacy `pool` spelling of `usage` (see AcceptsLegacyPoolField).
class ReorderPoolImagesRequest extends BaseFormRequest
{
    use AcceptsLegacyPoolField;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'usage' => [
                'required',
                'string',
                Rule::in(config('partna.upload_usages')),
            ],
            'media_type' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['image', 'video']),
            ],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->foldLegacyPoolField();

        if (is_string($this->media_type ?? null)) {
            $this->merge(['media_type' => strtolower(trim($this->media_type))]);
        }
    }
}
