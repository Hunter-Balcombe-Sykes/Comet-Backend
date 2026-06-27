`★ Insight ─────────────────────────────────────`
Two adjudication notes worth flagging as I write: (1) DEPL-4 from DeepSeek (`composer validate` in CI, confidence 0.6) is dropped — below threshold and not a real security/data issue. (2) The GS-1 allowlist staleness finding is newly added based on Grep-confirmed file deletions, not from DeepSeek's draft.
`─────────────────────────────────────────────────`

# CI / Deploy Safety Audit — 2026-05-31

**Branch:** development
**Lens:** Deploy script safety, CI workflow secrets handling, action permission scope, dangerous post-deploy hooks, env.example drift vs config, composer-script footguns
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- .github/workflows/ci.yml
- composer.json
- composer.lock
- .env.example
- scripts/guard-no-unsafe-migrations.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 3 complete

---

## P2 — Should fix

- [ ] **#DEPL-1** · P2 — CI workflow has no explicit `permissions` declaration
    - **Where:** .github/workflows/ci.yml:1 (workflow level — no `permissions:` key anywhere in the file)
    - **Affects:** Any future workflow step that touches `GITHUB_TOKEN`; the token silently inherits write scope across `contents`, `packages`, and other resources
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `permissions: {}` at the `jobs.test` level (or workflow level). Since no current step uses `GITHUB_TOKEN` for writes, the empty block is correct.
        - If `actions/checkout@v4` needs it (it handles its own token internally), explicitly add `contents: read`.
        - Add an inline comment: `# Locked to least-privilege — add per-step permissions explicitly if a future step needs the token.`
    - **Technical:** GitHub Actions defaults `GITHUB_TOKEN` to broad write permissions for private repositories: `contents: write`, `packages: write`, and several others. No current step in this workflow uses the token, but the wide default means a future step — release automation, a status check, an accidentally-elevated transitive action — would inherit write scope without any code change being needed. Explicit `permissions: {}` makes the boundary a code-level decision rather than a GitHub default, and it's enforced in the workflow file rather than as org policy. Zero-cost, zero-breakage change.
    - **Plain English:** Right now the automated test runner holds a master key to the repository even though it never opens anything. If someone later adds a step to the CI file, or if a package dependency does something unexpected, that key could be used to push commits or delete releases — without anyone having explicitly asked for that permission. One line in the config file changes the lock so the key simply stops working until someone consciously asks for the specific door they need.
    - **Evidence:**
        ```yaml
        jobs:
          test:
            runs-on: ubuntu-latest
            # No 'permissions:' key present at job or workflow level
        ```

- [ ] **#DEPL-2** · P2 — Direct pushes to `development` bypass all CI checks
    - **Where:** .github/workflows/ci.yml:4-7 (`on:` block)
    - **Affects:** All code landed on `development` without a PR; the deployed dev environment at dev-api.partna.au
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `development` to the `push:` trigger: `branches: [main, development]`. This makes direct pushes run CI before the deploy hook picks them up.
        - Separately, add a GitHub branch protection rule on `development` requiring the `test` status check to pass — this enforces the gate at the repo layer so it can't be bypassed by editing the workflow file.
        - If the `pull_request` trigger should also gate PRs *targeting* `development` (recommended), add it there too: `branches: [main, development, development-v2]`.
    - **Technical:** The workflow triggers on `pull_request: branches: [main, development-v2]` and `push: branches: [main]`. The `development` branch — the active integration branch that deploys to `dev-api.partna.au` — appears in neither list. A direct `git push origin development` or a GitHub squash-merge that bypasses a PR skips: Pint style enforcement, the inline-403 controller abort detector, the GS-1 raw-`Cache::` lint, the migration safety guard (Master Pattern 20), `composer audit`, and the full Pest test suite. All of these guards were added specifically because broken patterns can reach the deployed dev API; the bypass makes them opt-in rather than mandatory.
    - **Plain English:** The project has a set of automated safety checks — code style, security patterns, database safety, full tests. They run when a pull request is opened. But there's nothing stopping someone from pushing code directly to the main working branch, skipping every check. That code goes straight to the live development server. The fix is to tell those checks to also watch for direct pushes to that branch, and to configure the branch so direct pushes require passing checks first.
    - **Evidence:**
        ```yaml
        on:
          pull_request:
            branches: [main, development-v2]
          push:
            branches: [main]
        ```

---

## P3 — Nice to have

- [ ] **#DEPL-3** · P3 — `--force` in `post-update-cmd` silently overwrites customised published assets on every `composer update`
    - **Where:** composer.json:82-84 (`post-update-cmd` block)
    - **Affects:** Developers who customise any Laravel-published asset (config stubs, views, etc.) and later run `composer update` or `composer require`
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `--force` from the `post-update-cmd` entry. Without it, `artisan vendor:publish` skips files that already exist, preserving local changes.
        - Consider moving `vendor:publish` to `post-install-cmd` only — that hook fires on fresh installs, not on every package update.
        - Production deploys use `composer install` (not `update`), so `post-update-cmd` never runs in production. The change is safe.
    - **Technical:** `post-update-cmd` fires on every `composer update` or `composer require` invocation. The `--force` flag causes `artisan vendor:publish` to silently overwrite any customised published file with the vendor original. On a production deploy (which uses `composer install`), this hook does not fire — so production is unaffected. The blast radius is local development: a developer who has tuned a published config or view and then adds a new package will lose those changes without any warning or diff output from Artisan.
    - **Plain English:** There are framework templates that get copied into the project so developers can customise them. The `--force` flag means "always replace those customisations with the originals whenever any package is added or updated." A developer could spend time carefully adjusting one of those files, then accidentally lose all that work the next time they type `composer require anything`. Dropping the flag means the command skips files that already exist — if you want to force-refresh a specific template, you do it explicitly rather than on every update.
    - **Evidence:**
        ```json
        "post-update-cmd": [
            "@php artisan vendor:publish --tag=laravel-assets --ansi --force"
        ],
        ```

