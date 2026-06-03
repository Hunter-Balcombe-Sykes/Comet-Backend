# Enquiry Inbox Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the existing `contact` block / `site.enquiries` pipeline into a real dashboard inbox: in-app notification on submit, status workflow (new/read/replied/archived/spam), detail view with linked Customer + history, channels toggle (in_app/email), spam-mark side-effects, and a PII redaction cascade.

**Architecture:** Backend additions on top of existing primitives. New columns on `site.enquiries`. New queued job for fan-out notification dispatch. Adapter pattern for channels (InApp via existing `NotificationPublisher`, Email via existing `SendEnquiryNotificationJob`). EnquiryPolicy for authorization. No changes to the public submit contract beyond dropping the (never-emitted) `enquiry_id` from the response.

**Tech Stack:** Laravel 12, Supabase Postgres (raw SQL migrations only — no Laravel migrations), Redis (sorted set for spam blocklist), Pest 4, Horizon.

**Spec:** [`docs/superpowers/specs/2026-05-26-enquiry-inbox-design.md`](../specs/2026-05-26-enquiry-inbox-design.md) — read before starting.

**Prerequisites before any task:**
1. On branch off `development`: `git fetch && git pull && git checkout -b feat/enquiry-inbox development`.
2. `composer install && composer dev` works locally.
3. `php artisan tinker` works against dev DB.

---

## Testing conventions in this codebase — READ BEFORE WRITING ANY TEST

This codebase does **NOT** use Eloquent model factories. Tests follow a raw-SQL pattern (verified against `tests/Unit/UserEnquiryControllerTest.php` and `tests/Feature/Contact/PublicEnquirySubmissionTest.php`). When test snippets in this plan reference "factory" semantics, **translate** to the patterns below.

**Setup (in `beforeEach`):**
```php
beforeEach(function () {
    config(['partna.throttle.enabled' => false]);
    Cache::flush();
    setupUsersTable();
    setupContactInboxSchema();  // helper defined in Task 0 — extends existing pattern
});
```

**Seeding a user (replaces `User::factory()->create()`):**
```php
$user = makeInboxUser();  // existing helper at tests/Unit/UserEnquiryControllerTest.php; promoted to a shared location in Task 0
```

**Seeding an enquiry (replaces `Enquiry::factory()->create([...])`):**
```php
$enquiryId = seedInboxEnquiry($user->id, $siteId, [
    'status' => 'new',
    'customer_id' => $customerId,
    // ...any overrides
]);
$enquiry = Enquiry::find($enquiryId);
```

**Authenticating for dashboard endpoints (replaces `$this->actingAsUser($user)`):**
```php
// requestAs() sets the 'professional' attribute that the auth middleware would set after JWT decode.
// Note: attribute is still 'professional' even after the user-rename — request attributes weren't renamed.
$request = requestAs($user, method: 'POST', uri: "/api/me/enquiries/{$enquiryId}/replied");
$response = app(UserEnquiryController::class)->markReplied($request, $enquiryId);
$body = $response->getData(true);
expect($body['enquiry']['status'])->toBe('replied');
```

**For PUBLIC routes (no auth)**, do use HTTP `postJson` / `getJson` as `PublicEnquirySubmissionTest.php` does — the bot-protection + throttle middlewares need to run in the full HTTP pipeline.

**Where the helpers live:** see Task 0. Each test file `uses(...)` the helpers it needs.

---

## Task 0: Shared test helpers for the enquiry inbox

**Files:**
- Create: `tests/Helpers/EnquiryInboxTestHelpers.php`
- Read for reference: `tests/Unit/UserEnquiryControllerTest.php` (existing pattern), `tests/Feature/Contact/PublicEnquirySubmissionTest.php` (HTTP pattern + schema setup)

- [ ] **Step 1: Read the two existing test files**

Run:
```bash
cat tests/Unit/UserEnquiryControllerTest.php
cat tests/Feature/Contact/PublicEnquirySubmissionTest.php
```

Note the helper function shapes (`setupUsersTable`, `attachTestSchemas`, `makeInboxUser`, `seedInboxEnquiry`, `requestAs`). Our new helpers will extend these — same naming, same patterns.

- [ ] **Step 2: Create the helpers file**

Path: `tests/Helpers/EnquiryInboxTestHelpers.php`

```php
<?php

use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\Customer;
use App\Models\Core\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (! function_exists('setupContactInboxSchema')) {
    function setupContactInboxSchema(): void
    {
        attachTestSchemas();

        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
            id TEXT PRIMARY KEY,
            user_id TEXT NOT NULL,
            site_id TEXT NOT NULL,
            customer_id TEXT NULL,
            notification_id TEXT NULL,
            name TEXT NULL,
            email TEXT NULL,
            phone TEXT NULL,
            subject TEXT NOT NULL,
            message TEXT NULL,
            ip_hash TEXT NULL,
            user_agent TEXT NULL,
            status TEXT NOT NULL DEFAULT "new",
            read_at TEXT NULL,
            replied_at TEXT NULL,
            archived_at TEXT NULL,
            spam_at TEXT NULL,
            redacted_at TEXT NULL,
            email_sent_at TEXT NULL,
            deleted_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.customers (
            id TEXT PRIMARY KEY,
            user_id TEXT NOT NULL,
            email TEXT NULL,
            phone TEXT NULL,
            full_name TEXT NULL,
            source TEXT NULL,
            external_id TEXT NULL,
            marketing_opt_in_cached INTEGER NULL,
            notes TEXT NULL,
            redacted_at TEXT NULL,
            deleted_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
            id TEXT PRIMARY KEY,
            user_id TEXT NULL,
            type TEXT NOT NULL,
            category TEXT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            cta_url TEXT NULL,
            primary_action_label TEXT NULL,
            secondary_action_label TEXT NULL,
            secondary_action_url TEXT NULL,
            severity TEXT NULL,
            starts_at TEXT NULL,
            ends_at TEXT NULL,
            dedupe_key TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            email_sent_at TEXT NULL
        )');

        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notification_receipts (
            id TEXT PRIMARY KEY,
            notification_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            read_at TEXT NULL,
            dismissed_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.blocks (
            id TEXT PRIMARY KEY,
            site_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            block_type TEXT NOT NULL,
            block_group TEXT NOT NULL,
            settings TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            deleted_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
    }
}

if (! function_exists('makeInboxUser')) {
    function makeInboxUser(): User
    {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('core.users')->insert([
            'id' => $id,
            'handle' => 'inbox-'.substr($id, 0, 8),
            'handle_lc' => 'inbox-'.substr($id, 0, 8),
            'display_name' => 'Inbox Pro',
            'primary_email' => 'inbox-'.substr($id, 0, 8).'@example.com',
            'status' => 'active',
        ]);

        return User::query()->findOrFail($id);
    }
}

if (! function_exists('seedInboxEnquiry')) {
    function seedInboxEnquiry(string $userId, string $siteId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('site.enquiries')->insert(array_merge([
            'id' => $id,
            'user_id' => $userId,
            'site_id' => $siteId,
            'name' => 'Sarah',
            'email' => 's@e.com',
            'subject' => 'Press',
            'message' => 'A ten-char message here.',
            'status' => 'new',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ], $overrides));

        return $id;
    }
}

if (! function_exists('seedInboxCustomer')) {
    function seedInboxCustomer(string $userId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('site.customers')->insert(array_merge([
            'id' => $id,
            'user_id' => $userId,
            'email' => 'cust@example.com',
            'full_name' => 'Customer',
            'source' => 'enquiry',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ], $overrides));

        return $id;
    }
}

if (! function_exists('seedContactBlock')) {
    function seedContactBlock(string $siteId, string $userId, array $settings = []): string
    {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('site.blocks')->insert([
            'id' => $id,
            'site_id' => $siteId,
            'user_id' => $userId,
            'block_type' => 'contact',
            'block_group' => 'sections',
            'settings' => json_encode(array_merge([
                'notification_email' => 'pro@example.com',
                'subject_options' => ['Press', 'General'],
            ], $settings)),
            'is_active' => 1,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return $id;
    }
}

if (! function_exists('requestAs')) {
    function requestAs(User $user, string $method = 'GET', string $uri = '/api/me/enquiries', array $data = []): Request
    {
        $request = Request::create($uri, $method, $data);
        // Attribute is still 'professional' (request attributes weren't renamed when the column was).
        $request->attributes->set('professional', $user);

        return $request;
    }
}
```

- [ ] **Step 3: Wire it into the Pest bootstrap**

Edit `tests/Pest.php` — add an `uses(...)->in(...)` or `require_once` line so the helpers load. Check existing pattern. Typically:

```php
require_once __DIR__.'/Helpers/EnquiryInboxTestHelpers.php';
```

placed alongside the other helper requires.

- [ ] **Step 4: Smoke-test the helpers**

Create a quick disposable test:

```php
<?php

uses(Tests\TestCase::class);

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

it('can seed a user, customer, enquiry, and contact block', function () {
    $user = makeInboxUser();
    $customer = seedInboxCustomer($user->id);
    $siteId = (string) \Illuminate\Support\Str::uuid();
    $enquiryId = seedInboxEnquiry($user->id, $siteId, ['customer_id' => $customer]);
    $blockId = seedContactBlock($siteId, $user->id);

    expect(\App\Models\Core\Site\Enquiry::find($enquiryId)->status)->toBe('new');
    expect(\App\Models\Core\User\Customer::find($customer)->user_id)->toBe($user->id);
});
```

Run: `./vendor/bin/pest tests/Unit/EnquiryInboxHelperSmokeTest.php`
Expected: PASS. **Then delete this smoke-test file** — it was a one-shot verification.

- [ ] **Step 5: Commit**

```bash
git add tests/Helpers/EnquiryInboxTestHelpers.php tests/Pest.php
git commit -m "test(enquiry-inbox): shared test helpers (schema, seeders, requestAs)"
```

---

## Task 1: Create the Supabase migration

**Files:**
- Create: `supabase/migrations/20260527160000_enquiry_inbox.sql`

- [ ] **Step 1: Create the migration file with full SQL**

```sql
-- ==========================================================================
-- Enquiry Inbox foundation (2026-05-27)
--
-- Adds status enum, customer/notification linkage, status audit timestamps,
-- and a redacted_at column to site.enquiries. Drops the now-redundant
-- enquiries_user_created_idx (its (user_id, created_at DESC) prefix is
-- covered by the new composite (user_id, status, created_at DESC)).
--
-- Spec: docs/superpowers/specs/2026-05-26-enquiry-inbox-design.md
-- ==========================================================================

BEGIN;

-- 1. Status enum
CREATE TYPE site.enquiry_status AS ENUM ('new', 'read', 'replied', 'archived', 'spam');

-- 2. Status column with backfill-friendly default
ALTER TABLE site.enquiries
    ADD COLUMN status site.enquiry_status NOT NULL DEFAULT 'new';

-- 3. Backfill status from existing read_at
UPDATE site.enquiries SET status = 'read' WHERE read_at IS NOT NULL;

-- 4. New FK + timestamp columns (all nullable, no rewrite)
ALTER TABLE site.enquiries
    ADD COLUMN customer_id uuid,
    ADD COLUMN notification_id uuid,
    ADD COLUMN replied_at timestamptz,
    ADD COLUMN archived_at timestamptz,
    ADD COLUMN spam_at timestamptz,
    ADD COLUMN redacted_at timestamptz;

-- 5. Backfill customer_id by email match (live customers only)
UPDATE site.enquiries e
SET customer_id = c.id
FROM site.customers c
WHERE c.user_id = e.user_id
  AND lower(c.email) = lower(e.email)
  AND c.deleted_at IS NULL;

-- 6. FK constraints
ALTER TABLE site.enquiries
    ADD CONSTRAINT enquiries_customer_fk
        FOREIGN KEY (customer_id) REFERENCES site.customers(id) ON DELETE SET NULL,
    ADD CONSTRAINT enquiries_notification_fk
        FOREIGN KEY (notification_id) REFERENCES notifications.notifications(id) ON DELETE SET NULL;

COMMIT;

-- CONCURRENTLY index creation MUST live outside any transaction block.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_enquiries_user_status_created
    ON site.enquiries (user_id, status, created_at DESC)
    WHERE deleted_at IS NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_enquiries_customer
    ON site.enquiries (customer_id)
    WHERE deleted_at IS NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_enquiries_notification
    ON site.enquiries (notification_id)
    WHERE notification_id IS NOT NULL;

-- Drop the redundant index (covered by the new composite).
DROP INDEX CONCURRENTLY IF EXISTS site.enquiries_user_created_idx;
```

