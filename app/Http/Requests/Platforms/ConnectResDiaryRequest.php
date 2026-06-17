<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

// ResDiary connect input — shape-validated only; the host + widget checks live
// in the controller for friendly 422 messages (mirrors ConnectOpenTableRequest).
class ConnectResDiaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
        ];
    }
}
