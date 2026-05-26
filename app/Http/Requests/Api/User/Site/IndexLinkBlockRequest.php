<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;

// V2: Authorizes the link block listing endpoint — no input validation rules required.
class IndexLinkBlockRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [];
    }
}
