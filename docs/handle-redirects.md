# Handle Redirect Lifecycle

## Overview

When a professional renames their handle, the old handle enters a tiered lifecycle:

- **GRACE (Day 0–14):** 301 redirect active. Original owner can reclaim for free (no cooldown).
- **REDIRECT (Day 14–90):** 301 redirect active. Reclaim requires a normal rename with cooldown.
- **RELEASED (Day 90+):** Prune job deletes the alias row. Handle returns to the public pool.

## Lifecycle Diagram

```
                      ┌─────────────────────────────────┐
                      │  Day 0 — user renames           │
                      │  old handle written as alias    │
                      └─────────────────────────────────┘
                                      │
                                      ▼
 ┌─────────────────────────────────────────────────────────────────────┐
 │  GRACE  (Day 0 → Day 14)                                            │
 │  reclaim_until = created_at + 14d                                   │
 │  • Old URL serves 301 to new URL                                    │
 │  • Old handle reserved for ORIGINAL owner — they can reclaim free   │
 │  • Nobody else can rename TO this handle                            │
 └─────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
 ┌─────────────────────────────────────────────────────────────────────┐
 │  REDIRECT  (Day 14 → Day 90)                                        │
 │  reclaim_until passed, expires_at = created_at + 90d                │
 │  • Old URL still serves 301 to new URL                              │
 │  • Owner can no longer free-reclaim (they must use a normal rename) │
 │  • Still reserved against new claims                                │
 └─────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
 ┌─────────────────────────────────────────────────────────────────────┐
 │  RELEASED  (Day 90+)                                                │
 │  prune job hard-deletes the row                                     │
 │  • Old URL returns 404                                              │
 │  • Handle returns to the public pool, claimable by anyone           │
 └─────────────────────────────────────────────────────────────────────┘
```

## Cloudflare KV Contract

The `SyncSubdomainToKvJob` writes two types of entries to KV:

**Canonical entry** (no TTL):
```json
{"type": "brand"}
```
or
```json
{"type": "affiliate", "redirect": "https://brand.partna.au/handle"}
```

**Alias entry** (with `expirationTtl` matching `expires_at`):
```json
{"type": "alias", "target": "current-handle"}
```

The Edge Worker MUST respond to `type=alias` with:
```
HTTP/1.1 301 Moved Permanently
Location: https://<target>.partna.au<path>
Cache-Control: public, max-age=300
```

## Edge Worker Rollout Order

IMPORTANT: Deploy in this order to avoid silent 404s:

1. Deploy backend changes (Tasks 1–13) — KV writes the new `type=alias` entries.
2. Deploy updated Edge Worker that handles `type=alias` → 301.
3. After verifying the worker handles `type=alias` correctly, the system is fully live.

If the worker is deployed before the backend, `type=alias` entries don't exist yet — no harm.
If the backend is deployed before the worker, alias entries are written but the worker returns 404 for them —
there is no PHP fallback. The alias 301 is edge behaviour only: the Cloudflare Worker reads
`SUBDOMAIN_KV`, and a `{type:"alias"}` entry (written solely by `SyncSubdomainToKvJob`) is what
turns into the redirect. The backend serves no host-based redirect of its own; the one that used
to live in `PublicSiteController` went with that controller on 2026-09-04.

## Audit Log Schema

`audit.handle_change_log` records every rename event. Key columns:

| Column | Type | Description |
|--------|------|-------------|
| `id` | UUID | Primary key |
| `user_id`         | UUID | FK to core.users         |
| `old_handle` | VARCHAR | Handle before the rename |
| `new_handle` | VARCHAR | Handle after the rename |
| `changed_at` | TIMESTAMPTZ | When the rename occurred |
| `ip_address` | INET | Request IP (for abuse investigation) |
| `user_agent` | TEXT | Request UA |

Retention: 7 years (configurable via `partna.handle.audit_retention_years`). This log is append-only and never pruned alongside aliases — it's the forensic record of who owned a handle at any given time.

## Config

| Key | Default | Description |
|-----|---------|-------------|
| `partna.handle.reclaim_days` | 14 | Grace window in days |
| `partna.handle.redirect_days` | 90 | Total redirect window in days |
| `partna.handle.audit_retention_years` | 7 | Years to retain handle_change_log |

## Runbook: Prune deleted an alias prematurely

If the prune job deleted an alias that was still needed:
1. Check `audit.handle_change_log` for the rename event timestamp
2. The alias cannot be restored — the prune is hard-delete
3. If the handle is now taken by another professional, contact support
4. If the handle is in the pool, the professional can claim it via a normal rename (30-day cooldown applies)

## Runbook: handles:prune-expired-aliases

The `handles:prune-expired-aliases` artisan command hard-deletes alias rows where `expires_at < NOW()`. It is safe to run manually if needed:

```bash
php artisan handles:prune-expired-aliases --dry-run   # preview what would be deleted
php artisan handles:prune-expired-aliases             # actually prune
```

Scheduled daily via `app/Console/Kernel.php`. Logs the count of pruned rows at `info` level. No rollback possible — check the audit log before pruning manually in production.
