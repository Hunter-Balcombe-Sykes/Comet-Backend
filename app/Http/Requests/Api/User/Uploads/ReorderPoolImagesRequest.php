<?php

namespace App\Http\Requests\Api\User\Uploads;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// V2: Validates media pool reordering — pool type (gallery/content), optional media type filter, and distinct UUID array.
class ReorderPoolImagesRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'pool' => [
                'required',
                'string',
                Rule::in(config('partna.upload_pools')),
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
        if (is_string($this->pool ?? null)) {
            $this->merge(['pool' => strtolower(trim($this->pool))]);
        }
        if (is_string($this->media_type ?? null)) {
            $this->merge(['media_type' => strtolower(trim($this->media_type))]);
        }
    }
}
