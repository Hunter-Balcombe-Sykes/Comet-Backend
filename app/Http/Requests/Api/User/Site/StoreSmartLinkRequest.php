<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use App\Services\SmartLinks\SmartLinkTypeRegistry;
use Illuminate\Validation\Rule;

// Validates creating a smart link. A discount code applies only where it can
// auto-apply on the visitor click (Shopify / Eventbrite) — enforced in the
// controller; here it's just bounded.
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
        ];
    }
}
