<?php

namespace App\Http\Requests\Api\User\Content;

use App\Http\Requests\BaseFormRequest;

// Validates the Google-photos content-inclusion toggle body: { enabled: bool }.
class SetContentGooglePhotosRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
