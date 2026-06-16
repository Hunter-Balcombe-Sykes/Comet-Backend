<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

// OpenTable connect input. Only shape-validated here — the opentable-host and
// restaurant-id checks live in the controller so they return friendly,
// actionable 422 messages (mirrors DeezerController).
class ConnectOpenTableRequest extends FormRequest
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
