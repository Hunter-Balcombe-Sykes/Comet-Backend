<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// V2: Public document download. 302-redirects to an R2 presigned URL with
// a response-content-disposition=attachment override so the browser forces
// a download with the original filename instead of rendering inline.
class PublicDocumentDownloadController extends ApiController
{
    public function __invoke(SiteMedia $document, Request $request): RedirectResponse
    {
        // Only serve rows that are active, not soft-deleted, and actually
        // part of the documents pool — otherwise 404 without leaking details.
        abort_unless(
            $document->usage === SiteMedia::USAGE_DOCUMENTS
            && $document->is_active
            && $document->deleted_at === null,
            404
        );

        $site = Site::query()->find($document->site_id);
        abort_unless($site && $site->is_published, 404);

        // Enforce subdomain isolation when the caller provides an X-Site-Subdomain
        // header (all public-site frontend requests do). Absent header = unenforced
        // (used only by internal/test callers that bypass routing).
        $requestedSubdomain = trim((string) $request->header('X-Site-Subdomain', ''));
        if ($requestedSubdomain !== '') {
            abort_unless(
                strtolower($site->subdomain) === strtolower($requestedSubdomain),
                404
            );
        }

        $mediaDisk = config('partna.media_disk');
        $filename = $document->original_filename ?: 'document';

        $presignedUrl = Storage::disk($mediaDisk)->temporaryUrl(
            (string) $document->path,
            now()->addMinutes(5),
            [
                'ResponseContentDisposition' => $this->buildContentDisposition($filename),
            ]
        );

        return redirect()->away($presignedUrl);
    }

    /**
     * Build a Content-Disposition value with an RFC 5987 filename* parameter
     * (handles non-ASCII / Unicode) plus a quoted ASCII fallback for older clients.
     *
     * The RFC 5987 value percent-encodes every byte outside the attr-char set so
     * CRLF and other control characters cannot inject headers, and multi-byte UTF-8
     * filenames are preserved faithfully.
     */
    private function buildContentDisposition(string $name): string
    {
        // ASCII fallback: map non-printable and non-ASCII bytes to underscores.
        // Also replace " and \ which would break the quoted-string token.
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '_', $name);
        $ascii = str_replace(['"', '\\'], '_', $ascii);
        $ascii = trim($ascii) !== '' ? trim($ascii) : 'document';

        // RFC 5987: percent-encode every byte not in the attr-char set.
        // PCRE processes UTF-8 bytes individually here (no u flag), so rawurlencode()
        // on each matched byte produces the correct multi-byte percent sequences.
        $rfc5987 = (string) preg_replace_callback(
            '/[^A-Za-z0-9!#$&+\-.^_`|~]/',
            static fn (array $m): string => rawurlencode($m[0]),
            $name
        );

        return "attachment; filename=\"{$ascii}\"; filename*=UTF-8''{$rfc5987}";
    }
}
