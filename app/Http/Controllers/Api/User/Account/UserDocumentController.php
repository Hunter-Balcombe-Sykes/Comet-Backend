<?php

namespace App\Http\Controllers\Api\User\Account;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Documents\UpdateDocumentRequest;
use App\Http\Requests\Api\User\Documents\UploadDocumentRequest;
use App\Http\Resources\DocumentMediaResource;
use App\Models\Core\Site\SiteMedia;
use App\Support\Concerns\NormalisesOptionalString;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// V2: Per-site document CRUD (PDF/JPG/PNG, 1 per site). Flat-replace
// semantics — a second upload soft-deletes the existing row and deletes
// its R2 bytes synchronously before creating the new row.
class UserDocumentController extends ApiController
{
    use NormalisesOptionalString;
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // Return the document regardless of is_active so draft docs surface in
        // the dashboard — the frontend publish toggle flips is_active directly.
        $media = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DOCUMENTS)
            ->whereNull('deleted_at')
            ->first();

        return $this->success([
            'document' => $media ? (new DocumentMediaResource($media))->toArray(request()) : null,
        ]);
    }

    public function store(UploadDocumentRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // Double MIME-check via finfo — prevents Content-Type header spoofing
        // on top of the mimes: validation rule which trusts the client header.
        $file = $request->file('file');
        $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
        if (! in_array($actualMime, $allowed, true)) {
            return $this->error('Document bytes do not match an accepted file type.', 415);
        }

        $title = trim((string) $request->validated('title'));
        $caption = $this->normaliseOptionalString($request->validated('caption'));
        // basename() removes path traversal components; control-char strip (incl. CRLF)
        // prevents header injection if this value ever appears in Content-Disposition.
        $rawFilename = basename((string) $file->getClientOriginalName());
        $rawFilename = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $rawFilename);
        $originalFilename = substr($rawFilename, 0, 255);

        // Master Pattern 16 (DB-E#SCALE-1): the R2 PUT is performed OUTSIDE
        // the transaction so the Postgres connection slot + advisory lock are
        // not held across a 50–500ms Cloudflare round-trip.
        //
        // The transaction is the serialization point — it soft-deletes the
        // previous doc row and inserts the new row with path:''. The empty
        // path is the claim token: concurrent uploads for the same site see
        // the row and the advisory lock serializes them. The R2 PUT then runs
        // post-commit and the path is patched in.
        //
        // Extension is derived from the actual MIME — never from the
        // client-supplied filename (spoofable).
        $ext = match ($actualMime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        };

        $previousPath = null;
        $previousId = null;
        $media = DB::transaction(function () use ($site, $file, $actualMime, $title, $caption, $originalFilename, &$previousPath, &$previousId) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-documents:{$site->id}"]);
            }

            // Flat-replace targets any non-deleted doc (including drafts)
            // so uploading a new file always takes over the single slot.
            $existing = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DOCUMENTS)
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                // Capture path + id for post-commit cleanup (path) and for
                // restore-on-failure (id, see catch branch below).
                $previousPath = (string) $existing->path;
                $previousId = (string) $existing->id;
                // Suppress the old row's `deleted` observer event during
                // flat-replace — the new row's `saved` event a few lines
                // below will trigger section-visibility reevaluation once.
                // Without this, both events fire post-commit and do the
                // same DB read + check in sequence (wasted work).
                SiteMedia::withoutEvents(function () use ($existing): void {
                    $existing->delete();
                });
            }

            // Pick a sort_order that won't collide with other pools.
            // The unique constraint is site-wide (site_id, sort_order) —
            // not per-pool — so a gallery image at sort_order=0 would
            // block a document insert at the same position. Query the
            // max across all pools and take the next slot.
            $maxSort = SiteMedia::query()
                ->where('site_id', $site->id)
                ->whereNull('deleted_at')
                ->max('sort_order');
            $nextSort = is_null($maxSort) ? 0 : ((int) $maxSort + 1);

            return SiteMedia::create([
                'site_id' => $site->id,
                'pool' => SiteMedia::POOL_DOCUMENTS,
                'path' => '',
                'alt_text' => $title,
                'caption' => $caption,
                'sort_order' => $nextSort,
                'is_active' => true,
                'media_type' => SiteMedia::MEDIA_TYPE_DOCUMENT,
                'processing_state' => SiteMedia::PROCESSING_STATE_READY,
                'original_mime' => $actualMime,
                'original_filename' => $originalFilename,
                'original_size_bytes' => $file->getSize(),
            ]);
        });

        // Post-commit: stream the bytes to R2 (the lock and connection slot
        // have already released). If this throws, delete the empty-path row
        // we just inserted so the slot doesn't stay claimed by a phantom.
        $mediaDisk = config('partna.media_disk');
        $path = "documents/{$pro->id}/{$media->id}/original.{$ext}";

        try {
            $stream = fopen($file->getRealPath(), 'rb');
            Storage::disk($mediaDisk)->put($path, $stream, 'public');
            if (is_resource($stream)) {
                fclose($stream);
            }

            $media->update(['path' => $path]);
        } catch (\Throwable $e) {
            // Best-effort cleanup of any partial R2 upload, then drop the
            // empty-path row, then restore the prior soft-deleted doc.
            //
            // Pre-Pattern-16, a Cloudflare R2 failure rolled back the whole
            // transaction (including the soft-delete of the prior doc). With
            // the PUT now outside the transaction, the soft-delete already
            // committed — so on failure we explicitly restore() the prior
            // row so flat-replace remains "atomic swap or no change". The
            // prior R2 bytes are still in place because we throw before the
            // post-commit cleanup block runs.
            try {
                Storage::disk($mediaDisk)->delete($path);
            } catch (\Throwable $cleanupError) {
                Log::warning('Failed to clean up orphaned document R2 object after upload failure', [
                    'path' => $path,
                    'error' => $cleanupError->getMessage(),
                ]);
            }
            $media->forceDelete();
            if ($previousId !== null) {
                SiteMedia::withTrashed()
                    ->where('id', $previousId)
                    ->update(['deleted_at' => null]);
            }
            throw $e;
        }

        // Post-commit: delete old R2 bytes. Safe to run outside the txn because
        // the old row is already soft-deleted (so no reader will try to fetch
        // the old path), and if this delete fails we just leak bytes — not a
        // correctness issue.
        if ($previousPath !== null && $previousPath !== '') {
            try {
                Storage::disk(config('partna.media_disk'))->delete($previousPath);
            } catch (\Throwable $e) {
                Log::warning('Failed to delete previous document R2 object after commit', [
                    'path' => $previousPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->success(['document' => (new DocumentMediaResource($media))->toArray(request())], 201);
    }

    /**
     * Edit document title and/or caption. isDirty-guarded so no-op PATCHes
     * don't churn the public-site cache.
     */
    public function update(UpdateDocumentRequest $request, SiteMedia $document): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // Pool check: only operate on document-pool media (not gallery, etc).
        // Route model binding already 404s on soft-deleted rows.
        abort_unless($document->pool === SiteMedia::POOL_DOCUMENTS, 404);
        // Ownership via SitePolicy — must setRelation so the policy resolves through
        // the already-loaded site instead of lazy-loading.
        $document->setRelation('site', $site);
        $this->authorizeForUser($pro, 'update', $document);

        $data = $request->validated();
        $update = [];

        if (array_key_exists('title', $data)) {
            $update['alt_text'] = $this->normaliseOptionalString($data['title']);
        }

        if (array_key_exists('caption', $data)) {
            $update['caption'] = $this->normaliseOptionalString($data['caption']);
        }

        // is_enabled maps to is_active — the publish toggle flips this directly.
        if (array_key_exists('is_enabled', $data)) {
            $update['is_active'] = (bool) $data['is_enabled'];
        }

        if (! empty($update)) {
            $document->fill($update);
            if ($document->isDirty(['alt_text', 'caption', 'is_active'])) {
                $document->save();
            }
        }

        return $this->success(['document' => (new DocumentMediaResource($document->fresh()))->toArray(request())]);
    }

    /**
     * Soft-delete the row and synchronously delete the R2 bytes (no
     * versioning, so there's no archival value in keeping bytes around).
     */
    public function destroy(Request $request, SiteMedia $document): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // Pool check: only operate on document-pool media.
        abort_unless($document->pool === SiteMedia::POOL_DOCUMENTS, 404);
        $document->setRelation('site', $site);
        $this->authorizeForUser($pro, 'delete', $document);

        try {
            Storage::disk(config('partna.media_disk'))->delete((string) $document->path);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete document R2 object on destroy', [
                'media_id' => $document->id,
                'path' => $document->path,
                'error' => $e->getMessage(),
            ]);
        }

        $document->delete();

        return $this->success(['deleted' => true]);
    }
}
