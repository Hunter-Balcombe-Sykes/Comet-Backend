<?php

namespace App\Http\Requests\Platforms;

use App\Services\Platforms\PlatformInput;
use Illuminate\Foundation\Http\FormRequest;

class ConnectFreshaRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    // Scheme-less pastes ("fresha.com/a/…") are normalised before the regex
    // rule runs.
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('url'))) {
            $this->merge(['url' => PlatformInput::urlish((string) $this->input('url'))]);
        }
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:500', 'regex:#^https?://(www\.)?fresha\.com/(?:[a-z]{2,3}(-[a-z]{2})?/)?a/[a-z0-9-]+/?$#i'],
        ];
    }
}
