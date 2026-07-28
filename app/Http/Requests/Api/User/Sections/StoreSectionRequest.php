<?php

namespace App\Http\Requests\Api\User\Sections;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a section (plan §7).
 *
 * The rule is validated by {@see SectionRuleRules}: at most five predicates
 * over seven operators, because a bounded DSL is what lets the same rule be
 * rendered as an English sentence and explained per candidate in the trace. An
 * open query language could do neither.
 */
class StoreSectionRequest extends BaseFormRequest
{
    use SectionRuleRules;

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['label']);
        $this->lowercaseStrings(['key']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['collection', 'richtext', 'contact_form', 'newsletter', 'map', 'document', 'policy'])],
            'key' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'slot' => ['sometimes', Rule::in(['body', 'header_actions', 'footer'])],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'rule' => ['sometimes', 'array', $this->boundedRuleRule()],
            'mode' => ['sometimes', Rule::in(['hand_picked', 'automatic', 'mixed'])],
            'order_by' => ['sometimes', Rule::in(self::ORDER_BY)],
            'limit_n' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'group_by' => ['sometimes', 'nullable', Rule::in(['category', 'source', 'month', 'letter'])],
            'render' => ['sometimes', Rule::in(['cards', 'rows', 'tiles', 'buttons', 'carousel'])],
            'density' => ['sometimes', 'nullable', 'string', 'max:20'],
            'price_mode' => ['sometimes', 'nullable', 'string', 'max:20'],
            'min_items' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'on_empty' => ['sometimes', Rule::in(['hide', 'show_message', 'show_anyway'])],
            'stale_display' => ['sometimes', Rule::in(['inherit', 'show', 'hide'])],
        ];
    }
}
