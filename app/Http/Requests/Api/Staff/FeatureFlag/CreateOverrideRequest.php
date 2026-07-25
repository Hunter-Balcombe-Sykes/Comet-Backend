<?php

namespace App\Http\Requests\Api\Staff\FeatureFlag;

use Illuminate\Foundation\Http\FormRequest;

class CreateOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `pgsql.` prefix is load-bearing: Validator::parseTable() splits the
            // FIRST dot off as a CONNECTION name, so `exists:core.users,id` meant
            // connection "core" (unconfigured) and threw on every real request.
            'user_id' => ['required', 'uuid', 'exists:pgsql.core.users,id'],
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
