# API Contract — Audit 2026-05-20

**Branch:** development
**Lens:** API contract response shape and validation coverage
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/PublicSite/BootstrapController.php
- app/Http/Controllers/Api/PublicSite/PublicBookingController.php
- app/Http/Controllers/Api/PublicSite/PublicBrandAffiliateInviteController.php
- app/Http/Controllers/Api/PublicSite/PublicConfigController.php
- app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php
- app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php
- app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php
- app/Http/Controllers/Api/PublicSite/PublicEmailUnsubscribeController.php
- app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php
- app/Http/Controllers/Api/PublicSite/PublicMarketingPreferenceController.php
- app/Http/Controllers/Api/PublicSite/PublicOpenInviteController.php
- app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php
- app/Http/Controllers/Api/PublicSite/PublicSignupAvailabilityController.php
- app/Http/Controllers/Api/PublicSite/PublicSiteController.php
- app/Http/Controllers/Api/PublicSite/PublicWaitlistController.php
- app/Http/Controllers/Api/PublicSite/QrCodeController.php
- app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php
- app/Http/Requests/Api/Professional/Site/UpdateLinkBlockRequest.php
- app/Http/Requests/BaseFormRequest.php (and all other Form Request files audited)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#APIC-1** · P2 — `IndividualProfileController` speaks a different response language than every other public endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:68 (404 path), :86 (success path)
    - **Affects:** Any client consuming `GET /api/public/profiles/{handle}` — the Astro Worker subrequest and any frontend code that parses this endpoint must handle a unique JSON shape not shared by any other public controller.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `extends Controller` to `extends ApiController` in `IndividualProfileController`.
        - Replace `return response()->json(['message' => 'Not found.'], 404)` with `return $this->error('Not found.', 404)`.
        - Replace `return response()->json(['data' => $payload])` with `return $this->success($payload)`.
        - Verify the Astro Worker subrequest parses `data` from the `ApiController` envelope (i.e. `response.data.data`) and update if needed — this is the one mechanical cross-stack change the fix introduces.
    - **Technical:** Every other public controller in this namespace — `AnalyticsController`, `BootstrapController`, `PublicBookingController`, `PublicBrandAffiliateInviteController`, `PublicCustomerLeadController`, `PublicEmailSubscriptionController`, `PublicEnquiryController`, `PublicMarketingPreferenceController`, `PublicOpenInviteController`, `PublicShopifyStorefrontController`, `PublicSignupAvailabilityController`, `PublicSiteController`, `PublicWaitlistController` — all extend `ApiController` and emit `{"success":true,"data":{...}}` / `{"success":false,"message":"..."}`. `IndividualProfileController` was added in §28.8 (`feat(public-site): §28.8`, commit `0449531a`) and extended the bare `Controller` class, creating a silent contract fork. Clients that check `response.success` before reading `response.data` break on this endpoint.
    - **Plain English:** Every door in our building opens with the same key except one — the brand-new door that was just installed. Anyone who makes a master key for the building will find it doesn't work on that one door. The individual profile endpoint responds in a different format from every other public endpoint. Code that reads our API by checking a "did it work?" flag first, then reading the content, will fail silently on this endpoint without an obvious error message.
    - **Evidence:**
        ```php
        // IndividualProfileController extends Controller (not ApiController)
        class IndividualProfileController extends Controller
        ```
        ```php
        // 404 path — no `success` flag, no envelope
        return response()->json(['message' => 'Not found.'], 404);
        ```
        ```php
        // success path — `data` key present but no `success: true` flag
        return response()->json(['data' => $payload]);
        ```
        Compare with every other public controller:
        ```php
        // e.g. PublicBrandAffiliateInviteController (extends ApiController)
        return $this->success([
            'invite' => [...],
        ]);
        ```

