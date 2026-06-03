# B16: Auth Policy Doctrine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close three auth policy gaps: add `authorizeForUser` to self-service write controllers that skip it, replace the false-security `authorizeCustomLinks()` gate with the skeleton pattern in link-block store/reorder, and wire `requiresFreshAal2()` into the sensitive policy methods so AAL2 is enforced for account update and deletion confirm.

**Architecture:** Three sequential tasks. P3-12 first (adds policy calls to `ProfessionalController` and `ProfessionalAccountDeletionController`) because P2-01's `requiresFreshAal2()` hooks land inside `ProfessionalSelfPolicy::update/delete` — those methods must be exercised by a controller call before the AAL2 gate has any effect. P2-02 (link-block) is independent but lands last as a natural cleanup pass.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, SQLite in-memory tests, `authorizeForUser` / `Gate::forUser`, `BasePolicy::requiresFreshAal2`.

---

### Task 1 — P3-12: Add `authorizeForUser` to `ProfessionalController::update` and `ProfessionalAccountDeletionController`

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/Account/ProfessionalController.php`
- Modify: `app/Http/Controllers/Api/Professional/Account/ProfessionalAccountDeletionController.php`
- Create: `tests/Feature/Security/PolicyEnforcement/ProfessionalSelfPolicyEnforcementTest.php`

**Background:**
- `ProfessionalController::update` mutates the professional's own profile but never calls `authorizeForUser`, so pending-deletion guard in `ProfessionalSelfPolicy::update` is never invoked.
- `ProfessionalAccountDeletionController::request` and `confirm` both act on the professional but bypass the policy entirely.
- The policy is `ProfessionalSelfPolicy`, registered as `Gate::policy(User::class, ProfessionalSelfPolicy::class)`. The controller resolves the user via `$request->attributes->get('professional')`.
- `authorizeForUser($user, 'update', $user)` is the right call when the actor and the resource are the same model (their own User record).

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Http\Controllers\Api\Professional\Account\ProfessionalAccountDeletionController;
use App\Http\Controllers\Api\Professional\Account\ProfessionalController;
use App\Http\Requests\Api\Professional\UpdateProfessionalRequest;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
});

// ── ProfessionalController::update ─────────────────────────────────────────

it('blocks pending-deletion professional from updating their profile (423)', function () {
    $pro = createTenant('self-update-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro->refresh();

    $req = tenantRequestAs($pro, ['display_name' => 'Hacked'], 'PATCH');

    try {
        app(ProfessionalController::class)->update(
            UpdateProfessionalRequest::createFrom($req)
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('allows active professional to update their own profile (200)', function () {
    $pro = createTenant('self-update-active');
    $req = tenantRequestAs($pro, ['display_name' => 'New Name'], 'PATCH');

    $response = app(ProfessionalController::class)->update(
        UpdateProfessionalRequest::createFrom($req)
    );

    expect($response->getStatusCode())->toBe(200);
});

// ── ProfessionalAccountDeletionController::request ─────────────────────────

it('blocks pending-deletion professional from re-requesting deletion (409 from service, policy passes)', function () {
    // pending_deletion pros are blocked by service logic (409), not policy —
    // the policy blocks only suspended/disabled abuses. Verify the 409 path still works
    // after the policy call is added (i.e., the policy doesn't short-circuit it).
    $pro = createTenant('del-req-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro->refresh();

    $req = tenantRequestAs($pro, [], 'POST');
    $req->attributes->set('professional', $pro);

    $response = app(ProfessionalAccountDeletionController::class)->request($req);
    expect($response->getStatusCode())->toBe(409);
});

// ── ProfessionalAccountDeletionController::confirm ─────────────────────────

it('blocks pending-deletion professional from confirming deletion via policy (423)', function () {
    // pending_deletion means denyIfPendingDeletion fires on ProfessionalSelfPolicy::update.
    // confirm() calls authorizeForUser($pro, 'update', $pro) — 423 expected.
    $rawToken = 'raw-token-' . Str::random(54);
    $pro = createTenant('del-confirm-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status'               => 'pending_deletion',
        'deletion_token_hash'  => hash('sha256', $rawToken),
        'deletion_requested_at'=> now()->toIso8601String(),
    ]);
    $pro->refresh();

    $req = tenantRequestAs($pro, ['token' => $rawToken], 'POST');
    $req->attributes->set('professional', $pro);

    try {
        app(ProfessionalAccountDeletionController::class)->confirm($req);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});
```

