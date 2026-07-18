# AI Slop & Low-Value Code Audit — 2026-07-09

**Branch:** development
**Lens:** AI Slop & Low-Value Code — comment noise, premature abstraction, dead code, defensive cruft, copy-paste drift
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Services/User`
- `app/Services/Media`
- `app/Services/Platforms`
- `app/Services/Feedback`
- `app/Services/Diagnostics`
- `app/Mail`
- `app/Http/Controllers/Api/User`
- `app/Http/Resources`
- `app/Jobs`
- `app/Console`
- `app/Notifications`
- `app/Observers`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 3 complete

---

## P3 — Nice to have

- [ ] **#SLOP-1** · P3 — Decorative banner comment separates a controller's private helpers from public actions
    - **Where:** `app/Http/Controllers/Api/User/Content/ContentController.php:190-192`
    - **Affects:** Developer readability only — no runtime impact.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete the three-line ASCII banner (`/* --- */ /* Internals */ /* --- */`).
        - A blank line before the first `private function` is sufficient visual grouping — the `private` keyword already signals the boundary.
    - **Technical:** This banner violates the `CLAUDE.md` Commenting rule "Avoid ... decorative banners" and "Don't drown files in comments." It carries no WHY, no contract, no magic-default explanation — the method's `private` visibility and blank-line spacing already convey exactly the same grouping information.
    - **Plain English:** This is like drawing a line of dashes under a chapter heading that's already bold and on its own page — the heading alone already tells you a new section started. The dashes add nothing except something for the eye to skip past.
    - **Evidence:**
        ```php
            /* ------------------------------------------------------------------ */
            /*  Internals */
            /* ------------------------------------------------------------------ */

            /**
             * A bare ContentSelection with the current site attached, for the policy.
        ```

- [ ] **#SLOP-2** · P3 — Copy-pasted decorative banner precedes private helpers in four Platforms scraper services
    - **Where:** `app/Services/Platforms/AppleSearch.php:104`, `app/Services/Platforms/ShopifyScraper.php:190`, `app/Services/Platforms/WooCommerceScraper.php:250`, `app/Services/Platforms/HumanitixScraper.php:151`
    - **Affects:** Code readability; no execution impact.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete the `// ── internals ──────────────────────────────` line from each of the four files.
        - No replacement needed — method visibility (`private`) and the existing blank line already group the helpers.
    - **Technical:** Violates the `CLAUDE.md` Commenting rule "Avoid ... decorative banners." This exact ASCII-art separator string was copy-pasted verbatim into four unrelated scraper classes, each immediately preceding their first `private function` — it documents no WHY, contract, or magic default, only a visual break already implied by scope. (Note: DeepSeek's draft additionally cited `EventbriteScraper.php` and `GoogleBusinessAutoSync.php` for this same string — grep confirms neither file contains it; `GoogleBusinessAutoSync.php` uses differently-worded section labels — `// ── reservation ──`, `// ── booking ──`, etc. — on distinct functional groupings inside a 627-line file, which is a defensible navigational aid for a large multi-concern class, not a pure "internals" divider. Both files are dropped from this finding.)
    - **Plain English:** Four different files all have the exact same "furniture" comment separating the public API from the internal plumbing, even though the code layout already makes that obvious. It's like every room in a house having an identical "PRIVATE — STAFF ONLY" sign taped over a door that's already marked as a staff door on the blueprint.
    - **Evidence:**
        ```php
        // ── internals ────────────────────────────────────────────────

        private function itunes(string $path): ?array
        ```

- [ ] **#SLOP-3** · P3 — `HasCloudflareRetryPolicy` trait has exactly one consumer; the sibling Cloudflare job explicitly opted out
    - **Where:** `app/Jobs/Concerns/HasCloudflareRetryPolicy.php` (whole file, 13 lines); consumed only by `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:40`
    - **Affects:** Developer maintainability — an indirection that reads like a shared contract but serves one job; anyone touching Cloudflare job retry semantics has to open a second file to see three property values.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inline `public int $tries = 3;`, `public array $backoff = [10, 30, 60];`, and `public int $maxExceptions = 2;` (with its short-circuit comment) directly into `SyncSubdomainToKvJob`.
        - Remove `use App\Jobs\Concerns\HasCloudflareRetryPolicy;` and the `HasCloudflareRetryPolicy` trait-use from `SyncSubdomainToKvJob`.
        - Delete `app/Jobs/Concerns/HasCloudflareRetryPolicy.php`.
    - **Technical:** Confirmed via `Grep` across the whole repo: `app/Jobs/Cloudflare/` currently contains only two job classes, `CloudflareCachePurgeJob` and `SyncSubdomainToKvJob` — the other three jobs referenced in older archived audit docs (`ProvisionBrandDnsJob`, `RetireBrandDnsJob`, `RetireSubdomainFromKvJob`) no longer exist in the codebase (removed in the 2026-05-22 standalone strip). Only `SyncSubdomainToKvJob` uses the trait. `CloudflareCachePurgeJob` carries an explicit code comment ("Why a dedicated retry policy (not `HasCloudflareRetryPolicy`): The KV policy targets the KV REST API's failure profile ... Keep this distinct from the KV trait") documenting that it deliberately inlines its own `$tries`/`$backoff`/`$maxExceptions` instead of sharing this trait. With the historical second-and-third consumers gone, this is now a single-implementer abstraction, violating the `CLAUDE.md` "Do NOT over-engineer" rule: "three similar lines > a premature abstraction." Three property declarations inlined into the one remaining job are simpler than a `use` statement plus a separate file.
    - **Plain English:** Someone built a shared "toolbox" for a job's retry settings, but the only other job that could have used it explicitly said "these settings don't apply to me" — and since then, the two other jobs that might once have shared the toolbox were removed entirely. Now it's a box with exactly one tool in it. It's simpler to just keep that one tool with its owner.
    - **Evidence:**
        ```php
        // app/Jobs/Concerns/HasCloudflareRetryPolicy.php
        trait HasCloudflareRetryPolicy
        {
            public int $tries = 3;
            public array $backoff = [10, 30, 60];
            // Short-circuit permanent failures (e.g. revoked token) so failed()/Nightwatch fires after 2 attempts, not 3.
            public int $maxExceptions = 2;
        }
        ```
        ```php
        // app/Jobs/Cloudflare/CloudflareCachePurgeJob.php — the only other Cloudflare job, explicitly opts out
        // Why a dedicated retry policy (not HasCloudflareRetryPolicy):
        //   The KV policy targets the KV REST API's failure profile (rare, slow). Cache
        //   purge has its own 4xx/5xx semantics — short retries with exponential backoff
        //   are enough; a third retry at 60s is wasted because the underlying mutation
        //   has long since settled. Keep this distinct from the KV trait.
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Decorative banner comment removal:** #SLOP-1, #SLOP-2
    - **Why grouped:** Same root cause (purely decorative separator comments with no WHY/contract content) and same one-line-deletion fix pattern across five files; trivially low risk.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (no escalation needed — mechanical deletions).

- **Bundle 2 — Inline single-consumer Cloudflare retry trait:** #SLOP-3
    - **Why grouped:** Distinct root cause (premature abstraction, not comment noise) touching a job class + deleting a trait file — kept separate from Bundle 1 so the trait removal is reviewed against `JobHygienePolicyTest`'s `$backoff`/`$tries` expectations in isolation.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
