<?php

namespace App\Http\Requests\Api\Staff\Segments;

// OV-A: same field rules as create, but name becomes optional (PATCH semantics).
class UpdateSegmentRequest extends StoreSegmentRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
        ]);
    }
}
