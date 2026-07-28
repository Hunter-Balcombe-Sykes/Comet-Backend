<?php

namespace App\Http\Requests\Api\User\Design;

use App\Services\Design\DesignKitAutopilot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Restyle from brand" apply (plan §13). The user chooses WHICH proposed
 * columns to take — the diff is a menu, not a bundle — so the payload is the
 * chosen column list, validated against the closed set autopilot may write.
 */
class ApplyRestyleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Ownership enforced by the policy in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['string', Rule::in(DesignKitAutopilot::WRITABLE)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'columns.*.in' => 'That is not an area a brand restyle can change.',
        ];
    }
}
