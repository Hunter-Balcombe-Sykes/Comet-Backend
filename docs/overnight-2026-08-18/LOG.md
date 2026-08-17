# Overnight run 2026-08-18 — LOG

Plan + rulings: `docs/2026-08-18-overnight-run-plan.md`. Times AEST.
Entry types: `F<n>` finding (probe evidence → fix → re-probe → gate) · `X<n>` unrelated bug fixed on the way · `BLOCKED-F<n>`.

## W0 — setup (02:45–03:20)

- Test account: `broken-oven` (auth `ff2e60cf-…`, core `019f936e-115f-…`, business/bar). Snapshot before wipe: 42 connections (1 live), 17 ingest.sources, 235 content.items, 199 media_assets, 100 pins, 1 workplace, 22 collections. **Wiped to 0 across the board** with new `partna:reset-test-user broken-oven --yes` (03:05). Retired-surface live rows across dev: **0** (guardrail query). Residue elsewhere: not touched.
- Local topology up: `artisan serve :8000` (8 CLI workers), 2 × `queue:work` (all queues), `schedule:work`; local Redis; dev Supabase via pooler; dashboard :3000 → `http://localhost:8000` (`.env.local` backed up). Browser session authenticated (`/api/me` 200 via :8000).
- `.env` backed up (`.env.backup-2026-08-18-pre-overnight`); local-override tail appended (APP_ENV=local, local Redis, MAIL log, PARTNA_MEDIA_DISK=public_dev to match dev).
- New dev-only commands: `partna:reset-test-user`, `ingest:run --source|--user [--key] [--sync]`, `partna:as <handle> <METHOD> <uri> [--json]` (real HTTP kernel, JWT stub as in tests). Verified `partna:as broken-oven GET /api/me` → 200.
- Baselines: dashboard typecheck ✅ lint ✅ (1 warning). Backend `composer test` running (see W0 close).
- MCPs live: Nightwatch (app a1698025), Supabase (dev ref, prod present → always pass dev ref), Laravel Cloud tinker (`cloud tinker development --code=`), supabase CLI linked to dev; migrations in sync through 20260819000300.
- Apify: US$3.91 / 29 used this cycle.

## W1 — connect matrix
