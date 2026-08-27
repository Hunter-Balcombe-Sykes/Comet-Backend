<?php

namespace App\Http\Requests\Api\User\Content;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\SniffsFileMimeType;
use Illuminate\Contracts\Validation\Validator;

// Validates a manual content-library upload — an IMAGE or a VIDEO, one of
// the two (plan 04 step E, 2026-08-27 — was images-only; the "videos come
// from Instagram reels, never manual upload" era ended when the media pool
// grew its own upload door; same one-of shape as UploadImageRequest). The
// pool is fixed to 'content' by the controller, not the client.
class UploadContentImageRequest extends BaseFormRequest
{
    use SniffsFileMimeType;

    public function rules(): array
    {
        $imageMaxKb = (int) config('partna.image_max_upload_size', 10240);
        $videoMaxKb = (int) config('partna.video_max_upload_size', 512000);

        return [
            'image' => [
                'sometimes',
                'nullable',
                'file',
                'image',
                'mimes:jpeg,png,webp',
                "max:{$imageMaxKb}",
            ],
            'video' => [
                'sometimes',
                'nullable',
                'file',
                'mimes:mp4,mov,webm',
                "max:{$videoMaxKb}",
            ],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $hasImage = $this->hasFile('image');
            $hasVideo = $this->hasFile('video');

            if ($hasImage && $hasVideo) {
                $v->errors()->add('image', 'Provide either an image or a video, not both.');

                return;
            }
            if (! $hasImage && ! $hasVideo) {
                $v->errors()->add('image', 'An image or a video is required.');

                return;
            }
            if ($hasImage) {
                $this->assertImageMimeBytes($this->file('image'), $v, 'image');
            }
        });
    }
}