Save as `tests/Feature/Security/PolicyEnforcement/ProfessionalSelfPolicyEnforcementTest.php`.

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && \
  composer test -- --filter ProfessionalSelfPolicyEnforcementTest 2>&1 | tail -30
```

Expected: FAIL — "update" tests pass (policy call missing → no 423) or test framework errors.

- [ ] **Step 3: Add `authorizeForUser` to `ProfessionalController::update`**

In `app/Http/Controllers/Api/Professional/Account/ProfessionalController.php`, replace:

```php
    public function update(UpdateProfessionalRequest $request)
    {
        $professional = $this->currentProfessional($request);
        DB::transaction(function () use ($professional, $request): void {
```

with:

```php
    public function update(UpdateProfessionalRequest $request)
    {
        $professional = $this->currentProfessional($request);
        // Policy: denyIfPendingDeletion + owner check (ProfessionalSelfPolicy::update).
        $this->authorizeForUser($professional, 'update', $professional);
        DB::transaction(function () use ($professional, $request): void {
```

- [ ] **Step 4: Add `authorizeForUser` to `ProfessionalAccountDeletionController::confirm`**

In `app/Http/Controllers/Api/Professional/Account/ProfessionalAccountDeletionController.php`, the `confirm` method currently starts with:

```php
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'min:32'],
        ]);

        /** @var User $professional */
        $professional = $request->attributes->get('professional');

        $result = $this->deletionService->confirm($professional, (string) $request->input('token'), $request);
```

Replace with:

```php
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'min:32'],
        ]);

        /** @var User $professional */
        $professional = $request->attributes->get('professional');
        // Policy gate: denyIfPendingDeletion prevents double-confirmation race.
        // AAL2 freshness wired here via ProfessionalSelfPolicy::update after P2-01.
        $this->authorizeForUser($professional, 'update', $professional);

        $result = $this->deletionService->confirm($professional, (string) $request->input('token'), $request);
```

Note: `request()` and `confirm()` share the User model — `authorizeForUser` added only to `confirm` (high-risk irreversible action). `request` already guards via service-layer status checks (409 for pending_deletion, 403 for suspended/disabled), which remain sufficient for that lower-risk initiation step.

- [ ] **Step 5: Run tests to verify they pass**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && \
  composer test -- --filter ProfessionalSelfPolicyEnforcementTest 2>&1 | tail -20
```

Expected: all green.

- [ ] **Step 6: Run full test suite**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && composer test 2>&1 | tail -20
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && \
  git add \
    app/Http/Controllers/Api/Professional/Account/ProfessionalController.php \
    app/Http/Controllers/Api/Professional/Account/ProfessionalAccountDeletionController.php \
    tests/Feature/Security/PolicyEnforcement/ProfessionalSelfPolicyEnforcementTest.php && \
  git commit -m "fix(auth): add authorizeForUser to ProfessionalController::update and deletion confirm (#P3-12)"
