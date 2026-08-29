<?php

namespace App\Http\Requests\Platforms;

use App\Services\Media\ImagePixelBudget;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

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

    /**
     * Pixel-bomb guard (#W1-SEC-2): a ~33-byte PNG can declare a 20000x20000
     * header and pass both rules above, spending a billed OCR call on a
     * payload that will fail or hang. Runs only once mimetypes/max have
     * passed — before that the bytes are unbounded or not an image at all.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->has('file')) {
                return;
            }

            $file = $this->file('file');
            if (! $file instanceof UploadedFile) {
                return;
            }

            // PDFs carry no raster header; ImagePixelBudget::decodable()'s
            // allowlist is jpeg/png/webp, so safeToDecode() would refuse
            // every menu PDF. exceeds() alone is the check we want here —
            // decodable() is already discharged by the `mimetypes:` rule.
            if ($file->getMimeType() === 'application/pdf') {
                return;
            }

            if (ImagePixelBudget::exceeds((string) file_get_contents($file->getPathname()))) {
                $v->errors()->add('file', 'That image is too big to process. Use one under '
                    .round(ImagePixelBudget::maxPixels() / 1_000_000).' megapixels.');
            }
        });
    }
}
