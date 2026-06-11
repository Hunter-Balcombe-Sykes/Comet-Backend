<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectPodcastRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A feed URL or any show page that autodiscovers one.
            'url' => ['required', 'string', 'max:500', 'url:http,https'],
        ];
    }
}
