<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class SetShopProductsRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'productIds' => ['present', 'array', 'max:250'],
            'productIds.*' => ['string', 'max:50'],
        ];
    }
}