```

---

### Task 2 — P2-01: Wire `requiresFreshAal2()` into `ProfessionalSelfPolicy` for update and deletion confirm

**Files:**
- Modify: `app/Policies/ProfessionalSelfPolicy.php`
- Modify: `tests/Feature/Security/PolicyEnforcement/ProfessionalSelfPolicyEnforcementTest.php`

**Background:**
- `BasePolicy::requiresFreshAal2()` reads `supabase_aal` and `supabase_amr` request attributes (set by `VerifySupabaseJwt` middleware) and returns a 401 deny if the user hasn't completed MFA within `config('partna.mfa.fresh_window_seconds')` (default 300s).
- The audit specifies: wire into `ProfessionalSelfPolicy::update` for "profile update" and the deletion confirm call site. Since `confirm` routes through `ProfessionalSelfPolicy::update`, a single change to `update` covers both.
- Do **not** add it to `view` or `delete`-via-admin — only high-risk self-mutations.
- The MFA-foundation doc notes that during rollout, not all users have TOTP set up. `requiresFreshAal2` returns 401 with message `"Recent MFA verification required"` — frontend triggers step-up challenge if the user has TOTP enrolled, or gracefully degrades if not. This is acceptable: the gate is applied at policy layer, users without TOTP enrolled will always fail `requiresFreshAal2` until they enrol.
- **Conditional application**: only fire the fresh-AAL2 check if MFA is enabled for the user (check `config('partna.mfa.require_for_profile_update', false)` feature flag). This lets us gate rollout without changing policy code later.

- [ ] **Step 1: Add `mfa.require_for_profile_update` config key**

In `config/partna.php`, find the `mfa` array and add the new key. Locate:

```php
'mfa' => [
```

Inside that array, add after the existing `fresh_window_seconds` key:

```php
    // When true, ProfessionalSelfPolicy::update requires a fresh AAL2 check.
    // Flip to true once TOTP enrolment is in the UI and tested in production.
    'require_fresh_aal2_for_profile_update' => (bool) env('MFA_REQUIRE_FRESH_AAL2_FOR_PROFILE_UPDATE', false),
```

Also add to `.env.example`:
```
MFA_REQUIRE_FRESH_AAL2_FOR_PROFILE_UPDATE=false
```

- [ ] **Step 2: Write the failing AAL2 tests**

Append to `tests/Feature/Security/PolicyEnforcement/ProfessionalSelfPolicyEnforcementTest.php`:

```php
// ── AAL2 freshness gate (P2-01) ────────────────────────────────────────────

it('blocks profile update when fresh AAL2 required and token is aal1 (401)', function () {
    config(['partna.mfa.require_fresh_aal2_for_profile_update' => true]);

    $pro = createTenant('self-update-aal1');
    // tenantRequestAs sets aal=aal1 (no amr MFA entries) by default
    $req = tenantRequestAs($pro, ['display_name' => 'Should fail'], 'PATCH');

    try {
        app(ProfessionalController::class)->update(
            UpdateProfessionalRequest::createFrom($req)
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(401);
    }
});

it('allows profile update when fresh AAL2 required and amr contains recent totp', function () {
    config(['partna.mfa.require_fresh_aal2_for_profile_update' => true]);

    $pro = createTenant('self-update-aal2');
    $req = tenantRequestAs($pro, ['display_name' => 'Should pass'], 'PATCH');
    // Inject fresh AAL2 attributes — simulates a recently-verified TOTP session
    $req->attributes->set('supabase_aal', 'aal2');
    $req->attributes->set('supabase_amr', [
        ['method' => 'password', 'timestamp' => time() - 600],
        ['method' => 'totp',     'timestamp' => time() - 60],
    ]);

    $response = app(ProfessionalController::class)->update(
        UpdateProfessionalRequest::createFrom($req)
    );

    expect($response->getStatusCode())->toBe(200);
});

it('skips fresh-AAL2 check when feature flag is off (default)', function () {
    config(['partna.mfa.require_fresh_aal2_for_profile_update' => false]);

    $pro = createTenant('self-update-flag-off');
    $req = tenantRequestAs($pro, ['display_name' => 'Should pass'], 'PATCH');

    $response = app(ProfessionalController::class)->update(
        UpdateProfessionalRequest::createFrom($req)
    );

    expect($response->getStatusCode())->toBe(200);
});
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && \
  composer test -- --filter "ProfessionalSelfPolicyEnforcementTest" 2>&1 | grep -E "FAIL|PASS|Error" | head -20
```

Expected: the two new AAL2 tests fail (policy doesn't check the flag yet).

- [ ] **Step 4: Wire `requiresFreshAal2` into `ProfessionalSelfPolicy::update`**

In `app/Policies/ProfessionalSelfPolicy.php`, replace the `update` method:

```php
    public function update(User $actor, Model $resource): bool|Response
    {
        // Audit-log models are append-only — deny all mutations regardless of ownership.
        if ($resource instanceof ProfessionalDeletionAuditEntry) {
            return $this->denyAsNotFound();
        }

        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ($this->resolveOwnerId($resource) !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }
```

with:

```php
    public function update(User $actor, Model $resource): bool|Response
    {
        // Audit-log models are append-only — deny all mutations regardless of ownership.
        if ($resource instanceof ProfessionalDeletionAuditEntry) {
            return $this->denyAsNotFound();
        }

        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ($this->resolveOwnerId($resource) !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        // Require fresh MFA verification for high-risk self-mutations (profile update,
        // deletion confirm). Gated by feature flag — flip to true after TOTP enrolment
        // is live in the UI and smoke-tested in production.
        if (config('partna.mfa.require_fresh_aal2_for_profile_update')) {
            if ($denied = $this->checkFreshAal2()) {
                return $denied;
            }
        }

        return true;
    }

    /**
     * Returns a 401 deny Response when fresh AAL2 is required and not present;
     * null otherwise. Separated so the flag-gate in update() stays readable.
     */
    private function checkFreshAal2(): ?Response
    {
        $result = $this->requiresFreshAal2();

        return $result->allowed() ? null : $result;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && \
  composer test -- --filter ProfessionalSelfPolicyEnforcementTest 2>&1 | tail -20
```

Expected: all green.

- [ ] **Step 6: Run full suite**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && composer test 2>&1 | tail -20
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && \
  git add \
    app/Policies/ProfessionalSelfPolicy.php \
    config/partna.php \
    .env.example \
    tests/Feature/Security/PolicyEnforcement/ProfessionalSelfPolicyEnforcementTest.php && \
  git commit -m "feat(auth): wire requiresFreshAal2 into ProfessionalSelfPolicy::update (#P2-01)"
```

---

### Task 3 — P2-02: Replace `authorizeCustomLinks()` with skeleton-pattern `authorizeForUser` in link-block store/reorder

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php`
- Modify: `tests/Feature/Security/PolicyEnforcement/LinkBlockPolicyEnforcementTest.php`

**Background:**
- `authorizeCustomLinks()` is an empty no-op. It looks like a guard but does nothing.
- `store()` creates a new `Block` without checking pending-deletion or ownership policy.
- `reorder()` mutates sort-order on the actor's own blocks but also skips the pending-deletion check.
- The fix: use the skeleton pattern (unsaved model with ownership attrs set) to call `authorizeForUser($pro, 'create', $skeleton)`. `SitePolicy::create` already exists and does `denyIfPendingDeletion + ownerMatches`.
- Delete `authorizeCustomLinks()` entirely — it gives false confidence.

- [ ] **Step 1: Add tests for store/reorder auth**

Append to `tests/Feature/Security/PolicyEnforcement/LinkBlockPolicyEnforcementTest.php`:

```php
// ── store() ────────────────────────────────────────────────────────────────

it('blocks pending-deletion professional from creating a link block (423)', function () {
    $pro = createTenant('lb-store-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro->refresh();

    $req = tenantRequestAs($pro, [
        'title' => 'My Link',
        'url'   => 'https://example.com',
    ], 'POST');

    try {
        app(\App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalLinkBlockController::class)
            ->store(\App\Http\Requests\Api\Professional\Site\StoreLinkBlockRequest::createFrom($req));
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('allows active professional to create a link block (201)', function () {
    $pro = createTenant('lb-store-active');

    $req = tenantRequestAs($pro, [
        'title' => 'My Link',
        'url'   => 'https://example.com',
    ], 'POST');

    $response = app(\App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalLinkBlockController::class)
        ->store(\App\Http\Requests\Api\Professional\Site\StoreLinkBlockRequest::createFrom($req));

    expect($response->getStatusCode())->toBe(201);
});

// ── reorder() ──────────────────────────────────────────────────────────────

it('blocks pending-deletion professional from reordering link blocks (423)', function () {
    $pro = createTenant('lb-reorder-pending');
    $block = createLinkBlockFor($pro);
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro->refresh();

    $req = tenantRequestAs($pro, ['ids' => [$block->id]], 'POST');

    try {
        app(\App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalLinkBlockController::class)
            ->reorder(\App\Http\Requests\Api\Professional\Site\ReorderBlocksRequest::createFrom($req));
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('allows active professional to reorder their link blocks (200)', function () {
    $pro = createTenant('lb-reorder-active');
    $block = createLinkBlockFor($pro);

    $req = tenantRequestAs($pro, ['ids' => [$block->id]], 'POST');

    $response = app(\App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalLinkBlockController::class)
        ->reorder(\App\Http\Requests\Api\Professional\Site\ReorderBlocksRequest::createFrom($req));

    expect($response->getStatusCode())->toBe(200);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && \
  composer test -- --filter LinkBlockPolicyEnforcementTest 2>&1 | tail -20
```

Expected: the two `pending_deletion` tests (store 423, reorder 423) fail.

- [ ] **Step 3: Replace `authorizeCustomLinks()` + fix `store()` and `reorder()`**

In `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php`:

**Delete the entire `authorizeCustomLinks` method** (lines ~41–44):

```php
    private function authorizeCustomLinks(User $pro): void
    {
        // All individual users can manage custom links — no capability gate needed.
    }
```

**In `store()`**, replace:
```php
        $pro = $this->currentProfessional($request);
        $this->authorizeCustomLinks($pro);
        $site = $this->currentSite($pro);
```
with:
```php
        $pro = $this->currentProfessional($request);
        $site = $this->currentSite($pro);
        // Skeleton pattern: pre-create ownership + pending-deletion check via SitePolicy::create.
        $skeleton = new Block(['professional_id' => $pro->id, 'site_id' => $site->id]);
        $this->authorizeForUser($pro, 'create', $skeleton);
```

**In `update()`**, remove the `authorizeCustomLinks` call (policy check already present via `authorizeForUser($pro, 'update', $linkBlock)` below):
```php
        $pro = $this->currentProfessional($request);
        $this->authorizeCustomLinks($pro);   // ← delete this line
```

**In `reorder()`**, replace:
```php
        $pro = $this->currentProfessional($request);
        $this->authorizeCustomLinks($pro);
        $site = $this->currentSite($pro);
```
with:
```php
        $pro = $this->currentProfessional($request);
        $site = $this->currentSite($pro);
        // Skeleton pattern: reorder mutates block sort orders — treat as a create-level
        // site write for pending-deletion gate.
        $skeleton = new Block(['professional_id' => $pro->id, 'site_id' => $site->id]);
        $this->authorizeForUser($pro, 'create', $skeleton);
```

Also update the docblock at the top to remove the mention of `authorizeCustomLinks`:
```php
 * Authorization: ownership on write actions is enforced via SitePolicy (authorizeForUser).
 * A type constraint abort_unless guards that only link-type blocks reach the policy check.
 * store() and reorder() use the skeleton pattern (SitePolicy::create) for pending-deletion
 * and ownership guard without a DB row.
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && \
  composer test -- --filter LinkBlockPolicyEnforcementTest 2>&1 | tail -20
```

Expected: all green.

- [ ] **Step 5: Run full suite**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && composer test 2>&1 | tail -20
```

Expected: all green.

- [ ] **Step 6: Commit**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && \
  git add \
    app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php \
    tests/Feature/Security/PolicyEnforcement/LinkBlockPolicyEnforcementTest.php && \
  git commit -m "fix(auth): replace false-security authorizeCustomLinks with skeleton-pattern authorizeForUser in link-block store/reorder (#P2-02)"
```

---

## Post-implementation checklist

- [ ] Run `composer test` one final time and confirm all green
- [ ] Mark `#P2-01`, `#P2-02`, `#P3-12` as `[x]` in `audits/foundation-audit-v1/audit-2026-05-24-CONSOLIDATED.md`
- [ ] Update `.audit-work/completed/B16.md` with completion notes