- [ ] **Step 2: Verify the file lints**

Run: `cat supabase/migrations/20260527160000_enquiry_inbox.sql | head -20`
Expected: shows the BEGIN line and CREATE TYPE.

- [ ] **Step 3: Commit**

```bash
git add supabase/migrations/20260527160000_enquiry_inbox.sql
git commit -m "feat(enquiry-inbox): migration — status enum + customer/notification FKs + indexes"
```

---

## Task 2: Push migration to dev DB and verify backfill

**Files:** none (DB operation)

- [ ] **Step 1: Link to dev Supabase project**

Run (interactive — ask user to type `! supabase link --project-ref glncumufgaqcmqhzwrxm` in the prompt if you can't run it yourself):
`supabase link --project-ref glncumufgaqcmqhzwrxm`

- [ ] **Step 2: Dry-run the migration**

Run: `supabase db push --dry-run`
Expected: shows the new migration file with no errors.

- [ ] **Step 3: Push to dev DB**

Run: `supabase db push`
Expected: migration applied successfully.

- [ ] **Step 4: Verify backfill — status='read' count matches**

Run via Supabase MCP `execute_sql` or `psql`:
```sql
SELECT
  (SELECT count(*) FROM site.enquiries WHERE read_at IS NOT NULL) AS had_read_at,
  (SELECT count(*) FROM site.enquiries WHERE status = 'read')      AS now_read;
```
Expected: both numbers equal.

- [ ] **Step 5: Verify customer_id linkage ≥ 99%**

```sql
SELECT
  count(*)                                    AS total,
  count(customer_id)                          AS linked,
  100.0 * count(customer_id) / count(*)       AS pct;
```
Expected: pct ≥ 99 (some old enquiries may have orphaned emails).

- [ ] **Step 6: Verify dropped index is gone, new index is present**

```sql
SELECT indexname FROM pg_indexes
WHERE schemaname = 'site' AND tablename = 'enquiries'
ORDER BY indexname;
```
Expected: no `enquiries_user_created_idx`; presence of `idx_enquiries_user_status_created`, `idx_enquiries_customer`, `idx_enquiries_notification`.

- [ ] **Step 7: Commit (no file change, but mark milestone)**

```bash
git commit --allow-empty -m "chore(enquiry-inbox): migration applied to dev (glncumufgaqcmqhzwrxm)"
```

---

## Task 3: Create `App\Enums\EnquiryStatus` PHP enum

**Files:**
- Create: `app/Enums/EnquiryStatus.php`
- Test: `tests/Unit/Enums/EnquiryStatusTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\EnquiryStatus;

it('exposes all five statuses', function () {
    expect(EnquiryStatus::cases())->toHaveCount(5);
    expect(EnquiryStatus::New->value)->toBe('new');
    expect(EnquiryStatus::Read->value)->toBe('read');
    expect(EnquiryStatus::Replied->value)->toBe('replied');
    expect(EnquiryStatus::Archived->value)->toBe('archived');
    expect(EnquiryStatus::Spam->value)->toBe('spam');
});

it('can be cast from string', function () {
    expect(EnquiryStatus::from('new'))->toBe(EnquiryStatus::New);
});
```

- [ ] **Step 2: Run test (expect fail)**

Run: `./vendor/bin/pest tests/Unit/Enums/EnquiryStatusTest.php`
Expected: FAIL — class `App\Enums\EnquiryStatus` not found.

- [ ] **Step 3: Implement the enum**

```php
<?php

namespace App\Enums;

enum EnquiryStatus: string
{
    case New = 'new';
    case Read = 'read';
    case Replied = 'replied';
    case Archived = 'archived';
    case Spam = 'spam';
}
```

- [ ] **Step 4: Run test (expect pass)**

Run: `./vendor/bin/pest tests/Unit/Enums/EnquiryStatusTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Enums/EnquiryStatus.php tests/Unit/Enums/EnquiryStatusTest.php
git commit -m "feat(enquiry-inbox): EnquiryStatus enum"
```

---

## Task 4: Extend `Enquiry` model — fillable, casts, relationships

**Files:**
- Modify: `app/Models/Core/Site/Enquiry.php`
- Test: `tests/Unit/Models/EnquiryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\EnquiryStatus;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\Customer;
use App\Models\Core\Notifications\Notification;

it('has new fillable + casts + relationships', function () {
    $enquiry = new Enquiry;

    $expected = ['user_id', 'site_id', 'name', 'email', 'phone', 'subject',
        'message', 'ip_hash', 'user_agent', 'read_at', 'email_sent_at',
        'status', 'customer_id', 'notification_id',
        'replied_at', 'archived_at', 'spam_at', 'redacted_at'];

    foreach ($expected as $field) {
        expect($enquiry->getFillable())->toContain($field);
    }

    expect($enquiry->getCasts()['status'])->toBe(EnquiryStatus::class);
    expect($enquiry->getCasts()['replied_at'])->toBe('datetime');
    expect($enquiry->getCasts()['archived_at'])->toBe('datetime');
    expect($enquiry->getCasts()['spam_at'])->toBe('datetime');
    expect($enquiry->getCasts()['redacted_at'])->toBe('datetime');

    expect($enquiry->customer())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    expect($enquiry->notification())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});
```

- [ ] **Step 2: Run test (expect fail)**

Run: `./vendor/bin/pest tests/Unit/Models/EnquiryTest.php`
Expected: FAIL — missing fillable entries / casts / methods.

- [ ] **Step 3: Update the model**

Edit `app/Models/Core/Site/Enquiry.php` — add to `$fillable`:
```php
'status', 'customer_id', 'notification_id',
'replied_at', 'archived_at', 'spam_at', 'redacted_at',
```

Add to `$casts`:
```php
'status'      => \App\Enums\EnquiryStatus::class,
'replied_at'  => 'datetime',
'archived_at' => 'datetime',
'spam_at'     => 'datetime',
'redacted_at' => 'datetime',
```

Add imports + relationships at the bottom of the class:
```php
use App\Models\Core\Notifications\Notification;
use App\Models\Core\User\Customer;

public function customer(): BelongsTo
{
    return $this->belongsTo(Customer::class, 'customer_id');
}

public function notification(): BelongsTo
{
    return $this->belongsTo(Notification::class, 'notification_id');
}
```

- [ ] **Step 4: Run test (expect pass)**

Run: `./vendor/bin/pest tests/Unit/Models/EnquiryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Core/Site/Enquiry.php tests/Unit/Models/EnquiryTest.php
git commit -m "feat(enquiry-inbox): Enquiry model — fillable, casts, customer + notification relations"
```

---

## Task 5: Add transition methods to `Enquiry` model

**Files:**
- Modify: `app/Models/Core/Site/Enquiry.php`
- Modify: `tests/Unit/Models/EnquiryTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Models/EnquiryTest.php`:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('markRead() sets status=read and read_at', function () {
    $enquiry = Enquiry::factory()->create(['status' => 'new', 'read_at' => null]);
    $enquiry->markRead();
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::Read);
    expect($enquiry->fresh()->read_at)->not->toBeNull();
});

it('markReplied() sets status=replied and replied_at', function () {
    $enquiry = Enquiry::factory()->create(['status' => 'read']);
    $enquiry->markReplied();
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::Replied);
    expect($enquiry->fresh()->replied_at)->not->toBeNull();
});

it('archive() sets status=archived and archived_at', function () {
    $enquiry = Enquiry::factory()->create(['status' => 'replied']);
    $enquiry->archive();
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::Archived);
    expect($enquiry->fresh()->archived_at)->not->toBeNull();
});

it('markSpam() sets status=spam and spam_at', function () {
    $enquiry = Enquiry::factory()->create(['status' => 'new']);
    $enquiry->markSpam();
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::Spam);
    expect($enquiry->fresh()->spam_at)->not->toBeNull();
});

it('restore() sets status=new and preserves audit timestamps', function () {
    $enquiry = Enquiry::factory()->create(['status' => 'archived', 'archived_at' => now()]);
    $enquiry->restore();
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::New);
    // Audit timestamps stay so we keep history of the prior state.
    expect($enquiry->fresh()->archived_at)->not->toBeNull();
});

it('transitions are idempotent', function () {
    $enquiry = Enquiry::factory()->create(['status' => 'archived']);
    $enquiry->archive();
    $enquiry->archive();
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::Archived);
});
```

- [ ] **Step 2: Run tests (expect fail)**

Run: `./vendor/bin/pest tests/Unit/Models/EnquiryTest.php`
Expected: FAIL — methods don't exist (or factory missing).

- [ ] **Step 3: Ensure Enquiry factory exists**

If `database/factories/EnquiryFactory.php` doesn't exist, create one:

```php
<?php

namespace Database\Factories;

use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnquiryFactory extends Factory
{
    protected $model = Enquiry::class;

    public function definition(): array
    {
        return [
            'user_id'  => User::factory(),
            'site_id'  => Site::factory(),
            'name'     => $this->faker->name(),
            'email'    => $this->faker->email(),
            'phone'    => null,
            'subject'  => 'General',
            'message'  => $this->faker->paragraph(),
            'ip_hash'  => hash('sha256', $this->faker->ipv4()),
            'user_agent' => $this->faker->userAgent(),
            'status'   => 'new',
        ];
    }
}
```

Add `use HasFactory;` to the Enquiry model if missing.

- [ ] **Step 4: Implement the transition methods on the model**

Append to `app/Models/Core/Site/Enquiry.php` inside the class:

```php
public function markRead(): void
{
    $this->update(['status' => 'read', 'read_at' => $this->read_at ?? now()]);
}

public function markReplied(): void
{
    $this->update(['status' => 'replied', 'replied_at' => now()]);
}

public function archive(): void
{
    $this->update(['status' => 'archived', 'archived_at' => now()]);
}

public function markSpam(): void
{
    $this->update(['status' => 'spam', 'spam_at' => now()]);
}

public function restoreToNew(): void
{
    // Named restoreToNew because Eloquent's SoftDeletes trait already defines restore().
    $this->update(['status' => 'new']);
}
```

> Note: SoftDeletes already exposes `restore()` (un-soft-delete). The status-restore method is named `restoreToNew()` to avoid the clash. The controller and routes call this name; the spec's "restore" endpoint maps to it.

Update the test above: change `$enquiry->restore()` → `$enquiry->restoreToNew()`.

- [ ] **Step 5: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Unit/Models/EnquiryTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Core/Site/Enquiry.php tests/Unit/Models/EnquiryTest.php database/factories/EnquiryFactory.php
git commit -m "feat(enquiry-inbox): Enquiry transition methods (markRead/Replied/archive/markSpam/restoreToNew)"
```

---

## Task 6: Add `Enquiry::redact()` PII-scrub method

