# Security Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Security — auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure (chunks: config-models, public-staff-surface, platforms-surface, outbound-platforms)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Controllers/Api/PublicSite/PublicMenuController.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Services/Platforms/EventSlugSync.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

No findings survived adjudication in this run. Four draft findings were verified and dropped:

- **`Menu::$fillable` includes `user_id` / `MenuItem::$fillable` includes `menu_id`** (draft SEC-1/SEC-2, config-models chunk): the raw excerpts are verbatim, but every write path was traced (`app/Http/Controllers/Api/Platforms/MenuController.php`, `MenuContentController.php`, `app/Services/Platforms/MenuScanApplier.php`, `app/Jobs/Platforms/MenuFetchJob.php`, plus every `Menu::create`/`MenuItem::create`/`new Menu(`/`new MenuItem(` call site repo-wide) and confirmed `user_id`/`menu_id` are only ever set from the server-resolved actor (`$user->id`) or an ownership-scoped lookup (`Menu::query()->where('user_id', $user->id)->first()`), never from `$request->all()` or an unvalidated request key. This is the doctrine's own skeleton-authorization pattern (`new Menu(['user_id' => $user->id])` feeding `authorizeForUser($user, 'update', $menu ?? new Menu(...))`, mirroring `SitePolicy::ownerMatches`) and is already annotated in-repo as intentional defense-in-depth (`MenuController.php` "SEC-106" comments). No attacker-reachable path pipes untrusted input into these fields — not a real mass-assignment vulnerability.
- **`report($e)` in slug-lookup catch blocks leaks to Nightwatch** (draft SEC-1, public-staff-surface chunk): verified verbatim in both `PublicIntegrationController.php` and `PublicMenuController.php`, but `report()` routing exception detail to Nightwatch is the project's *intended* observability path (per project doctrine: "a failure that needs attention must throw or `$this->fail($e)`; bare `Log::warning` is invisible"), not a leak to an external or user-facing surface — Nightwatch is the internal engineering monitor. The finding inverts the house pattern rather than identifying a real PII/secret exposure.
- **In-memory mutation of fetched `IntegrationConnection` rows before Resource resolution** (draft SEC-2, public-staff-surface chunk): verified verbatim, but mis-categorized as "mass assignment" (it is not — no `$fillable`/`fill()` involved), and no `->save()`/`->update()` call exists anywhere in either controller for these rows today. The risk requires a hypothetical future code change to manifest; it is speculative hardening advice, not a demonstrated security or data-integrity defect.

The remaining two draft chunks (`platforms-surface`, `outbound-platforms`) reported no findings, which a review of `EventSlugSync.php` and `PublicIntegrationConnectionResource.php` (fail-closed per-platform payload allowlist, no raw payload pass-through, correctly tenant-scoped queries in both public controllers, consistent 404-not-403 handling, throttled routes) corroborates.

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

None.
