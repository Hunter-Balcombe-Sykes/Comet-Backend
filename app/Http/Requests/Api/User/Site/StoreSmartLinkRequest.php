<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use App\Services\SmartLinks\SmartLinkTypeRegistry;
use Illuminate\Validation\Rule;

// Validates creating a smart link. Discount fields apply only to
// product/event (enforced in the controller); here they're just bounded.
class StoreSmartLinkRequest extends BaseFormRequest
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
            'discount_code' => ['nullable', 'string', 'max:64'],
            'discount_kind' => ['nullable', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ];
    }
}
