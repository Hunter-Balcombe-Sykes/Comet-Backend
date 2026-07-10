<?php

namespace App\Http\Requests\Api\Staff\Segments;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// OV-A: manual member add/remove — bounded batch of existing user ids.
class SegmentMembersRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['uuid', Rule::exists('pgsql.core.users', 'id')],
        ];
    }
}
