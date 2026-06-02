`★ Insight ─────────────────────────────────────`
The Grep for all Shopify/Commerce/BrandStatus class names across `app/**/*.php` returned **zero matches** — confirming those files were removed in the May 2026 standalone strip-down, but the CI cache allowlist still carries their paths as explicit exemptions. This creates a maintainability trap: the allowlist looks documented and intentional, but it exempts paths that no longer exist.
`─────────────────────────────────────────────────`

# Deploy & CI Safety Audit — 2026-05-25

**Branch:** development
**Lens:** deploy script safety, CI workflow secrets handling, action permission scope, dangerous post-deploy hooks, env.example drift vs config, composer-script footguns
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- .github/workflows/ci.yml
- composer.json
- .env.example
- scripts/guard-no-unsafe-migrations.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#DEPL-1** · P1 — CI does not gate PRs (or pushes) targeting the active `development` branch
    - **Where:** .github/workflows/ci.yml:4-7
    - **Affects:** Every PR merged into `development` — the inline-auth-bypass lint, raw-Cache-call detector, migration safety guard, Pint style check, `composer audit`, and the full test suite are all bypassed silently.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `development` to both the `pull_request.branches` list and the `push.branches` list.
        - Verify the workflow file passes itself via a test PR to `development` after the fix.
    - **Technical:** The workflow triggers on `pull_request: branches: [main, development-v2]` and `push: branches: [main]`. The active integration branch per `CLAUDE.md` is `development`. All PRs opened against `development` fire zero CI jobs — no `composer audit`, no Pint, no inline-`abort(403)` scan, no GS-1 raw-Cache lint, no migration safety check, no test suite. The `push:` trigger is equally incomplete: direct pushes to `development` (e.g., a force-merge after a local rebase) are also unchecked. Adding `development` to both triggers closes the gap.
    - **Plain English:** Think of CI as the bouncer at the door checking IDs. Right now the bouncer only checks people going into two specific rooms (`main` and `development-v2`), but all the actual work gets submitted through a third room (`development`) where there's no bouncer at all. Every piece of code — including potential security shortcuts or broken database changes — walks right in unchecked.
    - **Evidence:**
        ```yaml
        on:
          pull_request:
            branches: [main, development-v2]
          push:
            branches: [main]
        ```

---

## P2 — Should fix

