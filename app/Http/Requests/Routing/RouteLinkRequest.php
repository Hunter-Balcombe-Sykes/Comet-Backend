<?php

namespace App\Http\Requests\Routing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deliberately permissive on shape: the canonicaliser is what decides whether
 * a string is a usable link, and it accepts far more than a `url` rule would
 * (missing scheme, wrapping punctuation, a link inside a sentence — plan §2
 * robustness). Validating with `url` here would reject inputs the router can
 * handle perfectly well, so this only bounds the size.
 */
class RouteLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'min:3', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'url.required' => 'Enter a link.',
            'url.max' => 'That link is too long.',
        ];
    }
}
