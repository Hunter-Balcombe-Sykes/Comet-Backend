# CI & Deploy Safety Audit — 2026-05-24

**Branch:** development
**Lens:** deploy script safety, CI workflow secrets handling, action permission scope, dangerous post-deploy hooks, env.example drift vs config, composer-script footguns
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- scripts/guard-no-unsafe-migrations.php
- composer.json
- .env.example

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 2 complete

---

> **Adjudication note — DEP-1 dropped:** DeepSeek claimed the `GRANDFATHERED_CUTOFF = '20260514100000'` renders the guard non-functional because "every migration currently in the repository is grandfathered." Verification shows the only migration in `supabase/migrations/` is `20260526000000_baseline_standalone_user.sql` — its timestamp (`20260526000000`) is numerically *greater* than the cutoff, so the guard **will** check it. The convention was adopted 2026-05-14; the baseline was written 2026-05-26. The guard is correctly calibrated. Finding dropped — evidence did not survive verification.

## P3 — Nice to have

- [ ] **#DEP-1** · P3 — `post-update-cmd` force-publishes vendor assets on every `composer update`
    - **Where:** composer.json:scripts.post-update-cmd
    - **Affects:** Developers who customize published Laravel assets (pagination views, mail templates); changes are silently reverted on every dependency update.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `--force` flag so `vendor:publish` skips files that already exist (the default behaviour).
        - If framework asset updates are genuinely needed after upgrades, run the publish manually with `--force` as a deliberate, visible step rather than an automatic hook.
    - **Technical:** `post-update-cmd` fires after every `composer update`. The `--force` flag overwrites existing published files unconditionally. No customized views under `resources/views/vendor/` exist yet, so there is no immediate data loss — but this is a pre-beta codebase that will soon accumulate customized mail and error templates. Once that happens, any `composer update` run by a developer will silently overwrite their work. Removing `--force` costs nothing; it only means published files won't auto-update on upgrade (which is generally what you want for a project that customizes them).
    - **Plain English:** Every time a developer updates the project's PHP dependencies, the system automatically overwrites any customized email or error-page templates with the framework's plain defaults — like a shared whiteboard that erases everyone's notes whenever anyone opens the window. There's nothing custom to lose today, but removing this behaviour now costs nothing and prevents a confusing hour of debugging later when someone wonders why their styled email header disappeared.
    - **Evidence:**
        ```json
        "post-update-cmd": [
            "@php artisan vendor:publish --tag=laravel-assets --ansi --force"
        ],
        ```

- [ ] **#DEP-2** · P3 — `post-create-project-cmd` creates a dead SQLite file in a PostgreSQL-only project
    - **Where:** composer.json:scripts.post-create-project-cmd
    - **Affects:** Project setup hygiene; negligible runtime risk (tests use `:memory:`, not the file).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `touch('database/database.sqlite')` line from `post-create-project-cmd`.
        - The file is already confirmed harmless (`phpunit.xml` pins `DB_DATABASE=:memory:`), so this is purely a clarity/cleanup change.
    - **Technical:** This is a Laravel skeleton artefact. The project is PostgreSQL-only (`DB_CONNECTION=pgsql` in `.env.example`). `phpunit.xml` sets `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:` — no test ever reads `database/database.sqlite`. The file serves no purpose and implies SQLite support to anyone reading the project setup script for the first time. Removing it eliminates a misleading signal with zero functional impact.
    - **Plain English:** The project setup script creates an empty SQLite database file — a leftover from the framework's default starter kit. This project doesn't use SQLite; it uses PostgreSQL. The file just sits there unused, like a power adapter for the wrong country in the box. It causes no harm today, but it's noise that can confuse a new developer who sees it and wonders if SQLite is somehow involved.
    - **Evidence:**
        ```json
        "post-create-project-cmd": [
            "@php artisan key:generate --ansi",
            "@php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\""
        ],
        ```

`★ Insight ─────────────────────────────────────`
- The `GRANDFATHERED_CUTOFF` pattern in `guard-no-unsafe-migrations.php` is a thoughtful design: rather than an allowlist of specific file names (which would need updating every time a new migration is added), it uses a single monotonic timestamp boundary — any migration written after the convention adoption date is automatically in scope with zero maintenance overhead.
- The Redis DB assignment in `.env.example` includes a critical comment that `Cache::flush()` issues a raw `FLUSHDB` that is NOT key-prefix-scoped — this is a subtle but important caveat that many teams discover painfully in production when a cache flush wipes Horizon job state.
`─────────────────────────────────────────────────`

The two remaining findings are both harmless Laravel skeleton leftovers — safe to clean up in a single small PR.
