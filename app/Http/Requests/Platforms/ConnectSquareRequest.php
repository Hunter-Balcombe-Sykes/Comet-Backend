<?php

namespace App\Http\Requests\Platforms;

use App\Services\Platforms\PlatformInput;
use Illuminate\Foundation\Http\FormRequest;

// The user pastes their public Square Appointments booking link. We only store
// it — the public site renders a "Book now" button that opens it. No scraping.
class ConnectSquareRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    // Scheme-less pastes ("book.squareup.com/…") are normalised before the regex.
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('url'))) {
            $this->merge(['url' => PlatformInput::urlish((string) $this->input('url'))]);
        }
    }

    public function rules(): array
    {
        return [
            // Any Square-owned host: book.squareup.com, squareup.com, *.square.site.
            'url' => ['required', 'string', 'max:1000', 'regex:#^https?://([a-z0-9-]+\.)*(squareup\.com|square\.site)(/[^\s]*)?$#i'],
        ];
    }

    public function messages(): array
    {
        return [
            'url.regex' => 'Enter a valid Square booking link (a squareup.com or square.site URL).',
        ];
    }
}
