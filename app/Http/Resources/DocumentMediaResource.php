<?php

namespace App\Http\Resources;

use App\Models\Core\Site\SiteMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// API resource for document-pool media items (PDF/JPG/PNG, pool='documents').
// Output is byte-identical to UserDocumentController::buildDocumentPayload().
//
// Field notes:
//   - `title`      → maps from alt_text (the editable display name for a document)
//   - `is_enabled` → maps from is_active (the publish toggle for the document)
//   - `preview_url` → Storage-signed URL for the stored path
//   - `download_url` → public download route (served via PublicDocumentController)
class DocumentMediaResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SiteMedia $media */
        $media = $this->resource;

        $mediaDisk = config('partna.media_disk');
        $previewUrl = Storage::disk($mediaDisk)->url((string) $media->path);

        return [
            'id' => $media->id,
            'title' => $media->alt_text,
            'caption' => $media->caption,
            // is_enabled maps to is_active — the publish toggle reads and writes this.
            'is_enabled' => (bool) $media->is_active,
            'original_mime' => $media->original_mime,
            'original_size_bytes' => $media->original_size_bytes,
            'original_filename' => $media->original_filename,
            'preview_url' => $previewUrl,
            'download_url' => '/api/public/documents/'.$media->id.'/download',
            'created_at' => $media->created_at?->toIso8601String(),
            'updated_at' => $media->updated_at?->toIso8601String(),
        ];
    }
}
