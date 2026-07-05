<?php

namespace App\Http\Requests\Api\User\Content;

use App\Http\Requests\BaseFormRequest;

// Validates a manual content-library image upload. Images only — the content
// pool accepts JPEG/PNG/WebP (videos come from Instagram reels, never manual
// upload). The pool is fixed to 'content' by the controller, not the client.
class UploadContentImageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $imageMaxKb = (int) config('partna.image_max_upload_size', 10240);

        return [
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,png,webp',
                "max:{$imageMaxKb}",
            ],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}
