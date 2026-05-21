- [ ] **#TXN-1** · P2 — Log call inside DB::transaction may ship to external sink in production
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:139
    - **Affects:** Poorly — this is a defensive flag. Under default Laravel config (file driver) this is harmless. If the production logging driver is Datadog, Sentry, or any network-shipping channel, this opens a TCP connection while holding the Postgres advisory lock + connection slot. The impacted user is whoever collides with the upload endpoint under load.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `Log::info('SiteMedia row created', ...)` to immediately after the `DB::transaction` block closes, or delete it (the `Log::info('Media upload started', ...)` and `Log::info('Original stored successfully', ...)` already bracket the operation).
        - If structured-log-shipping is detected in production config, audit all remaining `Log::*` calls inside `DB::transaction` closures across the codebase.
    - **Technical:** Category 6. Default Laravel logging writes to a local file descriptor synchronously — safe inside a transaction. But when the logging channel is stack/elastic/sentry/datadog, each `Log::info()` fires an HTTP/TCP round-trip that blocks the Postgres connection for the duration. Under concurrent uploads, this exhausts the connection pool faster than expected. The fix is trivial — the log line is informational and adds no value inside the atomic boundary.
    - **Plain English:** Inside the locked safe where database rows are being written, there's a note being passed through a pneumatic tube to another building. If the tube system is just a local filing cabinet it's fine; but if it's hooked up to an external monitoring service, every note ties up the safe while the tube travels. Moving the note outside the safe costs nothing.
    - **Evidence:**
        ```php
        $media = DB::transaction(function () use ($site, $pool, $maxItems, $request, $mediaType, $file) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-images:{$site->id}"]);
            }
            // ... lockForUpdate, count, create ...
            Log::info('SiteMedia row created', ['media_id' => $media->id, 'media_type' => $mediaType]);
            return $media;
        });
        ```
    - `[DRAFT, confidence: 0.5]`

- [ ] **#TXN-2** · P2 — Mass-update bypasses model observers inside transaction; missed side effects on professional-type change
    - **Where:** app/Http/Controllers/Api/Professional/Account/ProfessionalController.php:97-103 (and identical pattern in StaffProfessionalController.php:221-227)
    - **Affects:** Professionals switching their type to "influencer" — the `disableProfessionalOnlySections()` call runs a query-builder mass-update inside the transaction, bypassing `BlockObserver`. If BlockObserver ever gains a cache-invalidation, event-dispatch, or notification side effect (e.g. touching a parent Site or busting the site-blocks cache), that side effect silently does not fire.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After the `DB::transaction` block commits, iterate the affected block IDs and call `->save()` (or `->touch()`) on each so the observer fires, OR explicitly replicate the expected side effect (e.g. `site->touch()` + cache bust).
        - Document that query-builder mass-updates skip observers so future maintainers know to check this site.
    - **Technical:** Category 11 — observers only fire on Eloquent lifecycle events (`save()`, `delete()`, etc.), not on query-builder `update()`. The `disableProfessionalOnlySections()` helper runs `Block::query()->...->where('is_active', true)->update(['is_active' => false])` inside the parent transaction. If BlockObserver (or a future one) dispatches a cache-warming job or invalidates a site-blocks cache key on `updated`, none of that runs. The same pattern is already acknowledged in `ProfessionalUploadController::reorder()` where the comment explicitly notes the observer bypass and compensates with `$site->touch()` — that compensation is absent here.
    - **Plain English:** When a user switches account type, some sections get turned off. The code does a bulk "flip all these switches at once" database command inside a locked room. But if any switch should also ring a bell (like saying "hey, the public website needs to refresh"), the bell doesn't ring because the bulk command bypasses the bell-wiring. The fix is to either ring the bells manually after leaving the room, or flip each switch individually so the wiring works.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($professional, $request, $previousProfessionalType): void {
            $professional->fill($request->validated());
            $professional->save();

            $nextProfessionalType = mb_strtolower(trim((string) ($professional->professional_type ?? '')));
            if ($previousProfessionalType !== 'influencer' && $nextProfessionalType === 'influencer') {
                $this->disableProfessionalOnlySections($professional->id);
            }
        });

        // ...

        private function disableProfessionalOnlySections(string $professionalId): void
        {
            // ...
            Block::query()
                ->where('professional_id', $professionalId)
                ->where('block_group', 'sections')
                ->whereIn('block_type', $this->professionalOnlySectionTypes())
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                ]);
        }
        ```
    - `[DRAFT, confidence: 0.6]`

- [ ] **#TXN-3** · P2 — Mass status-update inside transaction bypasses ProfessionalObserver; missed side effects on bulk suspend/reactivate
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffProfessionalController.php:185-192
    - **Affects:** Staff compliance sweeps that suspend or reactivate batches of professionals (up to 100 at once). If ProfessionalObserver has side effects — cache invalidation, event dispatch, notification, Cloudflare purge — none fire because the mass `update()` skips Eloquent events.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - After the transaction commits, iterate the `$updated` IDs and call `Professional::find($id)->touch()` or fire a dedicated `ProfessionalStatusChanged` event per ID so cache/observer side effects run.
        - Alternatively, replace the mass query-builder update with a loop of `$pro->status = $status; $pro->save();` inside the transaction — this fires observers synchronously but is safe because the transaction itself provides atomicity and no external I/O is introduced.
    - **Technical:** Category 11. The `bulkUpdateStatus()` method uses `Professional::query()->whereIn('id', $existing)->update(['status' => $status])` inside a `DB::transaction`. Query-builder `update()` does not trigger Eloquent `saving`/`saved` events. If ProfessionalObserver (or a future one) invalidates caches, dispatches a `ProfessionalUpdated` event that feeds the staff dashboard, or pushes status to an external system, none of that executes. The `Log::info()` calls happen correctly outside the transaction, but the Eloquent lifecycle bypass is the gap.
    - **Plain English:** When staff suspend 50 accounts at once, the database rows update correctly inside a locked room, but none of the "account suspended" bells ring — no cache refresh, no event log, no downstream system notification. The room locks, the rows change, and the rest of the building doesn't find out. The fix is to either ring the bells for each account after the room unlocks, or process each account individually inside the room so the existing bell-wiring fires.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($ids, $status, &$updated, &$missing): void {
            $existing = Professional::query()->whereIn('id', $ids)->get(['id'])->pluck('id')->all();
            $missing = array_values(array_diff($ids, $existing));

            if (! empty($existing)) {
                Professional::query()
                    ->whereIn('id', $existing)
                    ->update(['status' => $status]);
                $updated = $existing;
            }
        });
        ```
    - `[DRAFT, confidence: 0.6]`

