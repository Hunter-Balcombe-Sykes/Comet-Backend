# Database & Queue Scaling Audit — 2026-05-11

**Branch:** `development`
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php`
- `app/Http/Controllers/Api/Professional/BrandGalleryController.php`
- `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php`
- `app/Jobs/ProcessImageVariantsJob.php`
- `app/Jobs/ProcessVideoVariantsJob.php`

## Progress

- P2 Medium: 0 of 1 complete

---

## P2 — Should fix

- [ ] **SCALE-1** · P2 — R2 network I/O inside `DB::transaction()` holds Postgres connection during upload
    - **Where:** `app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php:85–148`
    - **Affects:** Postgres connection pool availability during concurrent document uploads; degrades all DB-dependent endpoints while uploads are in-flight
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Move the `Storage::disk($mediaDisk)->put(...)` call (line 139) and the `$media->update(['path' => $path])` call (line 145) to **outside** the transaction closure — the `SiteMedia` row is already created with `path: ''` at line 122, so the move is mechanical
        - End the transaction after creating the empty-path row, exit the closure, perform the R2 put, then issue a single `$media->update(['path' => $path])` after the write completes
        - Keep the existing `$newUploadedPath` orphan-cleanup logic in the `catch` block (lines 149–163) — it remains correct when the write is outside the transaction
        - The advisory lock (`pg_advisory_xact_lock`) still guards the flat-replace atomicity as long as the R2 write and path update both happen after the transaction closes; this is acceptable because the empty-path row is the canonical reservation
        - Mirror the pattern already used in `BrandGalleryController::upload()`, which creates the row with empty path inside the transaction and uploads outside
    - **Technical:** `DB::transaction()` keeps a Postgres connection checked out for the entire duration of its closure. `Storage::disk()->put()` at line 139 streams bytes to Cloudflare R2 over the network — typical latency is 50–500 ms for the 10 MB PDFs this endpoint accepts. Under concurrent uploads (e.g. 20 brands onboarding simultaneously during a cohort launch), each in-flight upload holds one pool connection for that full network round-trip, starving the pool for all other HTTP and queue-worker DB operations. The `SiteMedia` row is already created with `path: ''` at line 122, so the fix is purely a question of restructuring scope: close the transaction after the row insert, then do the R2 write and path update in the open-connection window outside. `BrandGalleryController::upload()` already implements this correctly and can be used as the reference.
    - **Plain English:** To upload a document, the app borrows a database connection and holds onto it the entire time the file is being copied to cloud storage — which can take half a second or more. We only have a limited number of database connections shared across the whole app. If twenty people upload at once, twenty connections are stuck waiting for their files to finish copying, which means every other action in the app (loading pages, processing orders, sending notifications) starts queueing up waiting for a free connection. The fix is simple: do the quick database bookkeeping first, release the connection, *then* copy the file to storage. It's like filling out the receipt before carrying the sofa rather than holding a parking spot at the warehouse while you fill it out on the loading dock.
    - **Evidence:**
        ```php
        // Line 85 — transaction opened; connection held from here...
        $media = DB::transaction(function () use ($site, $pro, $file, $actualMime, $title, $caption, $originalFilename, &$previousPath, &$newUploadedPath) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-documents:{$site->id}"]);
            }

            // ...soft-delete existing row, create new SiteMedia with path: ''...

            // Line 136 — R2 network I/O while the Postgres connection is still held:
            $mediaDisk = config('partna.media_disk');
            $path = "documents/{$pro->id}/{$media->id}/original.{$ext}";
            $stream = fopen($file->getRealPath(), 'rb');
            Storage::disk($mediaDisk)->put($path, $stream, 'public');
            if (is_resource($stream)) {
                fclose($stream);
            }
            $newUploadedPath = $path;

            $media->update(['path' => $path]);

            return $media;
        }); // ...connection released only here, after R2 put completes
        ```

`★ Insight ─────────────────────────────────────`
**On transaction scope discipline:** The `pg_advisory_xact_lock` here is doing real work — it serializes concurrent flat-replace operations for a given site's document slot. Moving R2 I/O outside the transaction doesn't break this guarantee because the empty-path row serves as the serialization token; the lock only needs to span the read-soft-delete-insert sequence, not the subsequent network write. This is the key insight that makes the restructure safe rather than just a style preference.
`─────────────────────────────────────────────────`
