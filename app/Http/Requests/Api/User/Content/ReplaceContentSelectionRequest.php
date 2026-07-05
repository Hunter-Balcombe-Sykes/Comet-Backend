<?php

namespace App\Http\Requests\Api\User\Content;

use App\Http\Requests\BaseFormRequest;
use App\Models\Core\Site\ContentSelection;
use Illuminate\Validation\Rule;

// Validates a whole-selection replace: an ordered list of ≤15 entries. Per-entry
// shape (which of mediaId / ref is required) is validated in the service against
// the DB CHECK — here we bound the count, the type enum, and the field types.
// The service is the authority on ig-position placement + reference shape.
class ReplaceContentSelectionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'entries' => ['present', 'array', 'max:'.ContentSelection::MAX_POSITION],
            'entries.*.type' => ['required', 'string', Rule::in(ContentSelection::ALL_TYPES)],
            'entries.*.mediaId' => ['sometimes', 'nullable', 'uuid'],
            'entries.*.ref' => ['sometimes', 'nullable', 'string', 'max:512'],
        ];
    }
}
