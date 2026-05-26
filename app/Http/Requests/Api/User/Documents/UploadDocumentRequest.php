<?php

namespace App\Http\Requests\Api\User\Documents;

use App\Http\Requests\BaseFormRequest;

// V2: Validates document upload — PDF/JPG/PNG only, max 10 MB,
// required title (stored as alt_text on site_media), optional caption.
class UploadDocumentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:10240', // KB
            ],
            'title' => ['required', 'string', 'max:200'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Document must be a PDF, JPG, or PNG file.',
            'file.max' => 'Document must be smaller than 10 MB.',
        ];
    }
}
