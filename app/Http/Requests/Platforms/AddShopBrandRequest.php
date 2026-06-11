<?php

namespace App\Http\Requests\Platforms;

use App\Services\Platforms\PlatformInput;
use Illuminate\Foundation\Http\FormRequest;

class AddShopBrandRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    // Scheme-less pastes ("mystore.com.au") are normalised BEFORE the `url`
    // rule runs, so bare domains validate instead of bouncing with
    // "must be a valid URL".
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('url'))) {
            $this->merge(['url' => PlatformInput::urlish((string) $this->input('url'))]);
        }
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:500', 'url'],
            'discountCode' => ['sometimes', 'nullable', 'string', 'max:100'],
            // Client-assisted connect: when the server-side probe is blocked
            // (store WAFs that 403 datacenter IPs), the dashboard fetches the
            // store's own public WooCommerce Store API from the BROWSER (the
            // visitor's IP, CORS-open by WordPress default) and posts the raw
            // JSON here. Deep validation + host pinning happen in
            // WooCommerceScraper::productsFromClient.
            'storeApi' => ['sometimes', 'array'],
            'storeApi.root' => ['sometimes', 'nullable', 'array'],
            'storeApi.products' => ['required_with:storeApi', 'array', 'max:100'],
            'storeApi.products.*' => ['array'],
        ];
    }
}
