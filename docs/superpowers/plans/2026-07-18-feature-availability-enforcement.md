# Feature Availability Enforcement (non-integration site features) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let staff disable three anonymous-visitor public features (enquiry form, email signup, customer-lead capture) — globally or per segment — by enforcing existing `core.feature_availability` `feature.<name>` rules at each public submit endpoint, and surface the state to the site owner.

**Architecture:** A typed `PublicFeature` enum is the source of truth for the enforceable keys. A shared controller concern `GatesPublicFeature` reads the resolved site owner's `FeatureAvailability` snapshot and aborts **422 + `error: FEATURE_UNAVAILABLE`** when the feature is disabled; it is called in each of the three public submit controllers right after they resolve the site. Enforcement is read-only (no data mutation, no takedown job, no migration), so re-enable is automatic. The owner's authenticated `GET /api/site` read gains a `feature_availability` map.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4 feature tests on the SQLite in-memory mirror, `FeatureAvailability` service (`app/Services/FeatureAvailability/`).

**Design spec:** `docs/superpowers/specs/2026-07-18-feature-availability-enforcement-design.md`

## Global Constraints

- **No Laravel migration files** — none are needed; a composer guard rejects them. No schema change at all.
- **Public-endpoint response = 422 with `error: FEATURE_UNAVAILABLE`** — never 503, never 404, never 403. Body shape: `{ "error": "FEATURE_UNAVAILABLE", "message": "This feature is currently unavailable." }`.
- **Fail-open** — no applicable rule, or a null owner, means *available*. The 422 fires only on an explicit `disabled` rule. `UserFeatureAvailability::allows()` already returns `true` on absence.
- **Enum lives in `app/Enums/`** (matches `AccountType`, `SitepageId`, `EnquiryStatus`). Backing value is the `<name>` in `feature.<name>`.
- **Resource classes** for API responses; controllers stay thin.
- **4-space indentation, LF line endings.**
- **TEST TRAP — the SQLite fail-open mirror.** `FeatureAvailability::resolveOverrides()` catches `Throwable` and returns `[]` when `core.feature_availability` is absent (comment: *"covers SQLite test mirrors without the table"*). Those tables are **not** in the public suites' default schema. **Every disabled-path test MUST call `setupSegmentsTables()` and `setupFeatureAvailabilityTable()` in `beforeEach`, and `FeatureAvailability::flush()` after seeding a rule** — otherwise the test fails-open and passes for the wrong reason. This is the single most important thing to get right.
- **Test host mechanic** — the domain-scoped public routes don't match `localhost`, so tests hit the **domain-less** duplicates at `/api/public/...` and pass the tenant via the **`X-Site-Subdomain` header** (no `Host` header). `bot.token` middleware is a pass-through in tests (`partna.bot_protection.mode` defaults to `off`).
- **Commits:** Josh owns commits. Commit steps are included per task for whoever executes; keep them surgical (don't sweep unrelated Pint churn).

---

## File Structure

**Created:**
- `app/Enums/PublicFeature.php` — the enforceable-feature enum (source of truth: key + label).
- `app/Http/Controllers/Concerns/GatesPublicFeature.php` — the one-method submit gate.
- `tests/Unit/Enums/PublicFeatureTest.php` — enum unit test.
- `tests/Feature/CustomerLeads/PublicCustomerLeadGateTest.php` — customer-lead gate feature test (no existing happy-path file to extend).
- `tests/Feature/Site/SiteFeatureAvailabilitySurfacingTest.php` — owner-surfacing feature test.

**Modified:**
- `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php` — add gate call.
- `app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php` — add gate call.
- `app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php` — add gate call.
- `app/Http/Resources/SiteResource.php` — add opt-in `feature_availability` map.
- `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php` — opt into the map on `show`/`update`.
- `tests/Feature/Contact/PublicEnquirySubmissionTest.php` — add gate tests + widen `beforeEach` schema.
- `tests/Feature/Notifications/SubscriptionConfirmationDispatchTest.php` — add gate tests + widen `beforeEach` schema.

---

## Task 1: `PublicFeature` enum

**Files:**
- Create: `app/Enums/PublicFeature.php`
- Test: `tests/Unit/Enums/PublicFeatureTest.php`

**Interfaces:**
- Produces: `enum PublicFeature: string` with cases `Enquiries` (`'enquiries'`), `EmailSignup` (`'email_signup'`), `CustomerLeads` (`'customer_leads'`); methods `availabilityKey(): string` (returns `'feature.'.$this->value`) and `label(): string`. Consumed by Tasks 2–5.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Enums/PublicFeatureTest.php`:

```php
<?php

use App\Enums\PublicFeature;

it('maps each case to its feature.<name> availability key', function () {
    expect(PublicFeature::Enquiries->availabilityKey())->toBe('feature.enquiries')
        ->and(PublicFeature::EmailSignup->availabilityKey())->toBe('feature.email_signup')
        ->and(PublicFeature::CustomerLeads->availabilityKey())->toBe('feature.customer_leads');
});

it('enumerates exactly the three enforceable features', function () {
    expect(collect(PublicFeature::cases())->map->value->all())
        ->toBe(['enquiries', 'email_signup', 'customer_leads']);
});

it('has a non-empty human label for every case', function () {
    foreach (PublicFeature::cases() as $feature) {
        expect($feature->label())->toBeString()->not->toBe('');
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Enums/PublicFeatureTest.php`
Expected: FAIL — `Class "App\Enums\PublicFeature" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Enums/PublicFeature.php`:

```php
<?php

namespace App\Enums;

// The enforceable non-integration public features. The backing value is the
// '<name>' part of the availability key 'feature.<name>'. Single source of truth
// for the submit gate (GatesPublicFeature) and the owner-surfacing map on
// SiteResource. Adding a feature = one case here + one gate call in its endpoint.
enum PublicFeature: string
{
    case Enquiries = 'enquiries';
    case EmailSignup = 'email_signup';
    case CustomerLeads = 'customer_leads';

    /** The full core.feature_availability key, e.g. 'feature.enquiries'. */
    public function availabilityKey(): string
    {
        return 'feature.'.$this->value;
    }

    /** Human label for owner-surfacing / any future staff meta endpoint. */
    public function label(): string
    {
        return match ($this) {
            self::Enquiries => 'Enquiry form',
            self::EmailSignup => 'Email signup',
            self::CustomerLeads => 'Customer lead capture',
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Enums/PublicFeatureTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Enums/PublicFeature.php tests/Unit/Enums/PublicFeatureTest.php
git commit -m "feat(feature-availability): add PublicFeature enum (enforceable public features)"
```

---

## Task 2: `GatesPublicFeature` concern + wire `PublicEnquiryController`

This task builds the shared gate and applies it to the richest of the three controllers (the one with an existing contact-block check, so it also proves precedence).

**Files:**
- Create: `app/Http/Controllers/Concerns/GatesPublicFeature.php`
- Modify: `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php` (add `use` + gate call after site resolution, before the contact-block check ~line 73)
- Test: `tests/Feature/Contact/PublicEnquirySubmissionTest.php` (widen `beforeEach`; append gate tests)

**Interfaces:**
- Consumes: `PublicFeature` (Task 1).
- Produces: `trait GatesPublicFeature` with `protected function assertPublicFeatureAvailable(?\App\Models\Core\Site\Site $site, \App\Enums\PublicFeature $feature): void`. Throws `HttpResponseException` (422, `error: FEATURE_UNAVAILABLE`) when the site owner has the feature disabled; no-op otherwise. Consumed by Tasks 3–4.

- [ ] **Step 1: Widen the enquiry test `beforeEach` so rules aren't fail-open**

In `tests/Feature/Contact/PublicEnquirySubmissionTest.php`, add the two availability tables to the shared schema helper. Modify `setupContactSubmissionSchema()` (currently starts ~line 21) to add these two calls after `setupBlocksTable();`:

```php
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupSegmentsTables();            // <-- add: core.user_segments (+ members)
    setupFeatureAvailabilityTable();  // <-- add: core.feature_availability
```

Both use `CREATE TABLE IF NOT EXISTS`, so this is additive and harmless to the existing tests.

- [ ] **Step 2: Write the failing gate tests**

Append to `tests/Feature/Contact/PublicEnquirySubmissionTest.php`. Add these imports at the top of the file (next to the existing `use` lines):

```php
use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Services\FeatureAvailability\FeatureAvailability;
```

Then append these tests (they reuse the file's existing `seedPublishedContactSite()` and `validEnquiryPayload()` helpers):

```php
it('422s the enquiry submit when feature.enquiries is globally disabled', function () {
    seedPublishedContactSite();
    Bus::fake();

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.enquiries',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
    ]);
    FeatureAvailability::flush();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertStatus(422)->assertJson(['error' => 'FEATURE_UNAVAILABLE']);

    // No row written, no notification dispatched — the gate fired before persistence.
    expect(\App\Models\Core\Site\Enquiry::query()->count())->toBe(0);
    Bus::assertNotDispatched(DispatchEnquiryNotificationsJob::class);
    Bus::assertNotDispatched(SendEnquiryConfirmationJob::class);
});

it('allows the enquiry submit when no availability rule exists', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk()->assertJson(['ok' => true]);

    expect(\App\Models\Core\Site\Enquiry::query()->count())->toBe(1);
});

it('422s a submitter whose owner is in a disabled segment, but allows one who is not', function () {
    [$proId] = seedPublishedContactSite('segpro');       // owner IN the segment
    seedPublishedContactSite('freepro');                 // owner NOT in the segment
    Bus::fake();

    $segment = UserSegment::query()->create(['name' => 'seg-'.\Illuminate\Support\Str::random(4), 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $proId]);

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.enquiries',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
        'segment_id' => $segment->id,
    ]);
    FeatureAvailability::flush();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), ['X-Site-Subdomain' => 'segpro'])
        ->assertStatus(422)->assertJson(['error' => 'FEATURE_UNAVAILABLE']);

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), ['X-Site-Subdomain' => 'freepro'])
        ->assertOk();
});

