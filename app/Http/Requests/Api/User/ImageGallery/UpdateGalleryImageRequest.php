<?php

namespace App\Http\Requests\Api\User\ImageGallery;

use App\Http\Requests\BaseFormRequest;

// V2: Validates per-image metadata edits (caption, alt_text) on a gallery image.
class UpdateGalleryImageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'caption' => ['sometimes', 'nullable', 'string', 'max:200'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
