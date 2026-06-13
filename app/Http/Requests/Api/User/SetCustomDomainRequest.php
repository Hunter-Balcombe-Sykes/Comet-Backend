<?php

namespace App\Http\Requests\Api\User;

use Illuminate\Foundation\Http\FormRequest;

// Validates a user-connected custom domain. Authorization is structural (the
// controller only ever touches the caller's own site).
class SetCustomDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // Accept a forgiving paste ("https://BookWith.me/") and reduce it to a bare,
    // lowercase FQDN before validation.
    protected function prepareForValidation(): void
    {
        $domain = strtolower(trim((string) $this->input('domain', '')));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;   // strip scheme
        $domain = preg_replace('#[/:?].*$#', '', $domain) ?? $domain;     // strip path / port / query
        $domain = rtrim($domain, '.');                                    // strip trailing dot

        $this->merge(['domain' => $domain]);
    }

    public function rules(): array
    {
        return [
            'domain' => [
                'required', 'string', 'max:253',
                // A bare FQDN: dot-separated [a-z0-9-] labels + a ≥2-char TLD.
                'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
                // partna.au hosts route through the subdomain system, not here.
                'not_regex:/partna\.au$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter a valid domain like yourname.com (no http://, no path).',
            'domain.not_regex' => 'partna.au domains are managed automatically — connect a domain you own.',
        ];
    }
}
