<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared connect request for the link-only social platforms (X, LinkedIn,
 * Threads, Reddit — same single-field shape as Facebook/TikTok). The field
 * accepts a bare handle or a full profile URL; each controller normalises.
 */
class ConnectSocialLinkRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:200'],
        ];
    }
}
