<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Client-assisted catalog re-warm for a connected client-mode brand: the
 * dashboard fetched the store's public Store API from the browser and posts
 * the raw products array. Deep validation + host pinning happen in
 * WooCommerceScraper::productsFromClient.
 */
class SubmitShopCatalogRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'products' => ['required', 'array', 'max:100'],
            'products.*' => ['array'],
        ];
    }
}
