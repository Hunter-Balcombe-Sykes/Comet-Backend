<?php

namespace App\Http\Requests\Api\Professional\Site;

use App\Http\Requests\BaseFormRequest;

class ReclaimHandleRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'handle' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9_-]+$/i'],
        ];
    }
}
