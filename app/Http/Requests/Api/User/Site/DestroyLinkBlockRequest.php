<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;

// V2: Validates link block deletion — extracts and validates the UUID from the route parameter.
class DestroyLinkBlockRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        // `SubstituteBindings` middleware runs before this FormRequest is
        // resolved, so `route('linkBlock')` may already be the bound Block
        // model — not the raw UUID string. Normalise both shapes to the
        // underlying key so the `uuid` rule gets a plain string.
        $param = $this->route('linkBlock') ?? $this->route('block');
        $routeId = is_object($param) && method_exists($param, 'getKey')
            ? (string) $param->getKey()
            : $param;

        $this->merge([
            'id' => $routeId,
        ]);
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
        ];
    }
}
