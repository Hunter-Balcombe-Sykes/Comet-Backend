# Security Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Security — auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Models/Analytics/ActionEvent.php
- config/partna.php
- app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php
- app/Http/Requests/Api/PublicSite/Analytics/ActionSeenRequest.php
- app/Http/Requests/Api/PublicSite/Analytics/ActionTapRequest.php
- app/Http/Requests/Concerns/SiteOrderingValidationRules.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/DataExport/DataExportPayloadBuilder.php
- app/Http/Controllers/Concerns/ResolvesSiteFromRequest.php (adjacent, pulled for verification)
- app/Models/Core/User/UserDeletionAuditEntry.php (adjacent, pulled for verification)
- app/Services/Analytics/Writers/PostgresEventWriter.php (adjacent, pulled for verification)
- routes/api.php (adjacent, pulled for verification)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEC-1** · P1 — Origin-absent analytics fallback trusts publicly-known `site_id` + `subdomain`, letting a scripted caller forge another site's action/analytics events
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:465-478
    - **Affects:** Every published sitepage's `pageview`/`click`/`sectionSeen`/`itemSeen`/`actionSeen`/`actionTap`/`ping`/`sectionDwell` ingest endpoints. Fabricated `actionSeen`/`actionTap` events feed directly into `RankedActionsComputer`'s demand-rate scoring, so a scripted attacker can inflate or suppress another user's action ordering shown to real visitors.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `originAllowed()`, drop the "both `site_id` and `subdomain` present and matching" fallback and instead `return false` whenever `Origin` and `Referer` are both absent — every legitimate caller is the sitepage's own client-side JS beacon (IntersectionObserver / capture-phase click listener per the surrounding docblocks), which always emits one of the two headers; there is no documented server-side/synthetic caller today.
        - If a genuine no-header caller is later needed (e.g. a server-to-server integration), gate it with a distinct shared secret or signed request rather than public `site_id`/`subdomain`, which the resource itself already documents as world-readable.
    - **Technical:** `originAllowed()` correctly blocks browser-JS forgery — a script on `evil.partna.au` making a `fetch()` call carries an `Origin: https://evil.partna.au` header the browser will not let JS override, so that vector (and the narrower "site_id-alone" variant of this same bug) is already closed. The residual gap is the branch taken when `Origin` **and** `Referer` are both absent (any raw HTTP client that simply omits them, e.g. `curl`/`requests`): it authenticates the caller by checking that the POST body's `site_id` and `subdomain` match the resolved `$site` — but both values are public. `subdomain` is the site's own public handle, and the code's own comment on `site_id` says "exposed in public page payloads." This surface was introduced by the 2026-07-23 actions rebuild (`76ae1305 feat(analytics): action-seen/action-tap ingest endpoints`, `ea046b43 feat(analytics): add analytics.action_events raw event table`), which is what routes fabricated events into `RankedActionsComputer`'s scoring — the existing `resolveSiteFromData()` site_id/subdomain cross-check (IDOR guard, confirmed present) stops mismatched pairs but does nothing to stop a *matched*, entirely-public pair submitted with no browser present at all.
    - **Plain English:** The analytics system tries to prove a request really came from a visitor's browser by checking a hidden "return address" header that browsers can't fake. But it has a backup rule for when that header is missing: it trusts a request as long as it includes the site's ID number and web address — both of which anyone can find just by visiting the site's public page. So a script (not a real browser) can skip sending the "return address" header entirely, paste in those two public values, and the system waves it through as legitimate. That lets someone quietly stuff fake "someone tapped this button" events into another user's stats, skewing what content that user's real visitors see promoted.
    - **Evidence:**
        ```php
        if ($originHost === null) {
            if (empty($data['site_id']) || empty($data['subdomain'])) {
                return false;
            }

            return (string) $data['site_id'] === $site->id
                && strtolower((string) $data['subdomain']) === strtolower($site->subdomain);
        }
        ```

