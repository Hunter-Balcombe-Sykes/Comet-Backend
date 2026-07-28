<?php

namespace App\Http\Requests\Api\User\Sections;

use App\Http\Requests\BaseFormRequest;
use App\Services\Content\PageCapabilities;
use Illuminate\Validation\Rule;

/** Partial page update — clients send only what changed (plan §7). */
class UpdatePageRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStrings(['label', 'preset_key']);
        $this->lowercaseStrings(['key']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(PageCapabilities::reservedKeys())],
            'label' => ['sometimes', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'order_mode' => ['sometimes', Rule::in(['manual', 'auto'])],
            'is_hidden' => ['sometimes', 'boolean'],
            'capability' => ['sometimes', 'nullable', 'string', Rule::in(PageCapabilities::keys())],
            'preset_key' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'key.regex' => 'A page address may only use lowercase letters, numbers and hyphens.',
            'key.not_in' => 'That page address is reserved.',
            'capability.in' => 'That is not a page type this platform offers.',
        ];
    }
}
