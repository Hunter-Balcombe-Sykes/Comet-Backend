<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use App\Services\SmartLinks\SmartLinkTypeRegistry;
use Illuminate\Validation\Rule;

// Validates a smart-link preview request: a pasted URL + the dashboard
// selection (a commerce type, or a content platform). The resolver does the
// real "is this a valid X" check — we only bound the inputs here.
class PreviewSmartLinkRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $selections = array_merge(
            SmartLinkTypeRegistry::COMMERCE_SELECTIONS,
            SmartLinkTypeRegistry::CONTENT_PLATFORMS,
        );

        return [
            'url' => ['required', 'string', 'max:2048'],
            'selection' => ['required', 'string', Rule::in($selections)],
        ];
    }
}
