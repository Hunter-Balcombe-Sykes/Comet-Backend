<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

// Paste-a-URL body for the Tickets & Events smart-detect add endpoint. Auth is
// enforced by the route middleware (user.api + pending-deletion read-only).
class AddEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:500'],
        ];
    }
}
