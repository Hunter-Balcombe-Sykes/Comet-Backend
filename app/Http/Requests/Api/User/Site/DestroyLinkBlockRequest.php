<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\LinkBlockRequestHelpers;

// V2: Validates link block deletion — extracts and validates the UUID from the route parameter.
class DestroyLinkBlockRequest extends BaseFormRequest
{
    use LinkBlockRequestHelpers;

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => $this->resolveRouteBlockId(),
        ]);
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
        ];
    }
}
