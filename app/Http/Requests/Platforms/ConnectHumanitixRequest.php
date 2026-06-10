<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectHumanitixRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A Humanitix host page or any event page (host resolved server-side).
            'url' => ['required', 'string', 'max:500', 'regex:~^https?://(?:events\.)?humanitix\.com/~i'],
        ];
    }
}
