# Configuration Hygiene Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Configuration Hygiene — `env()` calls outside config files, missing `.env.example` entries for active config keys, feature flags without safe defaults, and config values used inconsistently (hardcoded in some places, config-driven in others).
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- routes/api/platforms.php
- app/Console/Commands/CleanupOrphanedLifestyleConnections.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Services/Cloudflare/CloudflarePurgeService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 2 complete

---

## P3 — Nice to have

- [ ] **CFG-1** · P3 — Hardcoded HTTP timeouts in `CloudflareCustomHostnameService`
    - **Where:** app/Services/Cloudflare/CloudflareCustomHostnameService.php:56,82,97
    - **Affects:** Operators tuning Cloudflare "Custom Hostnames" API call behavior; any environment where Cloudflare's control plane is slower than the hardcoded budget.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the 10s timeout (`create()`/`get()`) and 5s timeout (`delete()`) into `config('services.cloudflare.custom_hostname_timeout', 10)` / `config('services.cloudflare.custom_hostname_delete_timeout', 5)`.
        - Add corresponding `.env.example` entries with comments, mirroring the existing `CLOUDFLARE_*` block.
    - **Technical:** Category 4 — magic numbers in a service class. `Http::withToken(...)->timeout(10)` / `->timeout(5)` are hardcoded literals. Note this mirrors `SupabaseAdminService`'s own hardcoded `->timeout(10)` (confirmed via grep) — hardcoding a short internal-API timeout is the dominant, apparently deliberate pattern across this codebase's thin API wrappers (`GoogleBusinessApifyScraper`, `InstagramScraper`, `FreshaScraper` all hardcode theirs too), so this is genuinely low-risk polish rather than an isolated oversight. Still, a small number of services (`SafeUrlFetcher`, `LogoProcessorClient`, `BrandScanClient`) do pull timeouts from config, so there's an established config-driven pattern this file could adopt for consistency.
    - **Plain English:** This is like a phone system that hangs up on the other party after a fixed number of seconds, with that number welded into the wiring instead of printed on a settings dial. Most of the time 10 seconds is fine, but if Cloudflare's systems ever run slow, someone has to edit and re-ship the code to wait longer instead of just changing a setting.
    - **Evidence:**
        ```php
        // create() — 10s timeout
        $result = Http::withToken($this->apiToken)
            ->timeout(10)
            ->asJson()
            ->post($this->base(), [

        // get() — 10s timeout
        $result = Http::withToken($this->apiToken)
            ->timeout(10)
            ->get($this->base()."/{$id}")

        // delete() — 5s timeout
        Http::withToken($this->apiToken)->timeout(5)->delete($this->base()."/{$id}");
        ```

- [ ] **CFG-2** · P3 — Hardcoded fetch timeouts and media size caps in `InstagramConnectJob`
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:73,76,81,87
    - **Affects:** Operators managing Instagram media-mirroring behavior; environments where CDN latency or media sizes differ from today's assumptions.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `IMAGE_TIMEOUT_SECONDS`, `VIDEO_TIMEOUT_SECONDS`, `MAX_VIDEO_BYTES`, and `MAX_IMAGE_BYTES` to `config('partna.platforms.instagram.*')`.
        - Add matching `.env.example` entries with the current values as defaults and a one-line comment on each limit's purpose.
    - **Technical:** Category 4 — outbound fetch timeouts and per-request size caps hardcoded as private class constants (10s images / 30s video / 50MB video cap / 15MB image cap). Same root-cause pattern as CFG-1 (hardcoded operational limits in an outbound-HTTP code path) — tier consistency applies. Unlike CFG-1's short control-plane calls, these gate a CDN media mirror with real SSRF/size-abuse defenses already layered on (host allowlist, `SafeUrlFetcher::assertSafe`, no-redirect, content-type check, byte cap) — the values themselves are sound, just not adjustable without a deploy.
    - **Plain English:** These are safety rails for downloading a profile's Instagram photos and videos — how long to wait, and how large a file to accept before giving up. They're reasonable numbers today, but they're baked directly into the code rather than kept in a settings file, so if Instagram's file sizes or CDN speed change, a developer has to edit and redeploy code instead of flipping a setting.
    - **Evidence:**
        ```php
        private const IMAGE_TIMEOUT_SECONDS = 10;
        private const VIDEO_TIMEOUT_SECONDS = 30;
        private const MAX_VIDEO_BYTES = 52428800;
        private const MAX_IMAGE_BYTES = 15728640;
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Outbound HTTP limits → config:** CFG-1, CFG-2
    - **Why grouped:** Same root-cause pattern (hardcoded timeout/size-cap literals in outbound-HTTP-calling code) and the same mechanical fix shape (extract to `config('partna.*')` or `config('services.*')` + `.env.example` entries). Both are S-effort and independent of each other.
    - **Model:** Plan Opus · Implement Sonnet · Review Sonnet — no escalation needed, both are small mechanical extractions.

## Standalone — do NOT bundle

None — neither finding touches auth/authorization, money, or a DB migration/schema change, and both are S-effort.
