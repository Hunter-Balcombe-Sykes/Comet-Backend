# API Contract & Resource Leakage Audit — 2026-07-09

**Branch:** development
**Lens:** API Contract & Resource Leakage: raw model fields bleeding through, over-fetching, inconsistent pagination
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Http/Resources/`
- `app/Http/Controllers/Api/User/`
- `app/Http/Controllers/Api/ApiController.php`
- `app/Http/Controllers/Api/PublicSite/`
- `app/Http/Controllers/Api/Staff/`
- `app/Http/Controllers/Api/Internal/`
- `app/Http/Controllers/Api/Platforms/`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 7 complete

---

## P2 — Should fix

- [ ] **#API-1** · P2 — `StaffAccountDeletionController::show` returns a raw Eloquent collection instead of a Resource
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffAccountDeletionController.php:99-103, 111
    - **Affects:** Staff support tooling (`GET /staff/professionals/{professional}/deletion`) — any column added to `UserDeletionAuditEntry` in the future ships to the API automatically, with no allowlist gate to catch it.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `UserDeletionAuditEntryResource` (or similarly named) that explicitly allowlists `id`, `event`, `actor_type`, `reason`, `metadata`, `created_at`.
        - Replace `'audit_entries' => $auditEntries` with `'audit_entries' => UserDeletionAuditEntryResource::collection($auditEntries)`.
    - **Technical:** The query already limits the *select* to non-PII columns (`get(['id', 'event', 'actor_type', 'reason', 'metadata', 'created_at'])`), which is a good defensive habit, but the result is still an Eloquent `Collection` handed straight to `$this->success()` — it never passes through the platform's mandated Resource-class transformation gate (CLAUDE.md: "Resource classes for all API responses — never return raw Eloquent models"). Today the query-level column allowlist happens to prevent a leak, but that allowlist lives in a different place than the response contract, so a future edit to the `get([...])` call (e.g. adding a column for a new feature) bypasses the Resource layer entirely and ships straight to staff clients with no second gate.
    - **Plain English:** Support staff pull up a professional's account-deletion history, and right now the backend photocopies the raw database rows straight into the response instead of using a proper "packing list" that says exactly what's allowed out the door. Nothing sensitive leaks today because someone remembered to limit which columns get selected, but that's a fragile safety net — the next person who touches this code has no guardrail stopping them from accidentally including something that shouldn't be there.
    - **Evidence:**
        ```php
        $auditEntries = UserDeletionAuditEntry::query()
            ->where('user_id', $professional->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'event', 'actor_type', 'reason', 'metadata', 'created_at']);

        return $this->success([
            'status' => $professional->status,
            'deletion_requested_at' => optional($professional->deletion_requested_at)->toIso8601String(),
            'deletion_confirmed_at' => optional($professional->deletion_confirmed_at)->toIso8601String(),
            'deletes_at' => $deletesAt,
            'previous_status' => $professional->deletion_previous_status,
            'audit_entries' => $auditEntries,
        ]);
        ```

## P3 — Nice to have

- [ ] **#API-2** · P3 — `UserPublicResource` is dead code (zero controller call sites), previously flagged for deletion and never removed
    - **Where:** app/Http/Resources/UserPublicResource.php
    - **Affects:** No live traffic today — the risk is latent: a future developer wiring a new public endpoint could reach for this unlabelled, seemingly-legitimate class instead of `IndividualProfileResource` (the actual public-profile Resource), and it hasn't been audited against the current skeleton-system contract.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Confirm no route resolves to it (`grep -r "UserPublicResource" app/Http/Controllers`), then delete `app/Http/Resources/UserPublicResource.php` and its dedicated test `tests/Feature/Resources/UserPublicResourceTest.php`.
        - If it's being kept intentionally as a template, add an `@internal`/`@deprecated` docblock explaining why it still exists.
    - **Technical:** A repo-wide grep finds `UserPublicResource` referenced only by its own class file and its own test — no controller imports it. `IndividualProfileResource` is the Resource actually wired to the public-profile endpoint. This exact class was already flagged as orphaned in the archived 2026-06-13 API-contract audit (`audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-api-contract.md`), which recommended deleting it alongside the now-already-deleted `UserResource.php`. That earlier recommendation for `UserPublicResource` was never carried out.
    - **Plain English:** There's a filing tray labelled "for the public" sitting in the cabinet that nobody actually uses anymore — the real public tray is a different, newer one. It's not dangerous today, but a previous review already said to throw this one away and it's still sitting there, which means a future developer could mistake it for the real thing.
    - **Evidence:**
        ```php
        // app/Http/Resources/UserPublicResource.php — grep across app/, routes/, tests/
        // finds zero controller import sites; only self-reference + its own test.
        class UserPublicResource extends ApiResource
        {
            public function toArray(Request $request): array
            {
                return [
                    'id' => (string) $this->id,
                    'account_type' => $this->account_type?->value,
                    ...
        ```

- [ ] **#API-3** · P3 — `DocumentMediaResource`, `SiteMediaResource`, and `StaffUserListResource` omit the mandatory `(string)` cast on `id`
    - **Where:** app/Http/Resources/DocumentMediaResource.php:31, app/Http/Resources/SiteMediaResource.php:42, app/Http/Resources/Staff/StaffUserListResource.php:34
    - **Affects:** Any strict-typed API consumer (TypeScript/Zod) that expects `id` to always be a `string` — currently harmless since Postgres UUIDs already serialise as strings, but the codebase's own base class documents this as a mandatory contract.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Cast all three: `'id' => (string) $media->id` (DocumentMediaResource, SiteMediaResource), `'id' => (string) $this->resource->id` (StaffUserListResource).
        - No CI check currently enforces this — consider a lightweight static assertion sweep over `app/Http/Resources/` alongside `PolicyCoverageTest`-style sweep tests if this recurs.
    - **Technical:** `App\Http\Resources\ApiResource` (the shared base class every Resource extends) carries an explicit doctrine comment: "Resources emitting an `id` field MUST cast it to string... UUIDs serialise the same either way, but the cast keeps the type stable for strict-typed consumers... and survives a future int-keyed table without a contract break." `ContentLibraryUploadResource::toArray()` (`'id' => (string) $media->id,`) and the vast majority of other Resources follow this; these three are the confirmed outliers.
    - **Plain English:** Most ID fields in this API carry an explicit "always text" stamp, guaranteeing they'll read correctly even if the database ever changes how it stores IDs. Three files forgot the stamp. Nothing is broken today — this is a small inconsistency the codebase's own rulebook already calls out.
    - **Evidence:**
        ```php
        // DocumentMediaResource.php:31
        'id' => $media->id,

        // SiteMediaResource.php:42
        'id' => $media->id,

        // StaffUserListResource.php:34
        'id' => $this->resource->id,

        // Contrast — ApiResource.php:11-16 (doctrine comment) and
        // ContentLibraryUploadResource.php:28: 'id' => (string) $media->id,
        ```

- [ ] **#API-4** · P3 — Several media controllers call `->toArray(request())` manually instead of `->resolve()`
    - **Where:** app/Http/Controllers/Api/User/Account/UserDocumentController.php:43,205,249; app/Http/Controllers/Api/User/Content/ContentController.php:63,101; app/Http/Controllers/Api/User/Uploads/UserUploadController.php:94,181; app/Http/Controllers/Api/User/Uploads/UserDesignMediaController.php:54,85
    - **Affects:** Consistency of the transformation gate — none of the affected Resources (`DocumentMediaResource`, `SiteMediaResource`, `ContentLibraryUploadResource`, `DesignMediaResource`) currently use `$this->when()`/`$this->whenLoaded()`, so there's no active leak, but the pattern is a footgun if one is ever added.
    - **Effort:** S (~1h, mechanical replace across 8 call sites)
    - **What to do:**
        - Replace every `(new FooResource($model))->toArray(request())` with `(new FooResource($model))->resolve()`, matching the convention already used elsewhere (`UserEnquiryController`, `UserServiceController` use `->resolve()`).
    - **Technical:** `JsonResource::resolve()` calls `toArray()` and then runs the result through `filter()`, which strips out `Illuminate\Http\Resources\MissingValue` instances produced by `$this->when()` conditionals that evaluate false. Calling `->toArray($request)` directly (as these eight call sites do) skips that filter step — if any of these Resources ever gain a `$this->when(...)` field, the raw `->toArray()` path would serialize a `MissingValue` object into the JSON response instead of omitting the key. Today none of the four affected Resources use `when()`, so there is no active data leak — this is a consistency/footgun fix, not an active bug.
    - **Plain English:** There's a correct way to unwrap one of these API "packages" that also checks for anything marked "only include if applicable," and a shortcut that skips that check. Right now none of these particular packages have that kind of conditional content, so nothing goes wrong — but if someone adds one later without knowing about the shortcut, a stray internal marker could end up in the response instead of being cleanly left out.
    - **Evidence:**
        ```php
        // UserDocumentController.php:43
        'document' => $media ? (new DocumentMediaResource($media))->toArray(request()) : null,

        // ContentController.php:63
        ->map(fn (SiteMedia $m) => (new ContentLibraryUploadResource($m))->toArray($request))

        // UserUploadController.php:94
        return $this->success((new SiteMediaResource($media, includeVariants: true))->toArray(request()), 201);

        // UserDesignMediaController.php:85
        return $this->success((new DesignMediaResource($media))->toArray(request()), 201);
        ```

- [ ] **#API-5** · P3 — `SessionController` bypasses the `ApiController` response helpers and mixes raw `response()->json()` with `$this->error()` in the same class
    - **Where:** app/Http/Controllers/Api/User/Account/SessionController.php:42,56,66,77,90,97,103
    - **Affects:** Error-shape consistency for session management (`/api/sessions/*`) — `destroy()` already returns a bare `response()->json(null, 404)` today (not the standard `{message, errors}` envelope), sitting right next to a call to `$this->error(...)` in the same method.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `response()->json(['sessions' => $sessions])` → `$this->success(['sessions' => $sessions])`.
        - Replace the three `response()->json(null, 204)` calls → `$this->success(null, 204)`.
        - Replace `response()->json(['revoked' => $count])` → `$this->success(['revoked' => $count])`.
        - Replace `response()->json(null, 404)` (line 97) → `$this->error('Session not found.', 404)` so the 404 gets the standard envelope, matching the `$this->error(...)` call three lines above it in the same method.
    - **Technical:** Every other controller under the User API surface extends `ApiController` and uses `$this->success()`/`$this->error()`. `SessionController` is the sole holdout, calling `response()->json()` directly for six of its seven return paths — and inconsistently even within itself: `destroy()` uses `$this->error(...)` for the 400 case but a raw, envelope-less `response()->json(null, 404)` for the not-owned case. A client parsing errors by `{message, errors}` shape gets no `message` key on that 404 today.
    - **Plain English:** Every other part of the app rings up responses the same standard way, but the session-logout code brought its own register. It mostly works, but one of its error replies is already missing the standard receipt fields that every other error reply has, which means a frontend that reads error messages generically won't see anything for this one.
    - **Evidence:**
        ```php
        // SessionController.php:42
        return response()->json(['sessions' => $sessions]);

        // SessionController.php:90-97
        if ($sessionId === $currentSessionId) {
            return $this->error('Use /sessions/logout to end the current session.', 400);
        }
        ...
        if (! in_array($sessionId, $owned, true)) {
            return response()->json(null, 404);
        }
        ```

- [ ] **#API-6** · P3 — Platform-dashboard controllers and the public menu endpoint hand-build response arrays instead of dedicated Resource classes
    - **Where:** app/Http/Controllers/Api/Platforms/OnlineOrderingController.php (`entries()`, `entriesData()`, `consolidateEntry()`); app/Http/Controllers/Api/Platforms/BookingController.php (`status()`, `statusFor()`, `shapeCustom()`); app/Http/Controllers/Api/Platforms/ReservationsController.php (`status()`, `statusFor()`, `shapeCustom()`); app/Http/Controllers/Api/Platforms/MenuController.php (`show()`, `categories()`, `platforms()`); app/Http/Controllers/Api/PublicSite/PublicMenuController.php:60-88
    - **Affects:** Authenticated platform dashboard endpoints and the public menu endpoint (`GET /api/public/profiles/{handle}/menu`) — no field currently leaks (every array is hand-built with an explicit key list, not a model passthrough), but there's no dedicated class enforcing the contract if a field is added later.
    - **Effort:** L (~1–2d to cover all five controllers with dedicated Resource classes)
    - **What to do:**
        - Lowest priority: this is architecture-doctrine alignment, not a leak fix. If pursued, create per-shape Resources (`OnlineOrderingEntryResource`, `BookingStatusResource`, `PublicMenuResource` + item Resource, etc.) and swap the hand-built arrays for `Resource::collection(...)`/`new Resource(...)` calls.
        - Given the effort and zero current risk, this is reasonable to defer indefinitely in favor of higher-value work — flagging for visibility, not urgency.
    - **Technical:** CLAUDE.md's "Do NOT" list says never return raw Eloquent models, and the broader doctrine is "Resource classes for all API responses." These controllers technically deviate from that by hand-assembling arrays in private methods (`entriesData()`, `statusFor()`, `shapeCustom()`, `categories()`) rather than using `JsonResource` subclasses. However, every field in every one of these methods is explicitly named — none pass a model or DTO through wholesale — so the practical leak risk that Resource classes exist to prevent is already mitigated by the explicit allowlisting. This is a consistency gap, not an active vulnerability.
    - **Plain English:** Most of the app prints a formal receipt listing exactly what's included in each response. This handful of integration-status endpoints instead hand-writes the list item by item every time — which happens to be correct and safe today, since every field is deliberately chosen — but it's a different mechanism than the rest of the app uses, so it's harder to audit as a group later.
    - **Evidence:**
        ```php
        // OnlineOrderingController.php:44-47
        public function entries(Request $request): JsonResponse
        {
            return $this->success(['entries' => $this->entriesData($this->currentUser($request))]);
        }

        // PublicMenuController.php:82-88
        return $this->success([
            'data' => [
                'storeName' => $menu->store_name,
                'currency' => $currency,
                'categories' => $categories,
            ],
        ]);
        ```

- [ ] **#API-7** · P3 — Enquiry detail endpoint wraps its response in a generic `data` key while every other show endpoint uses a named resource key
    - **Where:** app/Http/Controllers/Api/User/Customers/UserEnquiryController.php:102
    - **Affects:** Frontend code fetching enquiry details — the response shape is `{data: {...}}` instead of `{enquiry: {...}}`, unlike every other single-resource show endpoint in the same file family.
    - **Effort:** S (~0.5–1h, coordinate the frontend key rename)
    - **What to do:**
        - Change `$this->success(['data' => ...])` to `$this->success(['enquiry' => ...])`, matching `UserEnquiryController::update()` (same file, line 131, already uses `'enquiry' => ...`), `UserCustomerController::show()` (`'customer'`), and `UserServiceController::show()` (`'service'`).
        - Coordinate the frontend destructuring key change in the same PR.
    - **Technical:** The project convention — visible even within this same controller's own `update()` method three lines below — is `{resource_name: Resource}` for single-resource responses. `show()` is the one outlier using `{data: Resource}`. List endpoints legitimately use `data` (via `paginatedResponse()`), so there's precedent for `data` in paginated contexts, but not for single-resource `show()` responses.
    - **Plain English:** Every other detail page in this API puts its content in a box labeled with what's inside — "customer," "feedback," "service" — including this very same controller's own update endpoint. Only its show endpoint uses a generic box labeled "data" instead, which means the frontend developer has to remember a special rule just for this one response.
    - **Evidence:**
        ```php
        // UserEnquiryController.php:102 — show()
        return $this->success(['data' => (new EnquiryDetailResource($enquiry))->resolve()]);

        // Same file, UserEnquiryController.php:130-132 — update()
        return $this->success([
            'enquiry' => (new EnquiryResource($enquiry->fresh()))->resolve(),
        ]);
        ```

- [ ] **#API-8** · P3 — Service and service-category list endpoints use hard `->limit()` caps instead of real pagination, and the staff category endpoint has no bound at all
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php:62; app/Http/Controllers/Api/User/SiteManagement/UserServiceCategoryController.php:41; app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php:43; app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceCategoryManagementController.php:36
    - **Affects:** Professionals (and staff viewing on their behalf) with more than 500 services or 200 categories — items beyond the cap silently never appear, with no pagination metadata to signal more exist. The staff category endpoint (`StaffServiceCategoryManagementController::index`) has **no limit at all**, unlike the other three call sites in the same family.
    - **Effort:** M (~2–4h backend; true pagination needs frontend coordination, called out below)
    - **What to do:**
        - This is an already-tracked, deliberate stopgap: both user-facing controllers carry an explicit comment — "Bound the query at scale (B18/API-4). True pagination is a frontend-coordinated change, deferred." — from a prior audit cycle. No new urgency here beyond what's already been triaged.
        - The one gap worth closing on its own (low effort, no frontend coordination needed): add `->limit(200)` to `StaffServiceCategoryManagementController::index()` to match its sibling `StaffServiceManagementController` and the user-facing category endpoint, so staff tooling has the same bound as everything else in this family.
        - Full `paginate()` migration remains deferred pending frontend pagination-control work, consistent with the existing B18/API-4 decision.
    - **Technical:** Given the "individual sitepages, small per-user data" scale context, 500+ services / 200+ categories for one professional is an unlikely edge case pre-pilot, and the team already explicitly reviewed and deferred this exact gap (comment references a prior audit ID). The one new observation is that `StaffServiceCategoryManagementController::index()` (line 36) has zero `limit()` call — the only one of the four sibling endpoints with no bound whatsoever — which is a straightforward, low-risk fix to bring it in line with its own family rather than a net-new scope item.
    - **Plain English:** If a professional builds up more than 500 services, the list just quietly stops at 500 with no "there's more" indicator — the team already knows about this and has a plan to fix it properly once the frontend adds pagination controls. One corner of it (the staff-side category list) doesn't even have that safety cap, unlike its three siblings, which is worth a five-minute fix on its own.
    - **Evidence:**
        ```php
        // UserServiceController.php:62
        $services = $servicesQuery->orderBy('sort_order')->orderBy('created_at')->limit(500)->get();

        // UserServiceCategoryController.php:41
        $categories = $q->orderBy('sort_order')->orderBy('created_at')->limit(200)->get();

        // StaffServiceManagementController.php:40-44
        // mirrors user-facing cap (UserServiceController::index limit(500))
        $services = $servicesQ
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->limit(500)
            ->get();

        // StaffServiceCategoryManagementController.php:36 — no limit() at all
        $categories = $q->orderBy('sort_order')->orderBy('created_at')->get();
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Response-envelope & Resource hygiene sweep:** #API-2, #API-3, #API-4, #API-5, #API-7
    - **Why grouped:** all five are small (S-effort), mechanical, non-functional consistency fixes across Resources/Controllers — safe to knock out in one session with a single review pass.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

- **Bundle 2 — Staff service-category listing bound:** #API-8 (the `StaffServiceCategoryManagementController::index` limit-add only; the broader pagination migration stays deferred per the existing B18/API-4 decision)
    - **Why grouped:** single-file, single-line addition matching an existing sibling pattern — no dependency on the other bundle.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

- **#API-1 — StaffAccountDeletionController raw collection return** · standalone: touches the account-deletion audit trail (sensitive DINT-adjacent data surface) and is the only P2 in this batch — warrants its own plan + sign-off rather than folding into a polish sweep.
- **#API-6 — Platform-dashboard hand-rolled arrays (5 controllers)** · standalone: L-effort item spanning five files; also lowest-urgency (no confirmed leak) so it shouldn't block or dilute the smaller bundles above.
