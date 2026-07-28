<?php

namespace App\Http\Requests\Api\User\Sections;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Pin or exclude one item in one section (plan §7).
 *
 * `pinned` and `excluded` are the ONLY two verbs, and `sort_key` is fractional
 * so dropping an item between two neighbours never rewrites the rest of the
 * list — a reorder that touches one row cannot lose a concurrent reorder of
 * another.
 */
class UpsertSectionItemRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'state' => ['required', Rule::in(['pinned', 'excluded'])],
            // Only meaningful for a pin: an exclusion has no position.
            'sort_key' => ['sometimes', 'nullable', 'numeric'],
        ];
    }
}
