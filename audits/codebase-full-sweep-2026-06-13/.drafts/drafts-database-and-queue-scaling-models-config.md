- [ ] **SCALE-1** · P1 — Customer::redact() loads all linked enquiries into memory without bounding
    - **Where:** app/Models/Core/User/Customer.php: near end of `redact()` method
    - **Affects:** GDPR data erasure for professionals; a user with a high volume of contact-form submissions could cause memory exhaustion or timeout during redaction, leaving PII not fully erased.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Enquiry::where(…)->each(fn ($e) => $e->redact())` pattern with a chunked approach (e.g., `chunkById(200)`) that processes enquiries in small batches.
        - Batch the status updates (e.g., bulk update the redaction fields directly on the matching rows) rather than loading each model.
    - **Technical:** `each()` internally calls `get()` and materialises the entire result set into memory, then issues an `update` per row. At viral scale a professional could have tens of thousands of enquiries; that single query will balloon memory and hold a transaction open while iterating, potentially exceeding PHP’s memory limit or the web process timeout on account deletion. Chunking avoids the memory pressure and keeps the per‑chunk work bounded.
    - **Plain English:** Imagine a folder with every message ever sent to you by your customers. The current code picks up the entire folder, reads every letter into RAM, and then goes through one‑by‑one to erase the personal info. If the folder is huge, the computer runs out of desk space mid‑job and crashes, leaving some letters un‑erased and the process incomplete. The fix is to process the folder a few letters at a time so the desk never overflows.
    - **Evidence:**
        ```php
        Enquiry::where('customer_id', $this->id)
            ->each(fn ($e) => $e->redact());
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **SCALE-2** · P2 — GalleryImageResource relies on lazy-loading of mediaVariants, risking N+1 queries
    - **Where:** app/Http/Resources/GalleryImageResource.php: line calling `$this->variantUrls()` in `toArray()`; and app/Models/Core/Site/SiteMedia.php: `variantUrls()` method that calls `$this->loadMissing('mediaVariants')`
    - **Affects:** List endpoints that use `GalleryImageResource` without eager‑loading the `mediaVariants` relation — every image row in the response will trigger an extra database query.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Require that controllers always eager‑load `mediaVariants` when using this resource.
        - Alternatively, change `variantUrls()` to accept only an already‑loaded relation (e.g., throw if not loaded) to make the mistake impossible, or use `whenLoaded` in the resource so that un‑loaded variants simply exclude the key.
    - **Technical:** The resource’s `toArray` calls `$this->variantUrls()`, which invokes `loadMissing` on the `mediaVariants` HasMany relation. If the controller forgot to `->with('mediaVariants')`, every row in a paginated gallery list will execute a separate `SELECT * FROM site.media_variants WHERE media_id = …`. At scale this is a classic N+1 whose query count multiplies with the page size. The correct Laravel pattern is to eager load the relation on the query and only use it if already present.
    - **Plain English:** Think of a photographer’s gallery: each photo’s thumbnail should be printed on the back of the print. The server currently picks up the stack of prints, then for each one sends a runner to the darkroom to fetch the tiny thumbnail. With 50 prints, that’s 50 separate trips. The fix is to fetch all the thumbnails at once before showing the prints, or to refuse to show a print that doesn’t already have its thumbnail attached.
    - **Evidence:**
        ```php
        // GalleryImageResource::toArray()
        'variants' => $this->variantUrls(),

        // SiteMedia::variantUrls()
        public function variantUrls(): array
        {
            $this->loadMissing('mediaVariants');
            …
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-3** · P2 — EnforcePlatformLinkCapCommand loads all distinct user IDs into memory
    - **Where:** app/Console/Commands/EnforcePlatformLinkCapCommand.php: line `$userIds = Block::query() … ->pluck('user_id');`
    - **Affects:** Scheduled or manual run of the platform‑link cap enforcement; at scale with thousands of professionals, the in‑memory collection of all user IDs can exhaust memory.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the single `pluck()` with a chunk‑based iteration, e.g., `User::whereIn(…)` or a cursor over distinct IDs via `chunkById`.
        - Process users in batches rather than loading the entire ID list upfront.
    - **Technical:** `pluck()` materialises every distinct `user_id` from the `site.blocks` table into a PHP array. While the loop itself then fetches blocks per user, the initial array can grow to hundreds of megabytes if the platform has many active professionals, causing an OOM kill during the command’s execution. Laravel’s `chunkById` or database-level pagination avoids this by holding only a page of IDs in memory at any time.
    - **Plain English:** The command is essentially saying “give me a list of every house in the entire city, all on one enormous sheet of paper.” That sheet alone could be too heavy to carry. The fix is to ask for the list a few blocks at a time, process those houses, and then ask for the next few blocks.
    - **Evidence:**
        ```php
        $userIds = Block::query()
            ->where('block_group', 'links')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('user_id')
            ->filter()
            ->values();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-4** · P2 — BackfillSubdomainKvCommand loads all user IDs into memory when run with --all
    - **Where:** app/Console/Commands/BackfillSubdomainKvCommand.php: line `$ids = $all ? User::query() … ->pluck('id') : collect([$proId]);`
    - **Affects:** Weekly scheduled KV backfill (Sunday 04:00) and manual re‑sync; a large user base will balloon the memory footprint of the scheduler process.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use `User::query() … ->chunkById(500)` to stream IDs and dispatch jobs in batches, or dispatch a single batch job that internally chunks.
        - Keep the per‑ID dispatch lightweight; a chunked approach avoids the massive upfront array.
    - **Technical:** The command currently calls `pluck('id')` to obtain every user ID into a PHP collection before looping. At scale this array contains one entry per professional — potentially tens of thousands — and the combined memory usage of the collection plus the subsequent foreach can push the PHP process over its memory limit, causing a crash and leaving the KV table incomplete until the next weekly run.
    - **Plain English:** Instead of bringing an entire phonebook into the office at once, the command should call one page of names, make the corresponding Cloudflare updates, then request the next page. The current approach risks the phonebook being too heavy to lift.
    - **Evidence:**
        ```php
        $ids = $all
            ? User::query()
                ->whereNotNull('handle')
                ->where('handle', '!=', '')
                ->pluck('id')
            : collect([$proId]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-5** · P2 — GcOrphanedVideoArtifactsCommand loads the entire videos/ directory listing into memory
    - **Where:** app/Console/Commands/GcOrphanedVideoArtifactsCommand.php: `foreach ($disk->allFiles('videos') as $file)`
    - **Affects:** Weekly garbage‑collection of orphaned video artefacts; as the number of uploaded videos grows, the in‑memory array of all file paths can cause an OOM kill during the command.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `allFiles()` with a streaming iterator (if the Flysystem/Laravel disk adapter supports directory listing pagination) or sweep the prefix in smaller batches using `listContents` with limit/pagination.
        - Alternatively, adopt a separate ledger‑based approach (like the existing `SweepPurgedVideoArtifactsCommand`) so a full enumeration is never needed.
    - **Technical:** `allFiles()` internally calls `listContents` with `recursive = true` and returns a plain PHP array of every file path under `videos/`. In a busy platform this could be millions of entries, consuming hundreds of megabytes of PHP memory and causing the weekly cleanup job to fail repeatedly, leaving orphan objects to accumulate and increase storage costs. A streaming or chunked listing keeps the memory footprint constant.
    - **Plain English:** The janitor currently picks up a box listing every single item in the entire warehouse, memorises every line, and then walks around to clean. If the warehouse holds a million boxes, the list alone is too heavy to carry. The fix is to walk down the aisles one shelf at a time, glancing only at the boxes in the current aisle.
    - **Evidence:**
        ```php
        foreach ($disk->allFiles('videos') as $file) {
            $parts = explode('/', $file);
            …
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-6** · P2 — PruneExpiredHandleAliases loads all expired alias IDs into memory before deletion
    - **Where:** app/Console/Commands/PruneExpiredHandleAliases.php: lines `$expiredHandleIds = $pgsql->table('core.user_handle_aliases') … ->pluck('id');` and `$expiredSubdomainIds = $pgsql->table('site.site_subdomain_aliases') … ->pluck('id');`
    - **Affects:** Daily alias expiry pruning; a large number of expired aliases will cause a memory spike in the scheduler process.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete the expired rows in batches using `limit` inside a loop rather than plucking all IDs first.
        - Capture affected professional IDs during the batched deletion (e.g., by chunking) to avoid loading the whole set at once.
    - **Technical:** `pluck()` materialises every expired alias ID into a PHP collection. Even though the subsequent delete uses those IDs, the intermediate array can be large if many professionals change handles/subdomains. At viral adoption, daily alias churn could generate tens of thousands of expired rows; the pluck could exceed the PHP memory limit and crash the scheduled job, leaving expired aliases in the database and the KV sync incomplete.
    - **Plain English:** The nightly cleanup currently says “give me a list of all expired parking permits” and then one‑by‑one removes them. If thousands of permits expire on the same night, that single list can be too long. The cleaner should instead remove a handful at a time, noting which drivers need a follow‑up, without ever holding the full list in hand.
    - **Evidence:**
        ```php
        $expiredHandleIds = $pgsql->table('core.user_handle_aliases')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->pluck('id');
        $expiredSubdomainIds = $pgsql->table('site.site_subdomain_aliases')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->pluck('id');
        ```
    - `[DRAFT, confidence: 0.9]`