- [ ] **#APIC-2** · P2 — `StoreLinkBlockRequest` omits the per-site `live_check_enabled` cap enforced on update, allowing cap bypass via create
    - **Where:** app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php (`withValidator` — missing check) vs app/Http/Requests/Api/Professional/Site/UpdateLinkBlockRequest.php:253–278 (contains the check)
    - **Affects:** Any professional or brand who creates link blocks with `live_check_enabled: true`; they can exceed the per-site streaming poll cap by sending create requests instead of editing existing blocks, bypassing both the dashboard UX limit and the backend guard.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the `live_check_enabled` cap check to `StoreLinkBlockRequest::withValidator()`, mirroring the logic in `UpdateLinkBlockRequest`.
        - On create, there is no "current block" to exclude from the count — drop the `$currentBlockId` exclusion clause (or pass `null` to the `when()`).
        - Resolve `$siteId` from the professional's site record (via `$pro = $this->route('professional') ?? $this->attributes->get('professional'); $pro->site->id`) since there is no route-bound block yet on create.
    - **Technical:** `UpdateLinkBlockRequest::withValidator()` checks `settings.live_check_enabled`, resolves the bound `linkBlock` model's `site_id`, excludes the current block from the count, then compares against `config('partna.streaming.max_live_check_per_site', 5)`. `StoreLinkBlockRequest::withValidator()` already enforces the platform-links cap by querying `Block` with the professional's ID, but it contains no equivalent clause for `live_check_enabled`. The staff-facing `StaffStoreLinkRequest` inherits from `StoreLinkBlockRequest` and therefore also lacks the guard. Since `StaffStoreLinkRequest extends StoreLinkBlockRequest`, fixing the parent fixes the staff path too.
    - **Plain English:** Imagine a concert venue that limits each section to five standing-room tickets. The rule is checked when you *upgrade* an existing ticket to standing room, but not when you *buy* a new standing-room ticket directly. Someone who knows this can just keep buying new tickets and fill the section beyond capacity. The same gap exists here: the limit on how many "live status" badges a profile page can show is checked when you edit an existing badge, but not when you create a new one from scratch.
    - **Evidence:**
        `UpdateLinkBlockRequest` (the check that exists):
        ```php
        if (is_array($settings) && array_key_exists('live_check_enabled', $settings) && (bool) $settings['live_check_enabled']) {
            $currentBlock = $this->route('linkBlock') ?? $this->route('block');
            $siteId = is_object($currentBlock) ? ($currentBlock->site_id ?? null) : null;
            $currentBlockId = is_object($currentBlock) && method_exists($currentBlock, 'getKey')
                ? (string) $currentBlock->getKey()
                : null;
            if ($siteId) {
                $cap = (int) config('partna.streaming.max_live_check_per_site', 5);
                $existing = \App\Models\Core\Site\Block::query()
                    ->where('site_id', $siteId)
                    ->where('block_group', 'links')
                    ->when($currentBlockId, fn ($q) => $q->where('id', '!=', $currentBlockId))
                    ->whereRaw("settings->>'live_check_enabled' = 'true'")
                    ->count();
                if ($existing >= $cap) {
                    $validator->errors()->add(
                        'settings.live_check_enabled',
                        "You can enable live status checking on at most {$cap} link blocks per site."
                    );
                }
            }
        }
        ```
        `StoreLinkBlockRequest::withValidator()` — no equivalent block present. The method closes after the platform-links cap check and the settings allowlist check, with no `live_check_enabled` clause.

`★ Insight ─────────────────────────────────────`
**Why controller base-class drift is subtle but painful:** `IndividualProfileController` compiles and runs perfectly — Laravel doesn't enforce that public controllers share a base class. The bug only surfaces at client-parse time, often in a frontend branch that conditionally reads `response.success`. This is a common pattern when a feature branch adds a new controller without copying the `extends ApiController` from adjacent files.

**Validation symmetry between create and update:** The `live_check_enabled` cap is a good example of a guard that's easy to add on update (you have the bound model for context) but easy to forget on create (no model yet, so you have to derive `site_id` differently). When a `withValidator()` check is added to one of a Store/Update pair, it's worth immediately adding a note in the PR to do the same for the other — or better, extracting the shared check into a protected method on a shared base class.

**Staff requests that inherit from professional requests:** `StaffStoreLinkRequest extends StoreLinkBlockRequest` means fixing the parent fixes the staff path for free. This inheritance pattern is clean when parent validation is strictly correct — but it also means parent gaps silently propagate to the staff surface, which typically has fewer automated test assertions.
`─────────────────────────────────────────────────`
