<?php

namespace App\Http\Requests\Api\User\Uploads;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\SniffsFileMimeType;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

// Validates a single design-layer singleton image upload (a brand logo or an
// integration cover). `purpose` must be one of the design singleton allowlist;
// the file is an image only (free ratio — no dimension constraints).
class UploadDesignMediaRequest extends BaseFormRequest
{
    use SniffsFileMimeType;

    public function rules(): array
    {
        $imageMaxKb = (int) config('partna.image_max_upload_size', 10240);

        return [
            'purpose' => ['required', 'string', Rule::in(SiteMedia::designSingletonPurposes())],
            'image' => ['required', 'file', 'image', 'mimes:jpeg,png,webp', "max:{$imageMaxKb}"],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->hasFile('image')) {
                $this->assertImageMimeBytes($this->file('image'), $v, 'image');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->purpose ?? null)) {
            $this->merge(['purpose' => strtolower(trim($this->purpose))]);
        }
    }

    public function messages(): array
    {
        $imageMaxMb = round(((int) config('partna.image_max_upload_size', 10240)) / 1024, 1);

        return [
            'purpose.in' => 'Invalid image slot.',
            'image.required' => 'An image file is required.',
            'image.max' => "Image must be smaller than {$imageMaxMb} MB.",
            'image.mimes' => 'Image must be JPEG, PNG, or WebP.',
            'image.image' => 'The file must be a valid image.',
        ];
    }
}