- [ ] **#TXN-4** · P2 — SiteMedia withoutEvents() inside transaction suppresses observers during flat-replace; intentional but fragile
    - **Where:** app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php:173-177
    - **Affects:** Users uploading a new document (flat-replace of the previous one). The old document's `deleted` observer is suppressed to avoid duplicate section-visibility work. Correct today, but fragile if the observer later gains a side effect unrelated to section visibility (e.g. audit log, analytics event, R2 byte accounting).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a short comment listing exactly which observer side effects are being suppressed and why, so a future engineer adding a new side effect to the `deleted` hook knows they must replicate it here.
        - Consider firing the suppressed side effects explicitly after the transaction commits (or wrapping only the known-duplicate side effect in a guard rather than suppressing the entire event).
    - **Technical:** Category 5 / Category 11 — `SiteMedia::withoutEvents()` is a blunt instrument. The comment says it prevents the `deleted` event and the new row's `saved` event from both triggering section-visibility reevaluation (described as "wasted work"). This is correct for the current observer code. However, `withoutEvents()` suppresses ALL Eloquent events for that delete — if `SiteMediaObserver::deleted` later gains an audit-log entry, a cleanup-job dispatch, or any side effect unrelated to section visibility, this `withoutEvents()` call silently drops it with no compilation error or test failure. The same pattern appears at ProfessionalDocumentController:173.
    - **Plain English:** When replacing an old document with a new one, the code deletes the old row but tells the framework "don't tell anyone about this delete" because the new row's creation will send a similar notification and we don't want double-notifications. This works, but it's like covering someone's mouth — if they later need to also send a different message (like "log this deletion for the audit trail"), that message gets blocked too. A note on the door explaining which messages are being suppressed would prevent future accidents.
    - **Evidence:**
        ```php
        // Suppress the old row's `deleted` observer event during
        // flat-replace — the new row's `saved` event a few lines
        // below will trigger section-visibility reevaluation once.
        // Without this, both events fire post-commit and do the
        // same DB read + check in sequence (wasted work).
        SiteMedia::withoutEvents(function () use ($existing): void {
            $existing->delete();
        });
        ```
    - `[DRAFT, confidence: 0.4]`

- [ ] **#TXN-5** · P2 — Multiple staff reorder transactions use two-pass sort_order update; no external I/O but pattern duplicates state risk
    - **Where:**
        - app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php:68-103
        - app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffSectionManagementController.php:82-117
        - app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:213-258
    - **Affects:** Any concurrent reorder request for the same professional's links/sections/images. The two-pass pattern (set all to offset+N, then set all to N) has a sub-microsecond window between passes where sort_order values are inflated. Under extreme concurrency, a reader could see partially-ordered rows. Practical risk is near-zero due to the advisory lock, but the second pass is unnecessary complexity.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collapse to a single-pass update: iterate `$newOrder` and set `sort_order = $i` directly. The advisory lock already serializes writers, so the two-phase offset dance provides no additional safety.
        - This applies to all three reorder methods (staff links, staff sections, professional image uploads).
    - **Technical:** Category 7 (transaction scope / unnecessary complexity) — The two-pass pattern (offset+N then N) is a workaround for databases that can't reorder in-place due to unique constraints. Postgres has deferrable constraints and the advisory lock already guarantees serialized access. The pattern works correctly but adds 2× the UPDATE statements per reorder and a theoretical read-skew window. Not a correctness bug today, but code that confused the original author enough to over-engineer is worth simplifying.
    - **Plain English:** When reordering items, the code moves everything to a temporary shelf first, then moves them to their final positions. It's like taking all the books off the shelf, putting them on a cart with new temporary numbers, then putting them back in order — instead of just shuffling them directly on the shelf. The room is already locked so nobody else can come in during the shuffle. The extra cart trip doesn't break anything but it's twice the work and makes the process harder to follow.
    - **Evidence:**
        ```php
        // Two-pass reorder pattern (representative — StaffLinkBlockManagementController:84-100)
        DB::transaction(function () use ($professional, $site, $ids) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-links:{$site->id}"]);
            // ... lockForUpdate, validate ...
            $offset = (int) Block::query()->...->max('sort_order') + 1000;

            foreach ($newOrder as $i => $id) {
                Block::query()->...->update(['sort_order' => $offset + $i]);
            }
            foreach ($newOrder as $i => $id) {
                Block::query()->...->update(['sort_order' => $i]);
            }
        });
        ```
    - `[DRAFT, confidence: 0.3]`