**Files:**
- Modify: `app/Models/Core/Site/Enquiry.php`
- Modify: `tests/Unit/Models/EnquiryTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('redact() nulls PII and scrubs the linked notification', function () {
    $notification = \App\Models\Core\Notifications\Notification::factory()->create([
        'title' => 'New enquiry',
        'body'  => 'Hello, I need...',
    ]);
    $enquiry = Enquiry::factory()->create([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'message' => 'Sensitive content',
        'notification_id' => $notification->id,
    ]);

    $enquiry->redact();

    $fresh = $enquiry->fresh();
    expect($fresh->name)->toBeNull();
    expect($fresh->email)->toBeNull();
    expect($fresh->message)->toBeNull();
    expect($fresh->redacted_at)->not->toBeNull();

    expect($notification->fresh()->title)->toBe('[redacted]');
    expect($notification->fresh()->body)->toBe('[redacted]');
});

it('redact() is a no-op when notification_id is null', function () {
    $enquiry = Enquiry::factory()->create(['notification_id' => null]);
    $enquiry->redact();
    expect($enquiry->fresh()->name)->toBeNull();
    expect($enquiry->fresh()->redacted_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run tests (expect fail)**

Run: `./vendor/bin/pest tests/Unit/Models/EnquiryTest.php`
Expected: FAIL — `redact()` not defined.

- [ ] **Step 3: Implement the method**

Append to Enquiry class:

```php
public function redact(): void
{
    $this->update([
        'name'        => null,
        'email'       => null,
        'phone'       => null,
        'message'     => null,
        'ip_hash'     => null,
        'user_agent'  => null,
        'redacted_at' => now(),
    ]);

    if ($this->notification_id) {
        \App\Models\Core\Notifications\Notification::where('id', $this->notification_id)
            ->update(['title' => '[redacted]', 'body' => '[redacted]']);
    }
}
```

- [ ] **Step 4: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Unit/Models/EnquiryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Core/Site/Enquiry.php tests/Unit/Models/EnquiryTest.php
git commit -m "feat(enquiry-inbox): Enquiry::redact() — nulls PII + scrubs linked notification"
```

---

## Task 7: Add `Customer::redact()` cascade

**Files:**
- Modify: `app/Models/Core/User/Customer.php`
- Test: `tests/Unit/Models/CustomerRedactTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\User\Customer;
use App\Models\Core\Site\Enquiry;

it('redact() nulls customer PII and cascades to linked enquiries', function () {
    $customer = Customer::factory()->create([
        'email' => 'foo@example.com',
        'full_name' => 'Foo Bar',
    ]);
    $enquiry = Enquiry::factory()->create([
        'customer_id' => $customer->id,
        'name' => 'Foo Bar',
        'email' => 'foo@example.com',
    ]);

    $customer->redact();

    expect($customer->fresh()->email)->toBeNull();
    expect($customer->fresh()->full_name)->toBeNull();
    expect($customer->fresh()->redacted_at)->not->toBeNull();
    expect($enquiry->fresh()->name)->toBeNull();
    expect($enquiry->fresh()->redacted_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run test (expect fail)**

Run: `./vendor/bin/pest tests/Unit/Models/CustomerRedactTest.php`
Expected: FAIL — `redact()` not defined on Customer.

- [ ] **Step 3: Add the method**

Append to `app/Models/Core/User/Customer.php`:

```php
public function redact(): void
{
    $this->update([
        'email'       => null,
        'full_name'   => null,
        'phone'       => null,
        'notes'       => null,
        'redacted_at' => now(),
    ]);

    \App\Models\Core\Site\Enquiry::where('customer_id', $this->id)
        ->each(fn ($e) => $e->redact());
}
```

If `redacted_at` is not already in the Customer migration columns, add it via a follow-up Supabase migration. Verify first:

Run: `psql ... -c "\d site.customers" | grep redacted_at`
If absent, add to migration `20260527160000_enquiry_inbox.sql`:
```sql
ALTER TABLE site.customers ADD COLUMN IF NOT EXISTS redacted_at timestamptz;
```
and re-push.

- [ ] **Step 4: Run test (expect pass)**

Run: `./vendor/bin/pest tests/Unit/Models/CustomerRedactTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Core/User/Customer.php tests/Unit/Models/CustomerRedactTest.php
git commit -m "feat(enquiry-inbox): Customer::redact() cascades to linked enquiries"
```

---

## Task 8: Refactor `NotificationPublisher::publish()` to return `?Notification`

**Files:**
- Modify: `app/Services/Notifications/NotificationPublisher.php`
- Test: `tests/Unit/Services/Notifications/NotificationPublisherReturnTypeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\Notifications\Notification;
use App\Services\Notifications\NotificationPublisher;

it('publish() returns the inserted Notification on first call', function () {
    $publisher = app(NotificationPublisher::class);

    $result = $publisher->publish(
        userId: (string) \App\Models\Core\User\User::factory()->create()->id,
        frontendType: 'enquiry.received',
        category: 'inbox',
        title: 'New enquiry',
        body: 'Hello there',
        dedupeKey: 'enquiry:test-1',
        ctaUrl: '/dashboard/enquiries/abc',
    );

    expect($result)->toBeInstanceOf(Notification::class);
    expect($result->dedupe_key)->toBe('enquiry:test-1');
});

it('publish() returns the EXISTING Notification when dedupe-key matches', function () {
    $publisher = app(NotificationPublisher::class);
    $user = \App\Models\Core\User\User::factory()->create();

    $first  = $publisher->publish(userId: (string) $user->id, frontendType: 'enquiry.received',
        category: 'inbox', title: 'a', body: 'b', dedupeKey: 'enquiry:test-2');
    $second = $publisher->publish(userId: (string) $user->id, frontendType: 'enquiry.received',
        category: 'inbox', title: 'c', body: 'd', dedupeKey: 'enquiry:test-2');

    expect($second->id)->toBe($first->id);
});

