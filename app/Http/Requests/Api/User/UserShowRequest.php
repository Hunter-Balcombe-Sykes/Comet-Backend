<?php

namespace App\Http\Requests\Api\User;

use App\Http\Requests\BaseFormRequest;

// V2: Authorizes the professional show endpoint — no input validation rules required.
class UserShowRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [];
    }
}
