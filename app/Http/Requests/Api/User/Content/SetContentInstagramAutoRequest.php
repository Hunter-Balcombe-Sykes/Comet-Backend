<?php

namespace App\Http\Requests\Api\User\Content;

use App\Http\Requests\BaseFormRequest;

// Validates the Instagram-auto toggle body: { enabled: bool }.
class SetContentInstagramAutoRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