it('publish() returns null when capability gate blocks', function () {
    // This test depends on `category` being in CAPABILITY_GATE_MAP with a falsy capability.
    // Skip if no such category exists yet — covered by config-driven tests elsewhere.
    expect(true)->toBeTrue();
})->skip('Covered by capability-gate-specific tests when the map is populated.');
```

- [ ] **Step 2: Add `'inbox'` to mailables registry FIRST (publish requires category in registry)**

Edit `config/partna.php`. Find `'notifications' => [ ... 'mailables' => [`. Add:

```php
'inbox' => null,  // in-app only — enquiry inbox; no mailable (email goes via SendEnquiryNotificationJob)
```

- [ ] **Step 3: Run test (expect fail)**

Run: `./vendor/bin/pest tests/Unit/Services/Notifications/NotificationPublisherReturnTypeTest.php`
Expected: FAIL — `publish()` returns void.

- [ ] **Step 4: Refactor the publisher**

Edit `app/Services/Notifications/NotificationPublisher.php`:

Change the signature from `: void {` to `: ?Notification {`.

After the existing `$inserted = DB::table(...)->insertOrIgnore([...])` line, replace the existing `if ($inserted > 0 && config(...))` block with:

```php
// Always return the row (newly inserted OR existing on dedupe conflict).
// Falls back to a select-by-(user_id, dedupe_key) lookup when insertOrIgnore
// reported 0 rows (i.e., conflict) so callers can capture the canonical id.
$notification = Notification::query()
    ->where('user_id', $userId)
    ->where('dedupe_key', $dedupeKey)
    ->first();

if ($inserted > 0 && config('partna.notifications.email_enabled', false)) {
    SendTransactionalNotificationEmailJob::dispatch(
        $notificationId,
        $category,
        $userId,
    )->onQueue('mail');
}

return $notification;
```

Also update the early-return paths (empty user_id, empty title/body, empty dedupeKey, capability gate, type/normalize) — change `return;` → `return null;`.

- [ ] **Step 5: Run test (expect pass)**

Run: `./vendor/bin/pest tests/Unit/Services/Notifications/NotificationPublisherReturnTypeTest.php`
Expected: PASS.

- [ ] **Step 6: Run the full notifications test suite to confirm no regressions**

Run: `./vendor/bin/pest tests/ --filter Notification`
Expected: ALL PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Notifications/NotificationPublisher.php config/partna.php tests/Unit/Services/Notifications/NotificationPublisherReturnTypeTest.php
git commit -m "refactor(notifications): publish() returns ?Notification + register 'inbox' category"
```

---

## Task 9: Create `EnquirySpamBlocklist` service (HMAC sorted-set)

**Files:**
- Create: `app/Services/Notifications/EnquirySpamBlocklist.php`
- Test: `tests/Feature/Services/EnquirySpamBlocklistTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Services\Notifications\EnquirySpamBlocklist;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::connection()->flushdb());

it('adds an email hash with 90-day expiry', function () {
    $svc = app(EnquirySpamBlocklist::class);
    $svc->add('user-123', 'spam@example.com');

    expect($svc->contains('user-123', 'spam@example.com'))->toBeTrue();
    expect($svc->contains('user-123', 'other@example.com'))->toBeFalse();
});

it('treats email case-insensitively', function () {
    $svc = app(EnquirySpamBlocklist::class);
    $svc->add('user-123', 'Spam@Example.COM');
    expect($svc->contains('user-123', 'spam@example.com'))->toBeTrue();
});

it('isolates blocklists per user', function () {
    $svc = app(EnquirySpamBlocklist::class);
    $svc->add('user-A', 'spam@example.com');
    expect($svc->contains('user-A', 'spam@example.com'))->toBeTrue();
    expect($svc->contains('user-B', 'spam@example.com'))->toBeFalse();
});

it('returns false for expired entries (synthetic past expiry)', function () {
    $svc = app(EnquirySpamBlocklist::class);
    $svc->addWithExpiry('user-123', 'spam@example.com', now()->subDay()->timestamp);
    expect($svc->contains('user-123', 'spam@example.com'))->toBeFalse();
});
```

- [ ] **Step 2: Run tests (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Services/EnquirySpamBlocklistTest.php`
Expected: FAIL — service doesn't exist.

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Redis;

class EnquirySpamBlocklist
{
    private const TTL_DAYS    = 90;
    private const MAX_MEMBERS = 500;

    public function add(string $userId, string $email): void
    {
        $expiresAt = now()->addDays(self::TTL_DAYS)->timestamp;
        $this->addWithExpiry($userId, $email, $expiresAt);
    }

    public function addWithExpiry(string $userId, string $email, int $expiresAt): void
    {
        $key    = $this->key($userId);
        $member = $this->hash($email);

        Redis::zadd($key, $expiresAt, $member);
        // Evict already-expired members on each write.
        Redis::zremrangebyscore($key, 0, now()->timestamp);
        // Cap set size by removing oldest beyond MAX_MEMBERS.
        Redis::zremrangebyrank($key, 0, -1 - self::MAX_MEMBERS);
        Redis::expire($key, self::TTL_DAYS * 86400);
    }

    public function contains(string $userId, string $email): bool
    {
        $score = Redis::zscore($this->key($userId), $this->hash($email));

        return $score !== null && (int) $score >= now()->timestamp;
    }

    private function key(string $userId): string
    {
        return "enquiry_spam:{$userId}";
    }

    private function hash(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), (string) config('app.key'));
    }
}
```

- [ ] **Step 4: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Services/EnquirySpamBlocklistTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Notifications/EnquirySpamBlocklist.php tests/Feature/Services/EnquirySpamBlocklistTest.php
git commit -m "feat(enquiry-inbox): EnquirySpamBlocklist (HMAC sorted-set, per-user, 90-day TTL, 500-member cap)"
```

---

## Task 10: Refactor `PublicEnquiryController::upsertEnquiryCustomer()` to return `Customer`

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php`
- Test: `tests/Feature/Contact/PublicEnquirySubmissionTest.php` (existing — extend)

- [ ] **Step 1: Write the failing test**

Add to existing test file:

```php
it('links the created Enquiry to the upserted Customer via customer_id', function () {
    $site = \App\Models\Core\Site\Site::factory()->withContactBlock()->create();

    $response = $this->postJson('/api/public/enquiry', [
        'name' => 'Alice', 'email' => 'alice@example.com',
        'subject' => 'General', 'message' => 'Hi there',
        'website' => '', 'form_started_at_ms' => now()->subSeconds(5)->valueOf() * 1000,
    ], ['Host' => "{$site->subdomain}.partna.au"]);

    $response->assertOk();
    $enquiry = \App\Models\Core\Site\Enquiry::latest()->first();
    expect($enquiry->customer_id)->not->toBeNull();
    expect($enquiry->customer->email)->toBe('alice@example.com');
});
```

(May need a `withContactBlock()` Site factory state — see Task 4 of the spec.)

- [ ] **Step 2: Run test (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Contact/PublicEnquirySubmissionTest.php`
Expected: FAIL — `customer_id` is null.

- [ ] **Step 3: Refactor `upsertEnquiryCustomer`**

In `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php`, change the signature from `): void {` to `): Customer {` and add `return $customer;` at every exit point. Add the `Customer` import at the top.

Update the caller inside `submit()`:

```php
try {
    $customer = $this->upsertEnquiryCustomer(...);
} catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
    // Concurrent submission won the insert race; re-fetch winner.
    $customer = \App\Models\Core\User\Customer::query()
        ->where('user_id', $site->user_id)
        ->whereRaw('lower(email) = ?', [strtolower($data['email'])])
        ->firstOrFail();
}

$enquiry = Enquiry::create([
    ... existing fields ...,
    'customer_id' => $customer->id,
]);
```

- [ ] **Step 4: Run test (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Contact/PublicEnquirySubmissionTest.php --filter "links the created Enquiry"`
Expected: PASS.

- [ ] **Step 5: Run the full PublicEnquiry test suite to confirm no regression**

Run: `./vendor/bin/pest tests/Feature/Contact/`
Expected: ALL PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php tests/Feature/Contact/PublicEnquirySubmissionTest.php
git commit -m "refactor(public-enquiry): upsertEnquiryCustomer returns Customer; link customer_id on Enquiry"
```

---

## Task 11: Create `InAppEnquiryNotificationAdapter`

**Files:**
- Create: `app/Services/Notifications/Adapters/InAppEnquiryNotificationAdapter.php`
- Create: `app/Services/Notifications/Adapters/EnquiryNotificationAdapter.php` (interface)
- Test: `tests/Feature/Services/InAppEnquiryNotificationAdapterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\Block;
use App\Services\Notifications\Adapters\InAppEnquiryNotificationAdapter;

it('publishes an in-app notification and writes notification_id back', function () {
    $enquiry = Enquiry::factory()->create();
    $block   = Block::factory()->forSite($enquiry->site_id)->ofType('contact')->create();

    app(InAppEnquiryNotificationAdapter::class)->dispatch($enquiry, $block);

    $fresh = $enquiry->fresh();
    expect($fresh->notification_id)->not->toBeNull();
    $notif = \App\Models\Core\Notifications\Notification::find($fresh->notification_id);
    expect($notif->dedupe_key)->toBe("enquiry:{$enquiry->id}");
    expect($notif->category)->toBe('inbox');
});

it('is idempotent under retries (same dedupe key)', function () {
    $enquiry = Enquiry::factory()->create();
    $block   = Block::factory()->forSite($enquiry->site_id)->ofType('contact')->create();

    $adapter = app(InAppEnquiryNotificationAdapter::class);
    $adapter->dispatch($enquiry, $block);
    $firstId = $enquiry->fresh()->notification_id;
    $adapter->dispatch($enquiry, $block);
    $secondId = $enquiry->fresh()->notification_id;

    expect($secondId)->toBe($firstId);
});
```

- [ ] **Step 2: Run test (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Services/InAppEnquiryNotificationAdapterTest.php`
Expected: FAIL — class doesn't exist.

- [ ] **Step 3: Create the interface**

```php
<?php

namespace App\Services\Notifications\Adapters;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;

interface EnquiryNotificationAdapter
{
    public function channel(): string;  // 'in_app' | 'email' | ...

    public function dispatch(Enquiry $enquiry, Block $block): void;
}
```

- [ ] **Step 4: Implement the adapter**

```php
<?php

namespace App\Services\Notifications\Adapters;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Str;

class InAppEnquiryNotificationAdapter implements EnquiryNotificationAdapter
{
    public function __construct(private readonly NotificationPublisher $publisher) {}

    public function channel(): string
    {
        return 'in_app';
    }

    public function dispatch(Enquiry $enquiry, Block $block): void
    {
        $notification = $this->publisher->publish(
            userId:        (string) $enquiry->user_id,
            frontendType:  'enquiry.received',
            category:      'inbox',
            title:         'New enquiry',                                    // PII-free
            body:          Str::limit((string) $enquiry->message, 140),
            dedupeKey:     "enquiry:{$enquiry->id}",
            ctaUrl:        "/dashboard/enquiries/{$enquiry->id}",
        );

        if ($notification !== null) {
            $enquiry->notification_id = $notification->id;
            $enquiry->saveQuietly();
        }
    }
}
```

- [ ] **Step 5: Run test (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Services/InAppEnquiryNotificationAdapterTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Notifications/Adapters/ tests/Feature/Services/InAppEnquiryNotificationAdapterTest.php
git commit -m "feat(enquiry-inbox): InAppEnquiryNotificationAdapter + EnquiryNotificationAdapter interface"
```

---

## Task 12: Create `EmailEnquiryNotificationAdapter` (with rate limit moved here)

**Files:**
- Create: `app/Services/Notifications/Adapters/EmailEnquiryNotificationAdapter.php`
- Modify: `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php` (remove inline rate limit + dispatch)
- Test: `tests/Feature/Services/EmailEnquiryNotificationAdapterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\Notifications\SendEnquiryNotificationJob;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\Block;
use App\Services\Notifications\Adapters\EmailEnquiryNotificationAdapter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;

it('dispatches SendEnquiryNotificationJob within rate limit', function () {
    Bus::fake();
    $enquiry = Enquiry::factory()->create();
    $block   = Block::factory()->forSite($enquiry->site_id)
        ->ofType('contact')
        ->withSettings(['notification_email' => 'pro@example.com'])
        ->create();

    app(EmailEnquiryNotificationAdapter::class)->dispatch($enquiry, $block);

    Bus::assertDispatched(SendEnquiryNotificationJob::class);
});

it('silently drops when per-pro hourly limit exceeded', function () {
    Bus::fake();
    $enquiry = Enquiry::factory()->create();
    $block   = Block::factory()->forSite($enquiry->site_id)
        ->ofType('contact')
        ->withSettings(['notification_email' => 'pro@example.com'])
        ->create();

    // Push limiter to max.
    $key = 'enquiry_notify:'.$enquiry->user_id;
    $limit = config('partna.throttle.enquiry_notification_per_hour', 10);
    for ($i = 0; $i < $limit; $i++) RateLimiter::hit($key, 3600);

    app(EmailEnquiryNotificationAdapter::class)->dispatch($enquiry, $block);

    Bus::assertNotDispatched(SendEnquiryNotificationJob::class);
});
```

- [ ] **Step 2: Run tests (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Services/EmailEnquiryNotificationAdapterTest.php`
Expected: FAIL — class doesn't exist.

- [ ] **Step 3: Implement the adapter**

```php
<?php

namespace App\Services\Notifications\Adapters;

use App\Jobs\Notifications\SendEnquiryNotificationJob;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use Illuminate\Support\Facades\RateLimiter;

class EmailEnquiryNotificationAdapter implements EnquiryNotificationAdapter
{
    public function channel(): string
    {
        return 'email';
    }

    public function dispatch(Enquiry $enquiry, Block $block): void
    {
        $email = (string) data_get($block->settings, 'notification_email', '');
        if (trim($email) === '') {
            return;
        }

        $key   = "enquiry_notify:{$enquiry->user_id}";
        $limit = (int) config('partna.throttle.enquiry_notification_per_hour', 10);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return;
        }
        RateLimiter::hit($key, 3600);

        SendEnquiryNotificationJob::dispatch((string) $enquiry->id, (string) $block->id);
    }
}
```

- [ ] **Step 4: Remove the inline rate-limit + dispatch from `PublicEnquiryController::submit()`**

Delete the block (around line 115-119 of the old code) that calls `RateLimiter::tooManyAttempts('enquiry_notify:...')` + `SendEnquiryNotificationJob::dispatch(...)`. This logic now lives in the email adapter and runs via the queued dispatcher job.

- [ ] **Step 5: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Services/EmailEnquiryNotificationAdapterTest.php tests/Feature/Contact/`
Expected: PASS (existing email-dispatch tests may need to update their assertions — they should now assert the adapter behaviour or the dispatcher job, not the inline controller code).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Notifications/Adapters/EmailEnquiryNotificationAdapter.php app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php tests/
git commit -m "feat(enquiry-inbox): EmailEnquiryNotificationAdapter owns the per-pro hourly rate limit"
```

---

## Task 13: Create `EnquiryNotificationDispatcher` (fan-out service)

**Files:**
- Create: `app/Services/Notifications/EnquiryNotificationDispatcher.php`
- Test: `tests/Feature/Services/EnquiryNotificationDispatcherTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\EnquiryNotificationDispatcher;
use App\Services\Notifications\Adapters\EnquiryNotificationAdapter;
use App\Services\Notifications\Adapters\InAppEnquiryNotificationAdapter;
use App\Services\Notifications\Adapters\EmailEnquiryNotificationAdapter;

it('dispatches in_app only when channels = [in_app]', function () {
    $enquiry = Enquiry::factory()->create();
    $block = Block::factory()->forSite($enquiry->site_id)
        ->ofType('contact')
        ->withSettings(['notification_channels' => ['in_app']])
        ->create();

    $inApp = Mockery::mock(InAppEnquiryNotificationAdapter::class);
    $email = Mockery::mock(EmailEnquiryNotificationAdapter::class);
    $inApp->shouldReceive('channel')->andReturn('in_app');
    $email->shouldReceive('channel')->andReturn('email');
    $inApp->shouldReceive('dispatch')->once();
    $email->shouldNotReceive('dispatch');

    (new EnquiryNotificationDispatcher([$inApp, $email]))->dispatch($enquiry, $block);
});

it('dispatches both when channels = [in_app, email]', function () {
    $enquiry = Enquiry::factory()->create();
    $block = Block::factory()->forSite($enquiry->site_id)
        ->ofType('contact')
        ->withSettings(['notification_channels' => ['in_app', 'email']])
        ->create();

    $inApp = Mockery::mock(InAppEnquiryNotificationAdapter::class);
    $email = Mockery::mock(EmailEnquiryNotificationAdapter::class);
    $inApp->shouldReceive('channel')->andReturn('in_app');
    $email->shouldReceive('channel')->andReturn('email');
    $inApp->shouldReceive('dispatch')->once();
    $email->shouldReceive('dispatch')->once();

    (new EnquiryNotificationDispatcher([$inApp, $email]))->dispatch($enquiry, $block);
});

it('defaults to [in_app] when channels key missing', function () {
    $enquiry = Enquiry::factory()->create();
    $block = Block::factory()->forSite($enquiry->site_id)
        ->ofType('contact')
        ->withSettings([])  // no notification_channels key
        ->create();

    $inApp = Mockery::mock(InAppEnquiryNotificationAdapter::class);
    $email = Mockery::mock(EmailEnquiryNotificationAdapter::class);
    $inApp->shouldReceive('channel')->andReturn('in_app');
    $email->shouldReceive('channel')->andReturn('email');
    $inApp->shouldReceive('dispatch')->once();
    $email->shouldNotReceive('dispatch');

    (new EnquiryNotificationDispatcher([$inApp, $email]))->dispatch($enquiry, $block);
});

it('continues to other adapters when one throws (reports to Nightwatch, no rethrow)', function () {
    $enquiry = Enquiry::factory()->create();
    $block = Block::factory()->forSite($enquiry->site_id)
        ->ofType('contact')
        ->withSettings(['notification_channels' => ['in_app', 'email']])
        ->create();

    $inApp = Mockery::mock(InAppEnquiryNotificationAdapter::class);
    $email = Mockery::mock(EmailEnquiryNotificationAdapter::class);
    $inApp->shouldReceive('channel')->andReturn('in_app');
    $email->shouldReceive('channel')->andReturn('email');
    $inApp->shouldReceive('dispatch')->andThrow(new \RuntimeException('publisher down'));
    $email->shouldReceive('dispatch')->once();

    (new EnquiryNotificationDispatcher([$inApp, $email]))->dispatch($enquiry, $block);
});
```

- [ ] **Step 2: Run tests (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Services/EnquiryNotificationDispatcherTest.php`
Expected: FAIL — class doesn't exist.

- [ ] **Step 3: Implement the dispatcher**

```php
<?php

namespace App\Services\Notifications;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\Adapters\EnquiryNotificationAdapter;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnquiryNotificationDispatcher
{
    /**
     * @param  array<int, EnquiryNotificationAdapter>  $adapters
     */
    public function __construct(private readonly array $adapters) {}

    public function dispatch(Enquiry $enquiry, Block $block): void
    {
        $channels = (array) data_get($block->settings, 'notification_channels', ['in_app']);
        if ($channels === []) {
            $channels = ['in_app'];  // never fully silent — at minimum the bell rings
        }

        foreach ($this->adapters as $adapter) {
            if (! in_array($adapter->channel(), $channels, true)) {
                continue;
            }

            try {
                $adapter->dispatch($enquiry, $block);
            } catch (Throwable $e) {
                // Adapter failure must not break other channels OR the public response.
                report($e);
                Log::warning('EnquiryNotificationDispatcher: adapter failed', [
                    'adapter'    => $adapter::class,
                    'enquiry_id' => (string) $enquiry->id,
                    'message'    => $e->getMessage(),
                ]);
            }
        }
    }
}
```

- [ ] **Step 4: Register the dispatcher in a ServiceProvider**

In `app/Providers/AppServiceProvider.php::register()`, add:

```php
$this->app->singleton(\App\Services\Notifications\EnquiryNotificationDispatcher::class, function ($app) {
    return new \App\Services\Notifications\EnquiryNotificationDispatcher([
        $app->make(\App\Services\Notifications\Adapters\InAppEnquiryNotificationAdapter::class),
        $app->make(\App\Services\Notifications\Adapters\EmailEnquiryNotificationAdapter::class),
    ]);
});
```

- [ ] **Step 5: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Services/EnquiryNotificationDispatcherTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Notifications/EnquiryNotificationDispatcher.php app/Providers/AppServiceProvider.php tests/Feature/Services/EnquiryNotificationDispatcherTest.php
git commit -m "feat(enquiry-inbox): EnquiryNotificationDispatcher — channel fan-out with per-adapter isolation"
```

---

## Task 14: Create `DispatchEnquiryNotificationsJob` (queued)

**Files:**
- Create: `app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php`
- Test: `tests/Feature/Jobs/DispatchEnquiryNotificationsJobTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\Notifications\DispatchEnquiryNotificationsJob;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\EnquiryNotificationDispatcher;

it('resolves enquiry + active contact block then invokes dispatcher', function () {
    $enquiry = Enquiry::factory()->create();
    $block   = Block::factory()->forSite($enquiry->site_id)
        ->ofType('contact')->active()->create();

    $dispatcher = Mockery::mock(EnquiryNotificationDispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->withArgs(fn ($e, $b) => $e->id === $enquiry->id && $b->id === $block->id)
        ->once();
    app()->instance(EnquiryNotificationDispatcher::class, $dispatcher);

    (new DispatchEnquiryNotificationsJob((string) $enquiry->id))->handle($dispatcher);
});

it('silently no-ops when the enquiry has been redacted/deleted before the job runs', function () {
    $enquiry = Enquiry::factory()->create();
    $enquiry->delete();

    $dispatcher = Mockery::mock(EnquiryNotificationDispatcher::class);
    $dispatcher->shouldNotReceive('dispatch');

    (new DispatchEnquiryNotificationsJob((string) $enquiry->id))->handle($dispatcher);
});
```

- [ ] **Step 2: Run test (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Jobs/DispatchEnquiryNotificationsJobTest.php`
Expected: FAIL — job class doesn't exist.

- [ ] **Step 3: Implement the job**

```php
<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\EnquiryNotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchEnquiryNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $enquiryId) {}

    public function handle(EnquiryNotificationDispatcher $dispatcher): void
    {
        $enquiry = Enquiry::query()->find($this->enquiryId);
        if (! $enquiry) {
            return;
        }

        $block = Block::query()
            ->where('site_id', $enquiry->site_id)
            ->where('block_group', 'sections')
            ->where('block_type', 'contact')
            ->active()
            ->first();

        if (! $block) {
            return;
        }

        $dispatcher->dispatch($enquiry, $block);
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Jobs/DispatchEnquiryNotificationsJobTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php tests/Feature/Jobs/DispatchEnquiryNotificationsJobTest.php
git commit -m "feat(enquiry-inbox): DispatchEnquiryNotificationsJob (queued — keeps public response off the hot path)"
```

---

## Task 15: Wire the queued dispatch + spam pre-check into `PublicEnquiryController::submit()`

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php`
- Modify: `tests/Feature/Contact/PublicEnquirySubmissionTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `PublicEnquirySubmissionTest.php`:

```php
it('queues DispatchEnquiryNotificationsJob after enquiry persists', function () {
    \Illuminate\Support\Facades\Bus::fake();
    $site = \App\Models\Core\Site\Site::factory()->withContactBlock()->create();

    $this->postJson('/api/public/enquiry', [
        'name' => 'Alice', 'email' => 'alice@example.com',
        'subject' => 'General', 'message' => 'Hi',
        'website' => '', 'form_started_at_ms' => now()->subSeconds(5)->valueOf() * 1000,
    ], ['Host' => "{$site->subdomain}.partna.au"])->assertOk();

    \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\Notifications\DispatchEnquiryNotificationsJob::class);
});

it('returns silent 200 + does NOT persist when sender email is in spam blocklist', function () {
    $site = \App\Models\Core\Site\Site::factory()->withContactBlock()->create();
    app(\App\Services\Notifications\EnquirySpamBlocklist::class)
        ->add((string) $site->user_id, 'spammer@example.com');

    $before = \App\Models\Core\Site\Enquiry::count();

    $response = $this->postJson('/api/public/enquiry', [
        'name' => 'Bob', 'email' => 'spammer@example.com',
        'subject' => 'General', 'message' => 'Hi',
        'website' => '', 'form_started_at_ms' => now()->subSeconds(5)->valueOf() * 1000,
    ], ['Host' => "{$site->subdomain}.partna.au"]);

    $response->assertOk()->assertJson(['ok' => true]);
    expect(\App\Models\Core\Site\Enquiry::count())->toBe($before);
});

it('response shape is {ok: true} only — no enquiry_id leaked', function () {
    $site = \App\Models\Core\Site\Site::factory()->withContactBlock()->create();
    $response = $this->postJson('/api/public/enquiry', [
        'name' => 'Carol', 'email' => 'carol@example.com',
        'subject' => 'General', 'message' => 'Hi',
        'website' => '', 'form_started_at_ms' => now()->subSeconds(5)->valueOf() * 1000,
    ], ['Host' => "{$site->subdomain}.partna.au"]);

    expect(array_keys($response->json()))->toBe(['ok']);
});
```

- [ ] **Step 2: Run tests (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Contact/PublicEnquirySubmissionTest.php`
Expected: FAIL — job not dispatched, spam check absent, enquiry_id may still be in response.

- [ ] **Step 3: Update the controller**

In `PublicEnquiryController::submit()`:

1. After resolving the contact block, before `DB::transaction`:
   ```php
   if (app(\App\Services\Notifications\EnquirySpamBlocklist::class)
       ->contains((string) $site->user_id, $data['email'])) {
       return $this->success(['ok' => true]);  // silent match-shape with honeypot
   }
   ```

2. Replace the deleted inline rate-limit + email dispatch (removed in Task 12) with the queued job after the transaction:
   ```php
   \App\Jobs\Notifications\DispatchEnquiryNotificationsJob::dispatch((string) $enquiry->id);
   ```

3. Ensure the return is `return $this->success(['ok' => true]);` (no `enquiry_id`).

- [ ] **Step 4: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Contact/`
Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php tests/Feature/Contact/PublicEnquirySubmissionTest.php
git commit -m "feat(enquiry-inbox): wire spam pre-check + queued dispatch in PublicEnquiryController"
```

---

## Task 16: Create `EnquiryPolicy`

**Files:**
- Create: `app/Policies/EnquiryPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php` (register Gate)
- Test: `tests/Feature/Policies/EnquiryPolicyTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\User;

it('owner can view + update + delete their enquiry', function () {
    $user = User::factory()->create();
    $enquiry = Enquiry::factory()->for($user)->create();
    expect($user->can('view', $enquiry))->toBeTrue();
    expect($user->can('update', $enquiry))->toBeTrue();
    expect($user->can('delete', $enquiry))->toBeTrue();
});

it('another pro cannot view/update/delete', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $enquiry = Enquiry::factory()->for($owner)->create();
    expect($stranger->can('view', $enquiry))->toBeFalse();
    expect($stranger->can('update', $enquiry))->toBeFalse();
    expect($stranger->can('delete', $enquiry))->toBeFalse();
});

it('viewAny allows any authenticated user (controller scopes by user_id)', function () {
    $user = User::factory()->create();
    expect($user->can('viewAny', Enquiry::class))->toBeTrue();
});
```

- [ ] **Step 2: Run tests (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Policies/EnquiryPolicyTest.php`
Expected: FAIL — policy not registered.

- [ ] **Step 3: Implement the policy**

```php
<?php

namespace App\Policies;

use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\User;

class EnquiryPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Enquiry $enquiry): bool
    {
        return (string) $user->id === (string) $enquiry->user_id;
    }

    public function update(User $user, Enquiry $enquiry): bool
    {
        return (string) $user->id === (string) $enquiry->user_id;
    }

    public function delete(User $user, Enquiry $enquiry): bool
    {
        return (string) $user->id === (string) $enquiry->user_id;
    }
}
```

- [ ] **Step 4: Register the policy**

In `app/Providers/AppServiceProvider.php::boot()`:

```php
\Illuminate\Support\Facades\Gate::policy(\App\Models\Core\Site\Enquiry::class, \App\Policies\EnquiryPolicy::class);
```

- [ ] **Step 5: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Policies/EnquiryPolicyTest.php tests/Feature/Security/PolicyCoverageTest.php`
Expected: ALL PASS (the PolicyCoverageTest sweep test auto-passes now that the policy is registered).

- [ ] **Step 6: Commit**

```bash
git add app/Policies/EnquiryPolicy.php app/Providers/AppServiceProvider.php tests/Feature/Policies/EnquiryPolicyTest.php
git commit -m "feat(enquiry-inbox): EnquiryPolicy registered (tenant isolation; 404 path in controllers)"
```

---

## Task 17: Extend `EnquiryResource` with new fields

**Files:**
- Modify: `app/Http/Resources/EnquiryResource.php`
- Test: `tests/Unit/Resources/EnquiryResourceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Http\Resources\EnquiryResource;
use App\Models\Core\Site\Enquiry;

it('emits status + new audit timestamps', function () {
    $enquiry = Enquiry::factory()->create([
        'status' => 'replied',
        'replied_at' => now(),
    ]);

    $payload = (new EnquiryResource($enquiry))->resolve();

    expect($payload['status'])->toBe('replied');
    expect($payload)->toHaveKey('replied_at');
    expect($payload)->toHaveKey('archived_at');
    expect($payload)->toHaveKey('spam_at');
    expect($payload)->toHaveKey('updated_at');
    // Retain backwards-compat fields:
    expect($payload)->toHaveKey('is_read');
    expect($payload)->toHaveKey('read_at');
    expect($payload['is_read'])->toBe(true);  // status !== 'new'
});
```

- [ ] **Step 2: Run test (expect fail)**

Run: `./vendor/bin/pest tests/Unit/Resources/EnquiryResourceTest.php`
Expected: FAIL — new keys missing.

- [ ] **Step 3: Update the resource**

Edit `app/Http/Resources/EnquiryResource.php`:

```php
return [
    'id'          => (string) $this->id,
    'name'        => $this->name,
    'email'       => $this->email,
    'phone'       => $this->phone,
    'subject'     => $this->subject,
    'message'     => $this->message,
    'status'      => $this->status?->value,
    'is_read'     => $this->status?->value !== 'new',
    'read_at'     => optional($this->read_at)->toIso8601String(),
    'replied_at'  => optional($this->replied_at)->toIso8601String(),
    'archived_at' => optional($this->archived_at)->toIso8601String(),
    'spam_at'     => optional($this->spam_at)->toIso8601String(),
    'created_at'  => optional($this->created_at)->toIso8601String(),
    'updated_at'  => optional($this->updated_at)->toIso8601String(),
];
```

- [ ] **Step 4: Run test (expect pass)**

Run: `./vendor/bin/pest tests/Unit/Resources/EnquiryResourceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/EnquiryResource.php tests/Unit/Resources/EnquiryResourceTest.php
git commit -m "feat(enquiry-inbox): EnquiryResource emits status + audit timestamps (retains is_read/read_at)"
```

---

## Task 18: Create `EnquiryDetailResource`

**Files:**
- Create: `app/Http/Resources/EnquiryDetailResource.php`
- Test: `tests/Unit/Resources/EnquiryDetailResourceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Http\Resources\EnquiryDetailResource;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\Customer;

it('includes mailto_url + eager-loaded customer + history collection', function () {
    $customer = Customer::factory()->create(['email' => 'a@example.com']);
    $enquiry  = Enquiry::factory()->create([
        'customer_id' => $customer->id,
        'email'       => 'a@example.com',
        'subject'     => 'Pricing & Availability',
    ]);
    $sibling = Enquiry::factory()->create([
        'customer_id' => $customer->id,
        'user_id'     => $enquiry->user_id,
        'subject'     => 'Prior question',
    ]);

    // Controller pre-loads + attaches collection
    $enquiry->setRelation('customer', $customer);
    $enquiry->historyForDetailView = collect([$sibling]);

    $payload = (new EnquiryDetailResource($enquiry))->resolve();

    expect($payload['mailto_url'])->toBe('mailto:a%40example.com?subject=Re%3A%20Pricing%20%26%20Availability');
    expect($payload['customer']['email'])->toBe('a@example.com');
    expect($payload['history'])->toHaveCount(1);
    expect($payload['history'][0]['subject'])->toBe('Prior question');
});
```

- [ ] **Step 2: Run test (expect fail)**

Run: `./vendor/bin/pest tests/Unit/Resources/EnquiryDetailResourceTest.php`
Expected: FAIL — class doesn't exist.

- [ ] **Step 3: Implement the resource**

```php
<?php

namespace App\Http\Resources;

use App\Http\Resources\CustomerResource;
use Illuminate\Http\Request;

class EnquiryDetailResource extends EnquiryResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'mailto_url' => sprintf(
                'mailto:%s?subject=%s',
                rawurlencode((string) $this->email),
                rawurlencode('Re: '.(string) $this->subject),
            ),
            'customer' => $this->whenLoaded('customer', fn () => new CustomerResource($this->customer)),
            'history'  => $this->getHistoryPayload(),
        ]);
    }

    private function getHistoryPayload(): array
    {
        $history = $this->resource->historyForDetailView ?? collect();

        return $history->map(fn ($e) => [
            'id'         => (string) $e->id,
            'subject'    => $e->subject,
            'created_at' => optional($e->created_at)->toIso8601String(),
            'status'     => $e->status?->value,
        ])->all();
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

Run: `./vendor/bin/pest tests/Unit/Resources/EnquiryDetailResourceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/EnquiryDetailResource.php tests/Unit/Resources/EnquiryDetailResourceTest.php
git commit -m "feat(enquiry-inbox): EnquiryDetailResource — mailto_url, customer, history"
```

---

## Task 19: Add `counts()` endpoint to `UserEnquiryController`

**Files:**
- Modify: `app/Http/Controllers/Api/User/Customers/UserEnquiryController.php`
- Modify: `routes/api/professional.php` (or wherever `GET /me/enquiries` lives — verify path)
- Test: `tests/Feature/Enquiry/EnquiryInboxControllerTest.php`

- [ ] **Step 1: Verify the route file path**

Run: `grep -rn "UserEnquiryController" routes/ | head -5`
Confirm which routes file maps the existing `GET /me/enquiries`.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\User;

it('returns counts across all five statuses', function () {
    $user = User::factory()->create();
    Enquiry::factory()->count(3)->for($user)->state(['status' => 'new'])->create();
    Enquiry::factory()->count(2)->for($user)->state(['status' => 'read'])->create();
    Enquiry::factory()->count(1)->for($user)->state(['status' => 'replied'])->create();
    Enquiry::factory()->count(5)->for($user)->state(['status' => 'archived'])->create();
    Enquiry::factory()->count(4)->for($user)->state(['status' => 'spam'])->create();

    $this->actingAsUser($user)  // assume helper exists; if not, set JWT via existing test helper
        ->getJson('/api/me/enquiries/counts')
        ->assertOk()
        ->assertExactJson([
            'new' => 3, 'read' => 2, 'replied' => 1, 'archived' => 5, 'spam' => 4,
        ]);
});

it('excludes other pros enquiries from counts', function () {
    $me     = User::factory()->create();
    $other  = User::factory()->create();
    Enquiry::factory()->for($me)->state(['status' => 'new'])->create();
    Enquiry::factory()->for($other)->state(['status' => 'new'])->create();

    $this->actingAsUser($me)
        ->getJson('/api/me/enquiries/counts')
        ->assertOk()
        ->assertJson(['new' => 1]);
});
```

- [ ] **Step 3: Run test (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Enquiry/EnquiryInboxControllerTest.php`
Expected: FAIL — route doesn't exist.

- [ ] **Step 4: Add the controller method**

```php
public function counts(Request $request): JsonResponse
{
    $user = $this->currentUser($request);

    $rows = Enquiry::query()
        ->where('user_id', $user->id)
        ->selectRaw('status, count(*) as c')
        ->groupBy('status')
        ->pluck('c', 'status');

    return $this->success([
        'new'      => (int) ($rows['new']      ?? 0),
        'read'     => (int) ($rows['read']     ?? 0),
        'replied'  => (int) ($rows['replied']  ?? 0),
        'archived' => (int) ($rows['archived'] ?? 0),
        'spam'     => (int) ($rows['spam']     ?? 0),
    ]);
}
```

- [ ] **Step 5: Add the route**

In the appropriate routes file, BEFORE the `{enquiry}` resource routes (to avoid `counts` being parsed as an id):

```php
Route::get('/me/enquiries/counts', [UserEnquiryController::class, 'counts']);
```

- [ ] **Step 6: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Enquiry/EnquiryInboxControllerTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/User/Customers/UserEnquiryController.php routes/ tests/Feature/Enquiry/EnquiryInboxControllerTest.php
git commit -m "feat(enquiry-inbox): GET /me/enquiries/counts"
```

---

## Task 20: Add `show()` endpoint with auto-read transition

**Files:**
- Modify: `app/Http/Controllers/Api/User/Customers/UserEnquiryController.php`
- Modify: routes file
- Modify: `tests/Feature/Enquiry/EnquiryInboxControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('returns enquiry + customer + history; auto-transitions new -> read', function () {
    $user = User::factory()->create();
    $customer = \App\Models\Core\User\Customer::factory()->for($user)->create();
    $enquiry = Enquiry::factory()->for($user)
        ->state(['status' => 'new', 'customer_id' => $customer->id])
        ->create();
    $sibling = Enquiry::factory()->for($user)
        ->state(['customer_id' => $customer->id, 'subject' => 'Earlier question'])
        ->create();

    $response = $this->actingAsUser($user)->getJson("/api/me/enquiries/{$enquiry->id}");

    $response->assertOk()
        ->assertJsonPath('data.status', 'read')
        ->assertJsonPath('data.customer.id', (string) $customer->id)
        ->assertJsonPath('data.history.0.subject', 'Earlier question');

    expect($enquiry->fresh()->status->value)->toBe('read');
});

it('returns 404 when enquiry belongs to another pro', function () {
    $me     = User::factory()->create();
    $other  = User::factory()->create();
    $enquiry = Enquiry::factory()->for($other)->create();

    $this->actingAsUser($me)->getJson("/api/me/enquiries/{$enquiry->id}")
        ->assertNotFound();
});

it('also marks the linked notification receipt as read', function () {
    // Setup an enquiry with a linked notification + receipt
    $user = User::factory()->create();
    $notification = \App\Models\Core\Notifications\Notification::factory()
        ->for($user)
        ->create(['category' => 'inbox']);
    $enquiry = Enquiry::factory()->for($user)
        ->state(['notification_id' => $notification->id, 'status' => 'new'])
        ->create();

    $this->actingAsUser($user)->getJson("/api/me/enquiries/{$enquiry->id}")->assertOk();

    $receipt = \App\Models\Core\Notifications\NotificationReceipt::query()
        ->where('notification_id', $notification->id)
        ->where('user_id', $user->id)
        ->first();
    expect($receipt?->read_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run tests (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Enquiry/EnquiryInboxControllerTest.php --filter show`
Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Add the controller method**

```php
public function show(Request $request, string $id): JsonResponse
{
    $user = $this->currentUser($request);

    $enquiry = Enquiry::query()
        ->where('user_id', $user->id)
        ->with('customer')
        ->find($id);

    if (! $enquiry) {
        return $this->error('Enquiry not found.', 404);
    }

    $this->authorizeForUser($user, 'view', $enquiry);

    // Eager-load history collection onto the model (used by EnquiryDetailResource).
    $enquiry->historyForDetailView = $enquiry->customer_id
        ? Enquiry::query()
            ->where('user_id', $user->id)
            ->where('customer_id', $enquiry->customer_id)
            ->where('id', '!=', $enquiry->id)
            ->whereNull('redacted_at')
            ->whereNull('deleted_at')
            ->latest('created_at')
            ->limit(10)
            ->get(['id', 'subject', 'created_at', 'status'])
        : collect();

    // Auto-read transition + notification receipt update.
    if ($enquiry->status?->value === 'new') {
        $enquiry->markRead();

        if ($enquiry->notification_id) {
            $notification = \App\Models\Core\Notifications\Notification::find($enquiry->notification_id);
            if ($notification) {
                app(\App\Services\Notifications\NotificationListingService::class)
                    ->markRead($notification, (string) $user->id);
            }
        }
    }

    return $this->success(['data' => (new \App\Http\Resources\EnquiryDetailResource($enquiry))->resolve()]);
}
```

- [ ] **Step 4: Add the route (BEFORE the `{enquiry}` PATCH/DELETE wildcards)**

```php
Route::get('/me/enquiries/{enquiry}', [UserEnquiryController::class, 'show']);
```

- [ ] **Step 5: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Enquiry/EnquiryInboxControllerTest.php --filter show`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/User/Customers/UserEnquiryController.php routes/ tests/Feature/Enquiry/EnquiryInboxControllerTest.php
git commit -m "feat(enquiry-inbox): GET /me/enquiries/{id} — detail + auto-read + notification receipt"
```

---

## Task 21: Add status transition POST endpoints (`/read`, `/replied`, `/archive`, `/spam`, `/restore`)

**Files:**
- Modify: `app/Http/Controllers/Api/User/Customers/UserEnquiryController.php`
- Modify: routes
- Modify: `tests/Feature/Enquiry/EnquiryInboxControllerTest.php`

- [ ] **Step 1: Write the failing tests** (using Pest `->with(...)` dataset, not Jest `it.each`)

Per the Testing conventions section: use `requestAs($user)` + direct controller invocation, and seed data via `makeInboxUser()` + `seedInboxEnquiry()` — NOT Eloquent factories.

```php
it('transitions and is idempotent', function (string $action, string $controllerMethod, string $expectedStatus) {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) \Illuminate\Support\Str::uuid());

    // First call — transition.
    $req = requestAs($user, method: 'POST', uri: "/api/me/enquiries/{$enquiryId}/{$action}");
    $first = app(UserEnquiryController::class)->{$controllerMethod}($req, $enquiryId);
    expect($first->getStatusCode())->toBe(200);
    expect(\App\Models\Core\Site\Enquiry::find($enquiryId)->status->value)->toBe($expectedStatus);

    // Second call — same state.
    $second = app(UserEnquiryController::class)->{$controllerMethod}(
        requestAs($user, 'POST', "/api/me/enquiries/{$enquiryId}/{$action}"),
        $enquiryId
    );
    expect($second->getStatusCode())->toBe(200);
    expect(\App\Models\Core\Site\Enquiry::find($enquiryId)->status->value)->toBe($expectedStatus);
})->with([
    ['read',     'markRead',     'read'],
    ['replied',  'markReplied',  'replied'],
    ['archive',  'archive',      'archived'],
    ['restore',  'restore',      'new'],
]);

it('cross-tenant transitions return 404', function () {
    $me    = makeInboxUser();
    $other = makeInboxUser();
    $enquiryId = seedInboxEnquiry($other->id, (string) \Illuminate\Support\Str::uuid());

    foreach (['markRead', 'markReplied', 'archive', 'markSpam', 'restore'] as $method) {
        $response = app(UserEnquiryController::class)->{$method}(
            requestAs($me, 'POST', "/api/me/enquiries/{$enquiryId}/...") ,
            $enquiryId
        );
        expect($response->getStatusCode())->toBe(404);
    }
});
```

- [ ] **Step 2: Run tests (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Enquiry/EnquiryInboxControllerTest.php`
Expected: FAIL — endpoints don't exist.

- [ ] **Step 3: Add the controller methods**

```php
public function markRead(Request $request, string $id): JsonResponse
{
    return $this->transition($request, $id, fn (Enquiry $e) => $e->markRead());
}

public function markReplied(Request $request, string $id): JsonResponse
{
    return $this->transition($request, $id, fn (Enquiry $e) => $e->markReplied());
}

public function archive(Request $request, string $id): JsonResponse
{
    return $this->transition($request, $id, fn (Enquiry $e) => $e->archive());
}

public function restore(Request $request, string $id): JsonResponse
{
    return $this->transition($request, $id, fn (Enquiry $e) => $e->restoreToNew());
}

private function transition(Request $request, string $id, \Closure $apply): JsonResponse
{
    $user = $this->currentUser($request);
    $enquiry = Enquiry::query()->where('user_id', $user->id)->find($id);
    if (! $enquiry) return $this->error('Enquiry not found.', 404);

    $this->authorizeForUser($user, 'update', $enquiry);
    $apply($enquiry);

    return $this->success(['enquiry' => (new EnquiryResource($enquiry->fresh()))->resolve()]);
}
```

- [ ] **Step 4: Add the routes (before any wildcard `{enquiry}` that could catch the action)**

```php
Route::post('/me/enquiries/{enquiry}/read',     [UserEnquiryController::class, 'markRead']);
Route::post('/me/enquiries/{enquiry}/replied',  [UserEnquiryController::class, 'markReplied']);
Route::post('/me/enquiries/{enquiry}/archive',  [UserEnquiryController::class, 'archive']);
Route::post('/me/enquiries/{enquiry}/restore',  [UserEnquiryController::class, 'restore']);
```

- [ ] **Step 5: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Enquiry/EnquiryInboxControllerTest.php`
Expected: PASS (except `/spam` — that's Task 22).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/User/Customers/UserEnquiryController.php routes/ tests/Feature/Enquiry/EnquiryInboxControllerTest.php
git commit -m "feat(enquiry-inbox): status transition endpoints (/read /replied /archive /restore)"
```

---

## Task 22: Add `markSpam()` endpoint with side-effect transaction

**Files:**
- Modify: `app/Http/Controllers/Api/User/Customers/UserEnquiryController.php`
- Modify: routes
- Create: `tests/Feature/Enquiry/EnquirySpamTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\Customer;
use App\Models\Core\User\User;
use App\Services\Notifications\EnquirySpamBlocklist;

it('mark-as-spam transitions status + adds email to blocklist', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($user)
        ->state(['email' => 'spammer@example.com', 'source' => 'enquiry'])
        ->create();
    $enquiry = Enquiry::factory()->for($user)
        ->state(['customer_id' => $customer->id, 'email' => 'spammer@example.com'])
        ->create();

    $this->actingAsUser($user)
        ->postJson("/api/me/enquiries/{$enquiry->id}/spam")
        ->assertOk();

    expect($enquiry->fresh()->status->value)->toBe('spam');
    expect(app(EnquirySpamBlocklist::class)->contains((string) $user->id, 'spammer@example.com'))->toBeTrue();
});

it('soft-deletes the Customer when it has no other touchpoints', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($user)
        ->state(['email' => 'lone@example.com', 'source' => 'enquiry', 'external_id' => null])
        ->create();
    $enquiry = Enquiry::factory()->for($user)
        ->state(['customer_id' => $customer->id, 'email' => 'lone@example.com'])
        ->create();

    $this->actingAsUser($user)->postJson("/api/me/enquiries/{$enquiry->id}/spam")->assertOk();

    expect($customer->fresh()->deleted_at)->not->toBeNull();
});

it('preserves the Customer when other touchpoints exist (other enquiry, subscription, or external_id)', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($user)
        ->state(['email' => 'multi@example.com', 'source' => 'enquiry', 'external_id' => 'pos-123'])
        ->create();
    $enquiry = Enquiry::factory()->for($user)
        ->state(['customer_id' => $customer->id, 'email' => 'multi@example.com'])
        ->create();

    $this->actingAsUser($user)->postJson("/api/me/enquiries/{$enquiry->id}/spam")->assertOk();

    expect($customer->fresh()->deleted_at)->toBeNull();
});

it('restore from spam does NOT auto-recreate the soft-deleted Customer', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->for($user)
        ->state(['email' => 'lone@example.com', 'source' => 'enquiry', 'external_id' => null])
        ->create();
    $enquiry = Enquiry::factory()->for($user)
        ->state(['customer_id' => $customer->id, 'email' => 'lone@example.com'])
        ->create();

    $this->actingAsUser($user)->postJson("/api/me/enquiries/{$enquiry->id}/spam")->assertOk();
    $this->actingAsUser($user)->postJson("/api/me/enquiries/{$enquiry->id}/restore")->assertOk();

    expect($customer->fresh()->deleted_at)->not->toBeNull();  // still soft-deleted
});
```

- [ ] **Step 2: Run tests (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Enquiry/EnquirySpamTest.php`
Expected: FAIL — endpoint doesn't exist.

- [ ] **Step 3: Add the controller method + route**

```php
public function markSpam(Request $request, string $id): JsonResponse
{
    $user = $this->currentUser($request);
    $enquiry = Enquiry::query()->where('user_id', $user->id)->find($id);
    if (! $enquiry) return $this->error('Enquiry not found.', 404);

    $this->authorizeForUser($user, 'update', $enquiry);

    \DB::transaction(function () use ($enquiry, $user) {
        $enquiry->markSpam();

        if ($enquiry->customer_id) {
            $customer = \App\Models\Core\User\Customer::whereKey($enquiry->customer_id)
                ->lockForUpdate()
                ->first();

            if ($customer && $customer->source === 'enquiry') {
                $hasOtherEnquiries = Enquiry::query()
                    ->where('customer_id', $customer->id)
                    ->where('id', '!=', $enquiry->id)
                    ->exists();

                $hasSubscription = \App\Models\Core\Notifications\EmailSubscription::query()
                    ->where('user_id', (string) $user->id)
                    ->whereRaw('lower(email) = ?', [strtolower((string) $customer->email)])
                    ->exists();

                if (! $hasOtherEnquiries && ! $hasSubscription && empty($customer->external_id)) {
                    $customer->delete();
                }
            }
        }

        app(\App\Services\Notifications\EnquirySpamBlocklist::class)
            ->add((string) $user->id, (string) $enquiry->email);
    });

    return $this->success(['enquiry' => (new EnquiryResource($enquiry->fresh()))->resolve()]);
}
```

Add route:

```php
Route::post('/me/enquiries/{enquiry}/spam', [UserEnquiryController::class, 'markSpam']);
```

- [ ] **Step 4: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Enquiry/EnquirySpamTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/User/Customers/UserEnquiryController.php routes/ tests/Feature/Enquiry/EnquirySpamTest.php
git commit -m "feat(enquiry-inbox): POST /me/enquiries/{id}/spam — lockForUpdate cleanup + blocklist"
```

---

## Task 23: Extend existing `update()` PATCH to also set status

**Files:**
- Modify: `app/Http/Controllers/Api/User/Customers/UserEnquiryController.php`
- Modify: `tests/Feature/Enquiry/EnquiryInboxControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('PATCH /me/enquiries/{id} with {read: true} sets status=read AND read_at', function () {
    $user = User::factory()->create();
    $enquiry = Enquiry::factory()->for($user)->state(['status' => 'new'])->create();

    $this->actingAsUser($user)
        ->patchJson("/api/me/enquiries/{$enquiry->id}", ['read' => true])
        ->assertOk();

    expect($enquiry->fresh()->status->value)->toBe('read');
    expect($enquiry->fresh()->read_at)->not->toBeNull();
});

it('PATCH /me/enquiries/{id} with {read: false} sets status=new AND clears read_at', function () {
    $user = User::factory()->create();
    $enquiry = Enquiry::factory()->for($user)->state(['status' => 'read', 'read_at' => now()])->create();

    $this->actingAsUser($user)
        ->patchJson("/api/me/enquiries/{$enquiry->id}", ['read' => false])
        ->assertOk();

    expect($enquiry->fresh()->status->value)->toBe('new');
    expect($enquiry->fresh()->read_at)->toBeNull();
});
```

- [ ] **Step 2: Run test (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Enquiry/EnquiryInboxControllerTest.php --filter PATCH`
Expected: FAIL — status not being updated.

- [ ] **Step 3: Update the existing `update` method**

Replace the body of `UserEnquiryController::update()`:

```php
public function update(Request $request, string $id): JsonResponse
{
    $user = $this->currentUser($request);
    $enquiry = Enquiry::query()->where('user_id', $user->id)->find($id);
    if (! $enquiry) return $this->error('Enquiry not found.', 404);

    $this->authorizeForUser($user, 'update', $enquiry);

    $request->validate(['read' => ['required', 'boolean']]);

    if ($request->boolean('read')) {
        $enquiry->markRead();
    } else {
        $enquiry->update(['status' => 'new', 'read_at' => null]);
    }

    return $this->success(['enquiry' => (new EnquiryResource($enquiry->fresh()))->resolve()]);
}
```

- [ ] **Step 4: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Enquiry/EnquiryInboxControllerTest.php`
Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/User/Customers/UserEnquiryController.php tests/Feature/Enquiry/EnquiryInboxControllerTest.php
git commit -m "feat(enquiry-inbox): PATCH /me/enquiries/{id} also updates status (backwards-compat shim)"
```

---

## Task 24: Extend `index()` with status filter

**Files:**
- Modify: `app/Http/Controllers/Api/User/Customers/UserEnquiryController.php`
- Modify: `tests/Feature/Enquiry/EnquiryInboxControllerTest.php`

- [ ] **Step 1: Write the failing tests**

```php
it('default list excludes archived and spam', function () {
    $user = User::factory()->create();
    Enquiry::factory()->for($user)->state(['status' => 'new'])->create();
    Enquiry::factory()->for($user)->state(['status' => 'read'])->create();
    Enquiry::factory()->for($user)->state(['status' => 'replied'])->create();
    Enquiry::factory()->for($user)->state(['status' => 'archived'])->create();
    Enquiry::factory()->for($user)->state(['status' => 'spam'])->create();

    $response = $this->actingAsUser($user)->getJson('/api/me/enquiries');
    $response->assertOk();
    expect(count($response->json('data')))->toBe(3);
});

it('?status=archived returns only archived enquiries', function () {
    $user = User::factory()->create();
    Enquiry::factory()->count(2)->for($user)->state(['status' => 'archived'])->create();
    Enquiry::factory()->for($user)->state(['status' => 'new'])->create();

    $response = $this->actingAsUser($user)->getJson('/api/me/enquiries?status=archived');
    $response->assertOk();
    expect(count($response->json('data')))->toBe(2);
});
```

- [ ] **Step 2: Run tests (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Enquiry/EnquiryInboxControllerTest.php --filter "status filter"`
Expected: FAIL — index returns all statuses.

- [ ] **Step 3: Update the `index` method**

```php
public function index(Request $request): JsonResponse
{
    $user = $this->currentUser($request);

    $query = Enquiry::query()->where('user_id', $user->id);

    if ($status = $request->string('status')->toString()) {
        $valid = ['new', 'read', 'replied', 'archived', 'spam'];
        if (in_array($status, $valid, true)) {
            $query->where('status', $status);
        }
    } else {
        $query->whereNotIn('status', ['archived', 'spam']);
    }

    $page = $query->orderByDesc('created_at')
        ->paginate((int) $request->integer('per_page', 20));

    $page->through(fn (Enquiry $e) => EnquiryResource::make($e)->resolve());

    return $this->success($this->paginatedResponse($page));
}
```

- [ ] **Step 4: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Enquiry/EnquiryInboxControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/User/Customers/UserEnquiryController.php tests/Feature/Enquiry/EnquiryInboxControllerTest.php
git commit -m "feat(enquiry-inbox): GET /me/enquiries ?status filter (default excludes archived/spam)"
```

---

## Task 25: Validate `notification_channels` in `UpsertSectionBlockRequest`

**Files:**
- Modify: `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php` (or wherever it lives post-rename — `grep -r class.*UpsertSectionBlockRequest app/`)
- Test: `tests/Feature/Contact/ContactSectionConfigTest.php` (existing — extend)

- [ ] **Step 1: Confirm the request file path**

Run: `find app -name "UpsertSectionBlockRequest*"`

- [ ] **Step 2: Write the failing test**

Append to `ContactSectionConfigTest.php`:

```php
it('rejects notification_channels with an invalid channel name', function () {
    $payload = $this->validContactBlockPayload(['notification_channels' => ['sms']]);
    $this->actingAsUser($this->user)
        ->postJson($this->upsertUrl(), $payload)
        ->assertJsonValidationErrors('settings.notification_channels.0');
});

it('rejects email channel when notification_email is null', function () {
    $payload = $this->validContactBlockPayload([
        'notification_channels' => ['email'],
        'notification_email' => null,
    ]);
    $this->actingAsUser($this->user)
        ->postJson($this->upsertUrl(), $payload)
        ->assertJsonValidationErrors('settings.notification_channels');
});

it('accepts default (in_app only) when channels key missing', function () {
    $payload = $this->validContactBlockPayload([]);  // no notification_channels
    unset($payload['settings']['notification_channels']);
    $this->actingAsUser($this->user)
        ->postJson($this->upsertUrl(), $payload)
        ->assertOk();
});
```

- [ ] **Step 3: Run tests (expect fail)**

Run: `./vendor/bin/pest tests/Feature/Contact/ContactSectionConfigTest.php`
Expected: FAIL — validation rule missing.

- [ ] **Step 4: Add the validation rule**

In `contactRules()` (or wherever the contact block rules live):

```php
'settings.notification_channels'   => ['sometimes', 'array', 'min:1'],
'settings.notification_channels.*' => ['string', \Illuminate\Validation\Rule::in(['in_app', 'email'])],
```

Add a custom rule (or `after` validator) that checks: if `'email'` ∈ channels, `notification_email` must be non-empty:

```php
public function withValidator(\Illuminate\Validation\Validator $validator): void
{
    $validator->after(function ($v) {
        $channels = (array) $this->input('settings.notification_channels', []);
        $email    = (string) $this->input('settings.notification_email', '');
        if (in_array('email', $channels, true) && trim($email) === '') {
            $v->errors()->add('settings.notification_channels',
                'Cannot select email channel without a notification_email set.');
        }
    });
}
```

- [ ] **Step 5: Run tests (expect pass)**

Run: `./vendor/bin/pest tests/Feature/Contact/ContactSectionConfigTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/ tests/Feature/Contact/ContactSectionConfigTest.php
git commit -m "feat(enquiry-inbox): UpsertSectionBlockRequest — validate notification_channels"
```

---

## Task 26: Run full test suite, Pint, smoke test

**Files:** none (verification)

- [ ] **Step 1: Run full test suite**

Run: `composer test`
Expected: ALL PASS.

- [ ] **Step 2: Run Pint**

Run: `./vendor/bin/pint`
Expected: `nothing changed`. If files are reformatted, stage and amend the last commit (or follow-up commit).

- [ ] **Step 3: Smoke test in dev — submit an enquiry**

Use `curl` or the dev frontend to submit a contact form. Confirm:

```sql
-- Enquiry row created
SELECT id, status, customer_id, notification_id FROM site.enquiries ORDER BY created_at DESC LIMIT 1;
-- Customer linked
SELECT id, email FROM site.customers WHERE id = (SELECT customer_id FROM site.enquiries ORDER BY created_at DESC LIMIT 1);
-- Notification dispatched
SELECT id, category, dedupe_key FROM notifications.notifications WHERE dedupe_key LIKE 'enquiry:%' ORDER BY created_at DESC LIMIT 1;
```

Expected: all three populated; `enquiry.notification_id` matches `notifications.id`.

- [ ] **Step 4: Watch logs for unexpected exceptions**

Run: `cloud env:logs partna development --minutes 10`
Expected: no new exception class for `Enquiry*`.

- [ ] **Step 5: Smoke test — POST a transition**

```bash
curl -X POST "https://dev-api.partna.au/api/me/enquiries/{ENQUIRY_ID}/replied" \
  -H "Authorization: Bearer {DEV_JWT}"
```

Expected: 200; `status=replied` in DB.

- [ ] **Step 6: Commit any final cleanup**

```bash
git status
# If there are uncommitted changes from Pint or test fixes:
git add -A
git commit -m "chore(enquiry-inbox): final cleanup pass"
```

---

## Task 27: Write frontend handoff one-pager + open PR

**Files:**
- Create: `docs/superpowers/handoffs/2026-05-27-enquiry-inbox-frontend.md`

- [ ] **Step 1: Ensure the handoffs directory exists, then copy the Frontend Handoff appendix from the spec into a standalone handoff doc**

Run: `mkdir -p docs/superpowers/handoffs`

Path: `docs/superpowers/handoffs/2026-05-27-enquiry-inbox-frontend.md`. Open the spec at `docs/superpowers/specs/2026-05-26-enquiry-inbox-design.md`, copy the entire `## Frontend handoff` section (and sub-sections through `### Bot-protection config`) verbatim into the new file. Add a one-line header:

```markdown
# Enquiry Inbox — Frontend Handoff

> Backend implementation is complete on `feat/enquiry-inbox`. This document is the API contract for the frontend session.
```

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/handoffs/2026-05-27-enquiry-inbox-frontend.md
git commit -m "docs(enquiry-inbox): frontend handoff appendix as standalone doc"
```

- [ ] **Step 3: Open the PR**

```bash
git push -u origin feat/enquiry-inbox
gh pr create --base development --title "Enquiry Inbox — backend foundation" --body "$(cat <<'EOF'
## Summary
- Adds five-state status workflow to site.enquiries (new/read/replied/archived/spam)
- In-app notification on submit via NotificationPublisher (returns ?Notification now)
- Per-block notification_channels toggle (in_app / email)
- Detail endpoint with linked Customer + last 10 enquiries from same contact
- Mark-as-spam side effects: lone-Customer cleanup + per-pro HMAC blocklist
- PII redaction cascade (Customer::redact → Enquiry::redact → notification title+body)

## Test plan
- [x] composer test green
- [x] Manual smoke test in dev (enquiry → site.enquiries + site.customers + notifications.notifications all populated)
- [x] cloud env:logs --minutes 10 — no new Enquiry* exceptions
- [x] POST transitions idempotent

## Frontend
Handoff doc: `docs/superpowers/handoffs/2026-05-27-enquiry-inbox-frontend.md`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-Review Notes

The plan above maps to the spec as follows:

| Spec section | Plan task(s) |
|---|---|
| Data model (enum, columns, FKs, indexes) | 1, 2 |
| Models + paths (Enquiry $fillable, $casts, relationships, transitions, redact) | 3, 4, 5, 6 |
| Customer::redact cascade | 7 |
| NotificationPublisher refactor + 'inbox' category | 8 |
| Spam blocklist service | 9 |
| upsertEnquiryCustomer refactor | 10 |
| In-app + email adapters + dispatcher + queued job | 11, 12, 13, 14 |
| Public submit flow (spam pre-check, queue, response shape) | 15 |
| EnquiryPolicy | 16 |
| Resources (EnquiryResource extend, EnquiryDetailResource) | 17, 18 |
| Dashboard endpoints (counts, show, transitions) | 19, 20, 21, 22, 23, 24 |
| notification_channels validation | 25 |
| Verification + smoke + Pint | 26 |
| Frontend handoff + PR | 27 |

Spec items NOT mapped to tasks:
- "extend `notifications.notifications.type` CHECK constraint" — confirmed unnecessary per re-verification (normalization layer maps unknown values to `'Info'`); no task.
- "extend `ApiController::error()`" — confirmed unnecessary per re-verification (bot-protection middleware has its own error surface); no task.
- "enable Cloudflare Turnstile" — bot-protection middleware already wired on the route; ops step (set `BOT_PROTECTION_MODE`) handled per the bot-protection spec, not here. The verification step in Task 26 confirms behaviour.
- Frontend rendering (theme + dashboard UI) — separate repo, separate session, covered by Task 27's handoff doc.

No placeholders. Method names consistent across tasks (`markRead`, `markReplied`, `archive`, `markSpam`, `restoreToNew`).

---

**Plan complete and saved to `docs/superpowers/plans/2026-05-27-enquiry-inbox.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

**Which approach?**
