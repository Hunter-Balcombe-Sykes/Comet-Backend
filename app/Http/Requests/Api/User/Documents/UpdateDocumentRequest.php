<?php

namespace App\Http\Requests\Api\User\Documents;

use App\Http\Requests\BaseFormRequest;

// V2: Validates document metadata edits (title, caption).
// Does NOT accept a file — file replacement goes through POST /api/documents.
class UpdateDocumentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:200'],
            // Publish toggle — maps to is_active on site_media.
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