- [ ] **#SEC-2** · P1 — Admin-cancelled deletion never restores the user's email because the restore query only matches self-service confirmations
    - **Where:** app/Services/User/AccountDeletionService.php:1305-1317
    - **Affects:** Any user whose deletion was started by staff (`adminInitiate()`, `event = 'admin_initiated'`) and later cancelled by staff (`adminCancel()`). Status and the site are correctly restored, but `primary_email` stays `deleted+{id}@partna.au` — the user cannot receive password resets or any other account-recovery email until someone notices and fixes it by hand.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `restoreEmailFromAuditSnapshot()`'s `where('event', UserDeletionAuditEntry::EVENT_CONFIRMED)` to `whereIn('event', [UserDeletionAuditEntry::EVENT_CONFIRMED, UserDeletionAuditEntry::EVENT_ADMIN_INITIATED])`.
        - Add a test covering `adminInitiate()` → `adminCancel()` → assert `primary_email` is restored to the pre-pseudonymisation value (the existing `self-service cancel()` path presumably already has an equivalent test to mirror).
    - **Technical:** Confirmed by reading the full call graph: `executeConfirmation()` is shared by both `confirmDeletion()` (writes `EVENT_CONFIRMED`) and `adminInitiate()` (writes `EVENT_ADMIN_INITIATED`, per `UserDeletionAuditEntry::EVENT_ADMIN_INITIATED = 'admin_initiated'`), and both paths call `pseudonymiseAccountPii()`, overwriting `primary_email`. Both `cancel()` and `adminCancel()` route through the same `restoreSiteAndStatus()` → `restoreEmailFromAuditSnapshot()` helper, which hardcodes the audit-row lookup to `event = 'confirmed'`. An admin-initiated deletion's snapshot row is written under `event = 'admin_initiated'`, so the lookup returns `null` and the `is_string($snapshotEmail) && $snapshotEmail !== ''` guard silently no-ops — the account comes back `active` but is unreachable by email, with no error surfaced anywhere.
    - **Plain English:** Think of it like a hotel safe. When a guest checks out (self-service deletion), the hotel locks their valuables away and writes the combination on a slip marked "guest checkout." If the guest comes back and cancels, staff find that slip and reopen the safe. But if a manager starts the checkout on the guest's behalf instead, the slip gets labeled "manager checkout." When that same manager later cancels it, staff only ever look for slips marked "guest checkout" — they never find the manager's slip, so the safe stays locked. The valuables (the real email) are still there, just unreachable by the automatic process, and the account holder is silently locked out of email-based account recovery.
    - **Evidence:**
        ```php
        private function restoreEmailFromAuditSnapshot(User $professional): void
        {
            $snapshotEmail = DB::connection('pgsql')
                ->table('audit.user_deletion_audit')
                ->where('user_id', $professional->id)
                ->where('event', UserDeletionAuditEntry::EVENT_CONFIRMED)
                ->orderByDesc('created_at')
                ->value('professional_email_snapshot');

            if (is_string($snapshotEmail) && $snapshotEmail !== '') {
                $professional->forceFill(['primary_email' => $snapshotEmail])->save();
            }
        }
        ```

## P2 — Should fix