it('gate precedence: disabled feature 422s even with a live contact block', function () {
    // seedPublishedContactSite() creates a live `contact` section block, so this
    // proves the feature gate fires BEFORE the existing contact-block check.
    seedPublishedContactSite();
    Bus::fake();

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.enquiries',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
    ]);
    FeatureAvailability::flush();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), ['X-Site-Subdomain' => 'testpro'])
        ->assertStatus(422)->assertJson(['error' => 'FEATURE_UNAVAILABLE']);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Contact/PublicEnquirySubmissionTest.php`
Expected: the 4 new tests FAIL — the disabled/precedence cases currently return 200 (`ok: true`) because nothing enforces the rule yet; the segment case returns 200 for both.

- [ ] **Step 4: Create the `GatesPublicFeature` concern**

Create `app/Http/Controllers/Concerns/GatesPublicFeature.php`:

```php
<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\PublicFeature;
use App\Models\Core\Site\Site;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Http\Exceptions\HttpResponseException;

// Enforcement point for staff-disabled non-integration public features. Used by
// the public submit controllers (enquiry / subscribe / customer-leads), which all
// extend ApiController — so $this->error() (the shared JSON error shape) resolves
// at runtime. See docs/superpowers/specs/2026-07-18-feature-availability-enforcement-design.md.
trait GatesPublicFeature
{
    /**
     * 422 the request when the resolved site owner has $feature staff-disabled.
     *
     * Fail-open by design: a null owner or the absence of an applicable rule means
     * "available" (FeatureAvailability::allows() already returns true on absence).
     * 422 + a machine-readable error mirrors PublicEnquiryController's existing
     * "not accepting enquiries" and PublicReportController's "422 not 404 on public
     * endpoints" — the public-endpoint convention, not 404/503/403.
     */
    protected function assertPublicFeatureAvailable(?Site $site, PublicFeature $feature): void
    {
        $owner = $site?->user;

        if ($owner && ! FeatureAvailability::for($owner)->allows($feature->availabilityKey())) {
            throw new HttpResponseException(
                $this->error('This feature is currently unavailable.', 422, [], ['error' => 'FEATURE_UNAVAILABLE'])
            );
        }
    }
}
```

- [ ] **Step 5: Wire the gate into `PublicEnquiryController`**

In `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php`:

1. Add the two `use` statements (alongside the existing controller-level `use` traits and the `App\Enums`/`App\Http\Controllers\Concerns` imports):

```php
use App\Enums\PublicFeature;
use App\Http\Controllers\Concerns\GatesPublicFeature;
```

2. Add the trait to the class body (next to `use HashesClientData;` / `use ResolvesSubdomainFromHost;`):

```php
    use GatesPublicFeature;
