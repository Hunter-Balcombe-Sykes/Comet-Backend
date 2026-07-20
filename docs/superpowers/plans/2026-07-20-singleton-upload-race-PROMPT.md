# Singleton design-media upload race — soft-deleted row with a live file (P1)

> **▶ To run this:** paste this whole file as the opening prompt of a fresh session.
> Found 2026-07-20 during the TRIAGE-2-P2 audit run, **empirically reproduced** by an
> independent reviewer, and deliberately left unfixed because closing it needs a redesign
> rather than a patch. It is **pre-existing** — not introduced by that run.

---

## The bug

`MediaUploadService::uploadSingleton()` runs in this order:

1. `purgeExistingSingleton()` — soft-deletes the current singleton and deletes its R2 files.
   **Runs OUTSIDE any lock.**
2. `createSingletonRowOrConflict()` → `createSingletonRow()` — takes
   `pg_advisory_xact_lock(hashtext("site-images:{$site->id}"))` inside its own transaction
   and INSERTs.
3. `storeOriginal()` — writes the file to R2.
4. `$media->update(['path' => $originalPath])` — final path write.

Two concurrent uploads for the same `(site_id, purpose)` can interleave so that **request
B's step 1 lands between request A's step 2 and step 4**.

When that happens:

- B soft-deletes A's freshly-committed row.
- A's step 4 **still succeeds**. `$media->update()` on an already-hydrated model calls
  `save()`, which uses `newModelQuery()` — and `newModelQuery()` carries **no global
  scopes**, so `SoftDeletingScope` does not block the write. (Verified in
  `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php`; the reviewer
  proved it with a throwaway test before deleting it.)
- Result: A's row is soft-deleted, invisible to every normal query — the `index()` listing,
  the public site render, everything — while carrying a **real R2 path and processed
  variants**. The API returns **201** to A as though it worked.

That is silent data loss. It is reclaimed only by `PurgeSoftDeleted`'s retention sweep, up
to **30 days** later. It is strictly worse than the uncaught-500 failure mode that WAS fixed.

`QUEUE_CONNECTION=sync` on the deployed env **widens the window**: image processing runs
inline inside the request, so the gap between step 2 and step 4 includes real CPU work, not
just a network round-trip.

## What is already fixed (do not redo)

The **unique-violation** half of this race is handled. If B's purge lands *before* A's
INSERT, both requests pass the purge, and the partial unique index
`site_media_design_singleton_purpose_uq` rejects the loser's INSERT. That used to surface as
an uncaught `UniqueConstraintViolationException` → raw 500; it is now caught by
`MediaUploadService::createSingletonRowOrConflict()` and converted to
`App\Services\Media\Exceptions\SingletonConflictException`, which
`UserDesignMediaController::upload()` maps to **409 `SINGLETON_UPLOAD_CONFLICT`**.

That fix leaves no orphaned R2 object, because `storeOriginal()` has not run at the point
the INSERT throws — pinned by a Mockery `->once()` expectation in
`tests/Feature/Media/DesignSingletonMediaConcurrencyTest.php`.

**This document is about the OTHER half only.**

## Why the obvious fix was rejected

Moving `purgeExistingSingleton()` inside the advisory-locked transaction closes the race at
its root — and was deliberately **not** done. Verified reasons:

- The lock key is `"site-images:{$site->id}"` — keyed on the site alone, with **no
  pool/purpose distinction**. `createSingletonRow` and `createMediaRow` take the identical
  key, and it is also used in `UserUploadController` and `UserGalleryController`. It is
  genuinely **site-wide**.
- `purgeExistingSingleton()` → `ImageVariantService::deleteVariants()` →
  `Storage::disk('media')->delete(...)`, and the `media` disk is `driver: s3` pointed at
  **R2** (`config/filesystems.php`). Genuine remote I/O.

So holding that lock across the purge would serialize **every** upload to the site — gallery
included — behind a network round-trip. That trades a narrow race for a broad
lock-over-I/O bottleneck, which is the worse deal.

## Directions worth considering

Do not assume any of these is right; they are starting points, not a plan.

- **Optimistic concurrency token.** Give the singleton row a version/nonce; make step 4's
  update conditional on the row still being live and still being the one this request
  created (`->whereNull('deleted_at')->where('id', ...)`), and handle the zero-rows-affected
  case by cleaning up A's now-orphaned R2 object and returning 409.
- **Narrow the lock rather than widening its scope.** A purpose-scoped lock key
  (`"site-singleton:{$site->id}:{$purpose}"`) would let purge+insert be atomic for
  singletons *without* serializing gallery uploads. This looks like the most promising
  direction — but check every existing holder of the site-wide key first, because two
  different keys guarding overlapping rows is its own hazard.
- **Reorder so the file lands before the row is claimable.** Write to R2 first, insert with
  the final path in one shot, and drop step 4 entirely — removing the window rather than
  guarding it. Check what depends on the two-phase write before assuming this is viable.

## Hard constraints

- **No Laravel migrations.** Schema changes go in `supabase/migrations/` as raw SQL; a
  composer guard rejects Laravel ones. A new column (e.g. a version token) makes this a
  **gated** item needing Josh's sign-off before implementation.
- Tests run **SQLite in-memory**; prod is **Postgres**. `pg_advisory_xact_lock` does not
  exist on SQLite, so true lock behaviour is unprovable in CI. The pgsql-gated pattern
  (`DB::connection('pgsql')->getDriverName() === 'pgsql'` + `markTestSkipped`) is the house
  convention — see `tests/Feature/Api/User/SiteManagement/WriteDesignKitConcurrencyTest.php`
  — but such a test **always skips** in `composer test`. Do not present a skipped test as
  coverage.
- The existing CI-running tests in `DesignSingletonMediaConcurrencyTest.php` reproduce race
  ordering via a `DB::listen()` hook that fires the competing request at a chosen query.
  That technique works and is the one to extend.
- SQLite treats unknown quoted identifiers as **string literals**, so a query on a
  nonexistent column silently "works". Assert on **returned data**, never that a query ran.
- `Illuminate\Database\UniqueConstraintViolationException` is thrown by **both** the SQLite
  and Postgres drivers (verified in `isUniqueConstraintError()` on both connections), so the
  SQLite-local mirror of the partial unique index is a faithful reproduction.

## Method

Follow `scripts/audit/fix-flow.md`: **plan (Opus) → implement (Sonnet) → independent review
by a SEPARATE Sonnet that did not write the code**. This touches concurrency and a
user-facing write path, so keep plan and implement separate and present the plan before
implementing.

**Do not use `git stash` in any form** — the stash stack is shared across worktrees. Prove
pre-fix failure by editing and restoring by hand, verifying with `git diff`.

Branch off `development`, work in a worktree under `backend-wt/` (not `.claude/worktrees/`,
which poisons the Composer classmap); each worktree needs its own `composer install` and
`.env`.

## Where the code is

- `app/Services/Media/MediaUploadService.php` — `uploadSingleton()`,
  `purgeExistingSingleton()` (carries a `KNOWN UNFIXED RACE` comment pointing here),
  `createSingletonRow()`, `createSingletonRowOrConflict()`
- `app/Http/Controllers/Api/User/Uploads/UserDesignMediaController.php` — 409 mapping
- `app/Services/Media/Exceptions/SingletonConflictException.php`
- `tests/Feature/Media/DesignSingletonMediaConcurrencyTest.php`
- `supabase/migrations/20260701210000_collapse_cover_singleton_indexes.sql` — the partial
  unique index
