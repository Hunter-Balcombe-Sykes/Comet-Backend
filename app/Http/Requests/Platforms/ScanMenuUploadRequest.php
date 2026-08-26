<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

// POST /api/platforms/menu/scan — one menu photo or PDF for AI extraction.
// `mimetypes` (not `mimes`) deliberately: it validates the CONTENT-sniffed
// type, so a disguised file (script renamed to .jpg) fails validation before
// any billed OCR call — same magic-byte posture as SniffsFileMimeType without
// needing the trait's two-pass shape for a single-field request.
class ScanMenuUploadRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = (int) config('partna.menu_scan_max_upload_size', 20480);

        return [
            'file' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                "max:{$maxKb}",
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Attach a menu photo or PDF.',
            'file.mimetypes' => "That file type isn't supported. Use a JPG, PNG, WebP or PDF.",
            'file.max' => 'That file is too big. The limit is '.round((int) config('partna.menu_scan_max_upload_size', 20480) / 1024).'MB.',
        ];
    }
}
