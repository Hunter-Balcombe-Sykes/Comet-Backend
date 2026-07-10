<?php

namespace App\Http\Requests\Api\Staff\FeatureAvailability;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// OV-A: upsert one availability rule — (feature_key, segment_id?) → mode.
// Key convention: integration.<platform> | feature.<name>.
class UpsertFeatureAvailabilityRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'feature_key' => ['required', 'string', 'max:120', 'regex:/^[a-z][a-z0-9._-]*$/'],
            'mode' => ['required', 'string', Rule::in(['enabled', 'disabled'])],
            'segment_id' => ['nullable', 'uuid', Rule::exists('pgsql.core.user_segments', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'feature_key.regex' => 'Keys are lowercase dot/dash/underscore identifiers, e.g. integration.instagram or feature.shop.',
        ];
    }
}