- [ ] **#DEPL-4** · P3 — `composer.json` PHP floor `^8.2` contradicts the `openspout` production dependency
    - **Where:** composer.json (`require."php"` key); composer.lock (`openspout/openspout` v5.7.0 entry)
    - **Affects:** Developers setting up the project on PHP 8.2 or 8.3; any future CI matrix that tests lower PHP versions
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update `"php": "^8.2"` to `"^8.4"` in `composer.json`'s `require` block.
        - Run `composer update --lock` (no-op if lock is already consistent) to write the updated platform to `composer.lock`.
    - **Technical:** `composer.json` declares `"php": "^8.2"` but the locked production dependency `openspout/openspout` v5.7.0 requires `"php": "~8.4.0 || ~8.5.0"`. Composer resolved the lock on PHP 8.4 (matching CI's `php-version: '8.4'`) so the lock file is internally consistent and CI passes. However, any developer or future CI matrix runner on PHP 8.2/8.3 will hit a platform-requirement error on `composer install` with no clear explanation from `composer.json`. The manifest's `^8.2` claim is a false promise.
    - **Plain English:** The project's specification sheet says it runs on PHP 8.2 or newer. But one of the installed libraries quietly requires PHP 8.4. The discrepancy doesn't cause a problem in practice — the servers all run 8.4 and CI runs 8.4 — but it means anyone who trusts the spec sheet and tries to set up on 8.2 gets a confusing error. Updating the number from 8.2 to 8.4 makes the spec sheet tell the truth.
    - **Evidence:**
        ```json
        // composer.json
        "require": {
            "php": "^8.2",
            "openspout/openspout": "^5.7",
        ```
        ```json
        // composer.lock — openspout/openspout v5.7.0
        "require": {
            "php": "~8.4.0 || ~8.5.0"
        },
        ```

- [ ] **#DEPL-5** · P3 — GS-1 cache-discipline allowlist retains 10 stale path exclusions from the standalone strip-down
    - **Where:** .github/workflows/ci.yml (`No raw Cache::* calls outside cache services (GS-1)` step, `git grep` exclusion list)
    - **Affects:** Correctness of the GS-1 lint if Shopify/commerce is ever reintegrated; developer understanding of what the approved exceptions actually are
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the following `:!path` exclusions — all confirmed deleted since the 2026-05-22 standalone strip-down:
            - `app/Http/Controllers/Api/Professional/ShopifyEmbeddedConnectionController.php`
            - `app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php`
            - `app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php`
            - `app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php`
            - `app/Observers/Core/BrandProfileObserver.php`
            - `app/Observers/Retail/BrandStoreSettingsObserver.php`
            - `app/Services/Professional/BrandStatusService.php`
            - `app/Services/Shopify/ShopifySetupTokenService.php`
            - `app/Services/Store/BrandCatalogService.php`
            - `app/Services/Stripe/CommissionPayoutRefundService.php`
            - `app/Services/Stripe/StripeConnectService.php`
        - Confirm `app/Observers/Core/CustomerObserver.php` (the one surviving named-file exclusion) still legitimately uses raw `Cache::` and add a brief inline comment explaining why.
    - **Technical:** `git grep` with `:!path` exclusions silently skips non-existent files — it only scans files that currently exist on disk. Stale exclusions are therefore inert today and do not weaken the lint. The forward risk is specific: if commerce is reintegrated (planned per `project_standalone_strip_down.md`) and any of these exact paths are recreated, their `Cache::` calls will automatically bypass the GS-1 lint without the allowlist entry requiring explicit review. A developer adding `app/Services/Shopify/ShopifySetupTokenService.php` could include raw `Cache::remember(...)` calls and CI would pass silently. Cleaning the list also removes misleading documentation — the comment at the top of the GS-1 step says "New code MUST route through a cache service. To add a new exception, justify it in PR review and append the path here," implying every listed path was justified and reviewed.
    - **Plain English:** The CI check that prevents developers from bypassing the cache architecture has a list of approved exceptions. Most entries on that list are for files that were deleted when the project was stripped down. Deleted files can't violate any rules, so this doesn't cause a problem right now. But if the project ever brings back commerce features (which is planned), those deleted files would automatically be on the "approved exceptions" list without anyone reviewing them — the safety scanner would wave them through based on a stale approval. Cleaning the list now costs nothing and keeps it honest for when it matters.
    - **Evidence:**
        ```yaml
        # The following named-file exclusions in the GS-1 step are confirmed
        # deleted (grep returns no matches for their class names in app/):
        ':!app/Http/Controllers/Api/Professional/ShopifyEmbeddedConnectionController.php' \
        ':!app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php' \
        ':!app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php' \
        ':!app/Observers/Core/BrandProfileObserver.php' \
        ':!app/Observers/Retail/BrandStoreSettingsObserver.php' \
        ':!app/Services/Professional/BrandStatusService.php' \
        ':!app/Services/Shopify/ShopifySetupTokenService.php' \
        ':!app/Services/Store/BrandCatalogService.php' \
        ':!app/Services/Stripe/CommissionPayoutRefundService.php' \
        ':!app/Services/Stripe/StripeConnectService.php'
        ```

The final audit is complete — 2 P2 hardening items and 3 P3 cleanup items, all with verbatim evidence verified against source.