```

3. Insert the gate call immediately after the site-resolution guard (after the `if (! $site || ! $site->user_id) { ... return ... 404; }` block, currently ending ~line 71) and **before** the `// 3) Contact block must be active` check (~line 73):

```php
        // Staff feature kill-switch: 422 before the contact-block check so a
        // disabled rule takes precedence over "no contact block".
        $this->assertPublicFeatureAvailable($site, PublicFeature::Enquiries);
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Contact/PublicEnquirySubmissionTest.php`
Expected: PASS — all tests green (the original happy-path test plus the 4 new gate tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Concerns/GatesPublicFeature.php \
        app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php \
        tests/Feature/Contact/PublicEnquirySubmissionTest.php
git commit -m "feat(feature-availability): gate public enquiry submit on feature.enquiries"
```

---

## Task 3: Wire `PublicEmailSubscriptionController`

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php` (add gate after site resolution ~line 80)
- Test: `tests/Feature/Notifications/SubscriptionConfirmationDispatchTest.php` (widen `beforeEach`; append gate tests)

**Interfaces:**
- Consumes: `GatesPublicFeature` + `PublicFeature::EmailSignup` (Tasks 1–2).

- [ ] **Step 1: Widen the subscribe test `beforeEach`**

In `tests/Feature/Notifications/SubscriptionConfirmationDispatchTest.php`, add the two availability tables to `beforeEach` (after the existing `setupCustomersTable();`):

```php
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupEmailSubscriptionsTable();
    setupCustomersTable();
    setupSegmentsTables();            // <-- add
    setupFeatureAvailabilityTable();  // <-- add
```

- [ ] **Step 2: Write the failing gate tests**

Add these imports at the top of the file:

```php
use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Notifications\EmailSubscription;
use App\Services\FeatureAvailability\FeatureAvailability;
```

Append these tests (reusing the file's `seedPublishedSubscribeSite()` helper; the subscribe payload is inlined since the file has no payload helper):

```php
function validSubscribePayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'reader@example.com',
        'full_name' => 'Reader Person',
        'website' => '',
        'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
    ], $overrides);
}

it('422s the subscribe submit when feature.email_signup is globally disabled', function () {
    seedPublishedSubscribeSite();

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.email_signup',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
    ]);
    FeatureAvailability::flush();

    $this->postJson('/api/public/subscribe', validSubscribePayload(), [
        'X-Site-Subdomain' => 'subpro',
    ])->assertStatus(422)->assertJson(['error' => 'FEATURE_UNAVAILABLE']);

    // No subscription row written — the gate fired before persistence.
    expect(EmailSubscription::query()->count())->toBe(0);
});

it('allows the subscribe submit when no availability rule exists', function () {
    seedPublishedSubscribeSite();

    $this->postJson('/api/public/subscribe', validSubscribePayload(), [
        'X-Site-Subdomain' => 'subpro',
    ])->assertOk()->assertJson(['subscribed' => true]);

    expect(EmailSubscription::query()->count())->toBe(1);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Notifications/SubscriptionConfirmationDispatchTest.php`
Expected: the disabled test FAILS (returns 200 + writes a row) because nothing enforces the rule yet.

- [ ] **Step 4: Wire the gate into `PublicEmailSubscriptionController`**

In `app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php`:

1. Add imports:

```php
use App\Enums\PublicFeature;
use App\Http\Controllers\Concerns\GatesPublicFeature;
```

2. Add the trait to the class body (next to `use HashesClientData;` / `use ResolvesSubdomainFromHost;`):

```php
    use GatesPublicFeature;
```

3. Insert the gate immediately after the site-resolution guard (`if (! $site) { return ... 404; }`, ~line 80) and before `$listKey = $data['list_key'] ?? 'marketing';` (~line 82):

```php
        // Staff feature kill-switch.
        $this->assertPublicFeatureAvailable($site, PublicFeature::EmailSignup);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Notifications/SubscriptionConfirmationDispatchTest.php`
Expected: PASS (existing confirmation tests + the 2 new gate tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php \
        tests/Feature/Notifications/SubscriptionConfirmationDispatchTest.php
git commit -m "feat(feature-availability): gate public subscribe submit on feature.email_signup"
```

---

## Task 4: Wire `PublicCustomerLeadController`

There is no happy-path feature test for `POST /public/customers`, so this task creates a self-contained one.

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php` (add gate after `$pro = $site->user;` ~line 85)
- Create: `tests/Feature/CustomerLeads/PublicCustomerLeadGateTest.php`

**Interfaces:**
- Consumes: `GatesPublicFeature` + `PublicFeature::CustomerLeads` (Tasks 1–2).

- [ ] **Step 1: Write the failing gate test (self-contained schema)**

Create `tests/Feature/CustomerLeads/PublicCustomerLeadGateTest.php`:

```php
<?php

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\User\Customer;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['partna.throttle.enabled' => false]);

    setupUsersTable();
    setupSitesTable();
    setupCustomersTable();
    setupEmailSubscriptionsTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();

    // analytics.lead_submissions has no global helper — the controller's logLead()
    // writes here on the allowed path. Same DDL as the enquiry suite.
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.lead_submissions (
        id TEXT PRIMARY KEY, occurred_at TEXT NULL, subdomain TEXT NULL, site_id TEXT NULL,
        user_id TEXT NULL, customer_id TEXT NULL, ip_hash TEXT NULL, user_agent TEXT NULL,
        referrer TEXT NULL, outcome TEXT NULL, form_started_at_ms INTEGER NULL
    )');
});

function seedPublishedLeadSite(string $subdomain = 'leadpro'): string
{
    $userId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId, 'handle' => $subdomain, 'handle_lc' => $subdomain,
        'display_name' => 'Lead Pro', 'primary_email' => $subdomain.'@example.com', 'status' => 'active',
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'subdomain' => $subdomain, 'is_published' => 1,
    ]);

    return $userId;
}

function validLeadPayload(array $overrides = []): array
{
    return array_merge([
        'full_name' => 'Casey Lead',
        'email' => 'casey@example.com',
        'phone' => '+44 7700 900111',
        'website' => '',
        'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
    ], $overrides);
}

it('422s the customer-lead submit when feature.customer_leads is globally disabled', function () {
    seedPublishedLeadSite();

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.customer_leads',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
    ]);
    FeatureAvailability::flush();

    $this->postJson('/api/public/customers', validLeadPayload(), [
        'X-Site-Subdomain' => 'leadpro',
    ])->assertStatus(422)->assertJson(['error' => 'FEATURE_UNAVAILABLE']);

    // No customer created — the gate fired before persistence.
    expect(Customer::query()->count())->toBe(0);
});

it('allows the customer-lead submit when no availability rule exists', function () {
    seedPublishedLeadSite();

    $this->postJson('/api/public/customers', validLeadPayload(), [
        'X-Site-Subdomain' => 'leadpro',
    ])->assertStatus(201)->assertJson(['ok' => true]);

    expect(Customer::query()->count())->toBe(1);
});

it('422s a customer-lead submitter whose owner is in a disabled segment', function () {
    $ownerId = seedPublishedLeadSite('segleadpro');

    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $ownerId]);

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.customer_leads',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
        'segment_id' => $segment->id,
    ]);
    FeatureAvailability::flush();

    $this->postJson('/api/public/customers', validLeadPayload(), [
        'X-Site-Subdomain' => 'segleadpro',
    ])->assertStatus(422)->assertJson(['error' => 'FEATURE_UNAVAILABLE']);

    expect(Customer::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/CustomerLeads/PublicCustomerLeadGateTest.php`
Expected: the two disabled/segment tests FAIL (return 201 + create a customer) because nothing enforces the rule yet; the "allowed" test passes already.

- [ ] **Step 3: Wire the gate into `PublicCustomerLeadController`**

In `app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php`:

1. Add imports:

```php
use App\Enums\PublicFeature;
use App\Http\Controllers\Concerns\GatesPublicFeature;
```

2. Add the trait to the class body (next to `use HashesClientData;` / `use ResolvesSubdomainFromHost;`):

```php
    use GatesPublicFeature;
```

3. Insert the gate immediately after `$pro = $site->user;` (~line 85) and before the `// Check if customer with this email already exists` block (~line 87):

```php
        // Staff feature kill-switch.
        $this->assertPublicFeatureAvailable($site, PublicFeature::CustomerLeads);
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/CustomerLeads/PublicCustomerLeadGateTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php \
        tests/Feature/CustomerLeads/PublicCustomerLeadGateTest.php
git commit -m "feat(feature-availability): gate public customer-lead submit on feature.customer_leads"
```

---

## Task 5: Owner-facing surfacing on `GET /api/site`

**Files:**
- Modify: `app/Http/Resources/SiteResource.php` (add opt-in `feature_availability` map)
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php` (opt in on `show`/`update`)
- Create: `tests/Feature/Site/SiteFeatureAvailabilitySurfacingTest.php`

**Interfaces:**
- Consumes: `PublicFeature` (Task 1), `FeatureAvailability` service.
- Produces: `SiteResource::withFeatureAvailability(User $owner): static` (fluent, mirrors `withRationale()`); when set, `toArray()` emits `feature_availability` = `{ <case value>: bool, ... }` for every `PublicFeature`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Site/SiteFeatureAvailabilitySurfacingTest.php`:

```php
<?php

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupDesignKitsTable();              // GET /api/site calls SiteResource->withRationale()
    setupDesignKitContributionsTable();  // -> DesignRationaleService reads these two
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
});

function seedOwnerWithSite(string $subdomain = 'ownerpro'): User
{
    $userId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId, 'handle' => $subdomain, 'handle_lc' => $subdomain,
        'display_name' => 'Owner Pro', 'primary_email' => $subdomain.'@example.com', 'status' => 'active',
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'subdomain' => $subdomain, 'is_published' => 1,
    ]);

    return User::query()->findOrFail($userId);
}

it('surfaces feature_availability on the owner GET /api/site, reflecting a disabled rule', function () {
    $owner = seedOwnerWithSite();

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.enquiries',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
    ]);
    FeatureAvailability::flush();

    actingAsUser($owner)
        ->getJson('/api/site')
        ->assertOk()
        ->assertJsonPath('site.feature_availability.enquiries', false)
        ->assertJsonPath('site.feature_availability.email_signup', true)
        ->assertJsonPath('site.feature_availability.customer_leads', true);
});

it('reports all features available when no rule exists', function () {
    $owner = seedOwnerWithSite('cleanpro');

    actingAsUser($owner)
        ->getJson('/api/site')
        ->assertOk()
        ->assertJsonPath('site.feature_availability.enquiries', true)
        ->assertJsonPath('site.feature_availability.email_signup', true)
        ->assertJsonPath('site.feature_availability.customer_leads', true);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Site/SiteFeatureAvailabilitySurfacingTest.php`
Expected: FAIL — `assertJsonPath` finds no `site.feature_availability` key (the resource doesn't emit it yet).

- [ ] **Step 3: Add the opt-in map to `SiteResource`**

In `app/Http/Resources/SiteResource.php`:

1. Add imports at the top:

```php
use App\Enums\PublicFeature;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
```

2. Add the fluent opt-in (next to the existing `$withRationale` property + `withRationale()` method):

```php
    /** Owner whose feature availability to emit; null = don't emit the map. */
    private ?User $featureAvailabilityOwner = null;

    /** Opt this resource into emitting feature_availability for $owner. Fluent. */
    public function withFeatureAvailability(User $owner): static
    {
        $this->featureAvailabilityOwner = $owner;

        return $this;
    }

    /**
     * feature_key value => is-available, for every enforceable PublicFeature.
     * Resolves the per-user availability snapshot ONCE (not per key).
     *
     * @return array<string, bool>
     */
    private function featureAvailabilityMap(User $owner): array
    {
        $availability = FeatureAvailability::for($owner);

        return collect(PublicFeature::cases())
            ->mapWithKeys(fn (PublicFeature $feature) => [
                $feature->value => $availability->allows($feature->availabilityKey()),
            ])
            ->all();
    }
```

3. In `toArray()`, add a final merged array (after the two booking `array_key_exists(...)` merges at the end of the `array_merge(...)` call):

```php
            $this->featureAvailabilityOwner !== null
                ? ['feature_availability' => $this->featureAvailabilityMap($this->featureAvailabilityOwner)]
                : []);
```

Note: this becomes the new last argument to the existing `array_merge(...)`. Move the closing `)` and `;` from the previous last argument onto this one.

- [ ] **Step 4: Opt in from `UserSiteController`**

In `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php`, update the two response builders to chain `->withFeatureAvailability($professional)`:

`show()` (currently `return $this->success(['site' => (new SiteResource($site))->withRationale()]);`):

```php
        return $this->success(['site' => (new SiteResource($site))
            ->withRationale()
            ->withFeatureAvailability($professional)]);
```

`update()` (the final `return $this->success(['site' => (new SiteResource($site))->withRationale()]);`):

```php
        return $this->success(['site' => (new SiteResource($site))
            ->withRationale()
            ->withFeatureAvailability($professional)]);
```

(`$professional` is already the resolved owner in both methods — `$professional = $this->currentUser($request);`.)

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Site/SiteFeatureAvailabilitySurfacingTest.php`
Expected: PASS (2 passed).

- [ ] **Step 6: Run the full set of touched suites to confirm no regressions**

Run:
```bash
php artisan test \
  tests/Unit/Enums/PublicFeatureTest.php \
  tests/Feature/Contact/PublicEnquirySubmissionTest.php \
  tests/Feature/Notifications/SubscriptionConfirmationDispatchTest.php \
  tests/Feature/CustomerLeads/PublicCustomerLeadGateTest.php \
  tests/Feature/Site/SiteFeatureAvailabilitySurfacingTest.php \
  tests/Feature/Api/User/SiteManagement/WriteDesignKitTest.php \
  tests/Feature/Staff/FeatureAvailabilityReadSideTest.php
```
Expected: PASS across all. (Running the full `composer test` may abort on the pre-existing PHP-8.4 `GenericShopScraperTest` issue unrelated to this work — prefer these targeted paths for verification.)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Resources/SiteResource.php \
        app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php \
        tests/Feature/Site/SiteFeatureAvailabilitySurfacingTest.php
git commit -m "feat(feature-availability): surface feature_availability on owner GET /api/site"
```

---

## Verification (post-implementation)

- [ ] `php artisan pint` on the changed files only (keep the diff surgical; revert unrelated baseline churn).
- [ ] Re-run the targeted suites in Task 5 Step 6 — all green.
- [ ] **Real-Postgres check (post-deploy to `development`).** The SQLite mirror doesn't enforce `core.feature_availability` shape. After deploy, with a live `disabled` rule for `feature.enquiries`, confirm a real `POST /public/enquiry` returns 422 `{ error: FEATURE_UNAVAILABLE }` and writes no `site.enquiries` row, and that `GET /api/site` for that owner reports `feature_availability.enquiries = false`.
- [ ] No migration to apply (verify `supabase/migrations/` is untouched).

## Self-Review (completed during authoring)

- **Spec coverage:** §5 registry → Task 1 (enum). §6 submit gate → Tasks 2–4 (concern + three controllers). §7 owner-surfacing → Task 5. §10 test matrix (disabled→422, enabled→200, segment, precedence, no-row/no-job, owner surfacing, re-enable-is-free-via-absence) → covered across Tasks 2–5. §8/§9 (no surface-hiding, no migration, read-only) → honored (no payload/view/edge-cache/migration touched). Deferred follow-ups (§11) → intentionally absent.
- **Placeholder scan:** none — every step has concrete code/commands.
- **Type consistency:** `PublicFeature` cases/`availabilityKey()`/`label()`, `assertPublicFeatureAvailable(?Site, PublicFeature)`, and `withFeatureAvailability(User)` are used identically everywhere they appear.
- **Known real-code anchors verified:** `ApiController::error(string,int,array,array)`; `Site::user()` `belongsTo(User,'user_id')`; test host = `X-Site-Subdomain` header; `bot_protection.mode` defaults `off`; `FeatureAvailabilityRule` `MODE_DISABLED`/`MODE_ENABLED` + fillable `feature_key,mode,segment_id`; setup helpers `setupSegmentsTables()`/`setupFeatureAvailabilityTable()` exist; `actingAsUser(User)`; site route `/api/site`.