- [ ] **#DEPL-2** · P2 — `composer update` silently force-overwrites published vendor assets
    - **Where:** composer.json:87
    - **Affects:** Any developer or automated pipeline that runs `composer update` after customizing published Horizon dashboard views or other vendor-published files. Customizations are clobbered without warning.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Remove `--force` from the `post-update-cmd` hook. Without it, `vendor:publish` will prompt before overwriting, protecting hand-edited files.
        - If republishing on every update is genuinely required (e.g., to keep Horizon's compiled assets current), document that published files must not be hand-edited and add a comment explaining the intent.
    - **Technical:** The `post-update-cmd` hook runs `@php artisan vendor:publish --tag=laravel-assets --ansi --force` after every `composer update`. The `--force` flag skips the existence check entirely. The `laravel-assets` tag publishes Horizon's compiled frontend bundle under `public/vendor/horizon/`. If a developer has pinned an older Horizon asset version or patched the dashboard HTML, those changes are silently replaced. The CI pipeline uses `composer install` (not `update`), so this doesn't affect automated builds — but it is a footgun for any developer who runs `composer update` locally, and for any manual deploy step that calls `update` instead of `install`.
    - **Plain English:** Every time someone runs the command that updates the project's libraries, a script automatically runs that says "overwrite these frontend files, no questions asked." If anyone has tweaked those files — say, to fix a styling quirk in the queue-monitoring dashboard — those tweaks disappear without a trace. Removing the "no questions asked" part means you'd at least get a warning before anything is overwritten.
    - **Evidence:**
        ```json
        "post-update-cmd": [
            "@php artisan vendor:publish --tag=laravel-assets --ansi --force"
        ],
        ```

---

## P3 — Nice to have

- [ ] **#DEPL-3** · P3 — CI workflow runs with default GITHUB_TOKEN write permissions
    - **Where:** .github/workflows/ci.yml:1 (no `permissions:` block present)
    - **Affects:** The runtime token scope for the CI job — a supply-chain compromise of any action used in this workflow would have repository write access.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `permissions: contents: read` at the top-level workflow block (or per-job, if jobs diverge later).
        - Confirm `actions/cache@v4` requires no additional scope; it does not.
    - **Technical:** Without an explicit `permissions:` block, the workflow inherits the repository's default GITHUB_TOKEN permissions, which typically include `contents: write`. This workflow only needs to read code and run tests — it never writes back to the repo. Adding `permissions: contents: read` follows principle of least privilege and limits blast radius if `shivammathur/setup-php`, `actions/cache`, or `actions/checkout` are ever compromised at a tagged release. This is a standard hardening step for CI-only workflows.
    - **Plain English:** The CI pipeline runs with a security badge that can unlock more doors than it needs. It only needs to read the code and run tests, but its badge also has write access to the repository. Adding one line to the config limits that badge to read-only — so if one of the tools the pipeline uses ever gets hacked, the attacker can't use that badge to tamper with the codebase.
    - **Evidence:**
        ```yaml
        name: CI

        on:
          pull_request:
            branches: [main, development-v2]
          push:
            branches: [main]

        jobs:
          test:
            runs-on: ubuntu-latest
        ```
        *(No `permissions:` block appears anywhere in the file.)*

- [ ] **#DEPL-4** · P3 — GS-1 cache allowlist carries 10 stale file paths removed in the standalone strip-down
    - **Where:** .github/workflows/ci.yml:84-98 (allowlist exclusion block)
    - **Affects:** Developers reading the allowlist to understand what's legitimately exempted from the GS-1 cache discipline rule — stale entries create false authority, suggesting those patterns were reviewed and approved when the files no longer exist.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Remove the following confirmed-stale exclusion lines from the allowlist (these files were deleted in the May 2026 standalone strip-down — verified by grep returning zero matches across `app/**/*.php`):
            - `':!app/Http/Controllers/Api/Professional/ShopifyEmbeddedConnectionController.php'`
            - `':!app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php'`
            - `':!app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php'`
            - `':!app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php'`
            - `':!app/Observers/Retail/BrandStoreSettingsObserver.php'`
            - `':!app/Services/Professional/BrandStatusService.php'`
            - `':!app/Services/Shopify/ShopifySetupTokenService.php'`
            - `':!app/Services/Store/BrandCatalogService.php'`
            - `':!app/Services/Stripe/CommissionPayoutRefundService.php'`
            - `':!app/Services/Stripe/StripeConnectService.php'`
        - Leave in place the remaining allowlist entries for files that do exist (HealthController, NotificationController, BrandProfileObserver, CustomerObserver).
    - **Technical:** The GS-1 allowlist in `.github/workflows/ci.yml` uses `git grep` path exclusions to skip known-legitimate raw `Cache::*` callers. Ten of these exclusions reference files that were removed in the 2026-05-22 standalone strip-down (`Shopify/*`, `Stripe/*`, `Store/*`, `BrandStatusService`). Stale exclusions are harmless today — `git grep` simply skips paths that don't exist — but they erode the allowlist's value as documentation of intentional exemptions. They also create a subtle long-term risk: if someone re-creates a file at one of these exact paths, the raw `Cache::*` call inside it will silently bypass GS-1 because the path is pre-approved.
    - **Plain English:** The list of "approved exceptions" to a security rule still includes 10 files that were deleted weeks ago. The rule still works correctly — it just skips over the deleted files — but the approved list now looks like it covers things it doesn't. Worse, if one of those deleted files is ever recreated, its cache calls would automatically be approved without anyone realizing it was a new exception that needed review.
    - **Evidence:**
        ```yaml
        BAD=$(git grep -n -E '\bCache::(put|remember|rememberForever|forget|add)\b' -- \
          'app/' \
          ':!app/Services/Cache/' \
          ':!app/Http/Controllers/Api/Webhooks/' \
          ':!app/Http/Controllers/Api/HealthController.php' \
          ':!app/Http/Controllers/Api/Professional/ShopifyEmbeddedConnectionController.php' \
          ':!app/Http/Controllers/Api/Professional/Notifications/NotificationController.php' \
          ':!app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php' \
          ':!app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php' \
          ':!app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php' \
          ':!app/Observers/Core/BrandProfileObserver.php' \
          ':!app/Observers/Core/CustomerObserver.php' \
          ':!app/Observers/Retail/BrandStoreSettingsObserver.php' \
          ':!app/Services/Professional/BrandStatusService.php' \
          ':!app/Services/Shopify/ShopifySetupTokenService.php' \
          ':!app/Services/Store/BrandCatalogService.php' \
          ':!app/Services/Stripe/CommissionPayoutRefundService.php' \
          ':!app/Services/Stripe/StripeConnectService.php')
        ```
        *(Grep across `app/**/*.php` for all 10 class names returned zero matches — files do not exist.)*

- [ ] **#DEPL-5** · P3 — Inline PHP guard scripts in `composer.json` are opaque and untestable
    - **Where:** composer.json (scripts section, `guard:no-laravel-migrations`, `guard:no-cache-memo`)
    - **Affects:** Developer experience when debugging a guard failure — error output lacks file/line references into the guard logic itself; IDE static analysis and unit testing are impossible.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract `guard:no-laravel-migrations` and `guard:no-cache-memo` into standalone scripts under `scripts/` (e.g., `scripts/guard-no-laravel-migrations.php`, `scripts/guard-no-cache-memo.php`), following the existing pattern in `scripts/guard-no-unsafe-migrations.php`.
        - Update `composer.json` to call `@php scripts/guard-no-laravel-migrations.php` and `@php scripts/guard-no-cache-memo.php`.
    - **Technical:** The two inline guards are dense single-quoted PHP strings inside JSON. When they trip, stderr output names the violating source file but includes no internal line-reference to the guard itself — there is nothing to attach a debugger to or step through. `scripts/guard-no-unsafe-migrations.php` is 120 lines with comment blocks, structured error messages, and a standalone invocation path — the inline guards deserve the same treatment. Extracting them also makes it trivial to run guards in isolation (`php scripts/guard-no-laravel-migrations.php`) and to write Pest tests for edge cases.
    - **Plain English:** Two of the three automated safety checks live crammed into a single hard-to-read line inside the project's configuration file. When they catch a problem, the error message is cryptic and there's no easy way to step through what went wrong. The third safety check — the database migration guard — already lives in its own clean, well-commented file that's easy to read, fix, and test. Moving the other two into the same format brings consistency and saves time the next time someone needs to understand or extend a guard.
    - **Evidence:**
        ```json
        "guard:no-laravel-migrations": [
            "@php -r 'if (is_dir(\"database/migrations\")) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\"database/migrations\")) as $f) { if ($f->isFile() && $f->getExtension() === \"php\") { fwrite(STDERR, \"Laravel migrations are not allowed: \" . $f->getPathname() . PHP_EOL); exit(1); } } }'"
        ],
        "guard:no-cache-memo": [
            "@php -r '$bad = []; if (is_dir(\"app\")) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\"app\")) as $f) { if ($f->isFile() && $f->getExtension() === \"php\" && str_contains(file_get_contents($f->getPathname()), \"Cache::memo()->remember\")) { $bad[] = $f->getPathname(); } } } if (!empty($bad)) { fwrite(STDERR, \"Cache::memo()->remember is banned in app/ — use CacheLockService::rememberLocked (Master Pattern 14):\" . PHP_EOL); foreach ($bad as $p) { fwrite(STDERR, \"  $p\" . PHP_EOL); } exit(1); }'"
        ],
        ```
