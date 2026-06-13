<?php

namespace App\Http\Requests\Platforms;

use App\Services\Platforms\PlatformInput;
use Illuminate\Foundation\Http\FormRequest;

// Add a single product by URL (the "add individual product" flow), not tied to
// a connected store. The product page is scraped server-side (JSON-LD →
// OpenGraph) by GenericShopScraper::fetchSingleProduct.
class AddShopProductRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    // Scheme-less pastes ("mystore.com/product") normalise before `url` runs.
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
        ];
    }
}
