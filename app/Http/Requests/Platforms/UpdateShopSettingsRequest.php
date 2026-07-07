<?php

namespace App\Http\Requests\Platforms;

use App\Models\Core\Site\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validates the GLOBAL shop link controls (2026-07-08) written to site.sites
// via ShopController::updateSettings. Both fields optional — PATCH applies only
// what's present. linkMode mirrors Site::SHOP_LINK_MODES (checkout|product);
// autoLatest is a plain boolean. No CHECK constraint backs these (SQLite-test-
// mirror convention), so the request is the sole gate on the stored values.
class UpdateShopSettingsRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint / policy, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'linkMode' => ['sometimes', 'string', Rule::in(Site::SHOP_LINK_MODES)],
            'autoLatest' => ['sometimes', 'boolean'],
        ];
    }
}