- [ ] **#SEC-3** · P2 — Non-array `payload` values bypass the public per-platform allowlist and would reach the CDN-cached public wire verbatim
    - **Where:** app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php:220-223
    - **Affects:** Unauthenticated sitepage visitors hitting `GET /api/public/profiles/{handle}/platforms` — only in the hypothetical case a `site.platform_connections.payload` row is ever written as a scalar (an error string, a stray token) instead of an array.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the `! is_array($payload)` branch to `return [];` (matching the fail-closed posture the unknown-platform branch a few lines below already uses), rather than passing the raw value through.
        - If a genuinely distinct "pending connection" wire shape is needed later, key it off an explicit field (e.g. `last_refreshed_at === null`) rather than the payload's PHP type.
    - **Technical:** `filterPayload()` allowlists every key of an array payload per-platform, and — per the adjacent `MissingPublicAllowlistException`/`Log::warning` branch — already fails closed (`return []`) for an unregistered platform. The `! is_array($payload)` guard is the one place that still passes the value through unmodified. I checked every current write site under `app/Services/Platforms/` (`FreshaServiceProjector`, `ShopProductSeeder`, `ShopBrandSeeder`, `EventsCatalog`, `EventsSeeder`, `InstagramConnectionSeeder`, `CustomLinkSeeder`, `InstagramAutoSync`, `GoogleBusinessAutoSync`, `OnDemandRefresh`, `ScheduledRefresh`) and every one assigns an array (or the `array`-cast model attribute), so there is no live path producing a scalar `payload` today — this is a defense-in-depth gap, not a currently-exploitable one.
    - **Plain English:** The public integrations feed acts like a bouncer checking every item in a bag before letting it through — but only if you actually hand over a bag (an array of fields). If a scraper or refresh job ever accidentally stores something else (a raw error message, a stray key) instead of the expected bag of fields, the bouncer waves it straight through untouched onto the public, cached page. Nothing does this today, but the code should refuse anything that isn't the expected shape rather than trust it by default.
    - **Evidence:**
        ```php
        // Null / non-array payloads (e.g. a pending connection) pass through.
        if (! is_array($payload)) {
            return $payload;
        }
        ```

## P3 — Nice to have

- [ ] **#SEC-4** · P3 — RUM beacon endpoint reads the raw request body instead of using a Form Request, unlike every sibling analytics method
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:600-635
    - **Affects:** Code consistency only — the `/api/public/analytics/rum` endpoint. Confirmed already covered by the same `throttle:analytics` middleware as its siblings (`routes/api.php:133-134`), so the log-flooding risk DeepSeek's draft raised does not hold; this is a validation-pattern inconsistency, not an active DoS gap.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract a `RumRequest` Form Request with rules for `handle` (the existing regex), and nullable-integer/boolean rules for `ttfb`, `dom`, `load`, `fcp`, `lkg`, matching the pattern every other method in this controller (`PageviewRequest`, `ClickRequest`, etc.) already follows.
        - No throttle change needed — `throttle:analytics` is already applied at the route.
    - **Technical:** `rum()` reads `$request->json()->all()` directly and validates only `handle` inline via `preg_match`; the timing/boolean fields are cast with bare `(int)`/`(bool)` rather than declared Form Request rules. This is inert today: the route already carries `->middleware('throttle:analytics')` (same group as `pageview`/`click`/etc.), the write is log-only (no DB row, no downstream scoring input), and every logged field is already coerced to a scalar type before logging. The gap is purely that this method doesn't match the established `FormRequest`-per-endpoint convention the rest of the controller follows, which matters for maintainability more than security.
    - **Plain English:** Every other data-collection endpoint on this page uses a standard "ID check" form to make sure incoming data is the right shape before accepting it; this one endpoint (which just records how fast a page loaded) skips that form and reads the raw data directly, doing its own ad-hoc checks inline instead. It's already rate-limited and the one meaningful field is already validated, so this is a tidiness fix, not an urgent one.
    - **Evidence:**
        ```php
        $payload = $request->json()->all();
        $handle = isset($payload['handle']) ? (string) $payload['handle'] : null;
        if (! $handle || ! preg_match('/^[a-z0-9-]{1,63}$/i', $handle)) {
            return $this->success(['message' => 'ok'], 200);
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Analytics ingest hardening:** #SEC-1, #SEC-4
    - **Why grouped:** Both live in `AnalyticsController.php` — the origin-fallback tightening and the RUM Form Request extraction touch adjacent methods in the same file and can be reviewed together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Public integration payload allowlist hardening:** #SEC-3
    - **Why grouped:** Single-file, single-line fail-closed change; no dependency on the other findings.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SEC-2 — Admin-cancelled deletion doesn't restore email** · reason: touches the GDPR-erasure/account-recovery data path in `AccountDeletionService` — run with its own plan + sign-off given the sensitivity of PII restoration correctness, even though the code change itself is a one-line `whereIn`.
