# Integration-Connected Notification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a user connects an integration from the dashboard, raise an in-app bell notification naming that integration — never an email.

**Architecture:** A new `IntegrationNotifier` dispatcher publishes through the existing `NotificationPublisher` with `critical: false`, which is what makes email structurally impossible (the publisher only dispatches `SendTransactionalNotificationEmailJob` when `critical` is true). Two emit points call it: the controller trait `ManagesIntegrationConnection::upsertConnection()` for synchronous connects, and `ConnectFetchJob` for deferred ones. All filtering guards live inside the notifier so future emit points inherit them.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4. No migration — the `notifications.notifications` table and the `config/partna.php` category registry already absorb this.

**Spec:** `docs/superpowers/specs/2026-07-25-integration-connected-notification-design.md`

## Global Constraints

- **Never create Laravel migration files.** Not needed here — no schema change.
- **4-space indent, LF.** Comments explain WHY, not what. No banners, no restatements.
- Category string is exactly `integration_connected` everywhere.
- Retention config key is exactly `integration_connected`.
- Dedupe key format is exactly `integration_connected:{connection_id}`.
- `critical: false` on every publish in this feature. This is the "not an email" requirement — it is enforced by the publisher, not by convention.
- Tests run **SQLite**, production is Postgres. Nothing here is constraint-bound, so no CHECK/NOT NULL drift risk applies.
- Do **not** run `composer test` while a sibling subagent is running. Run targeted files with `php artisan test --filter=`.
- Do **not** run `git stash` at any point.

---

## File Structure

| File | Responsibility |
|---|---|
| `config/partna.php` | Register the category (`mailables`) and its retention window. Two lines. |
| `app/Services/Notifications/Dispatchers/IntegrationNotifier.php` | **New.** Owns the guards, the platform label, and the publish call. The only place that knows what an "integration connected" notification looks like. |
| `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php` | Emit point (a) — synchronous connects. One `if` after the upsert. |
| `app/Jobs/Platforms/ConnectFetchJob.php` | Emit point (b) — deferred connects, on both success paths. |
| `tests/Feature/Notifications/IntegrationConnectedNotifierTest.php` | **New.** The notifier's guards and payload shape, tested directly. |
| `tests/Feature/Platforms/IntegrationConnectedNotificationTest.php` | **New.** Both emit points end-to-end. |

Guards live in the notifier rather than at call sites deliberately: `EventsController:48` dispatches `ConnectFetchJob` for a deferred *organiser* connect (an account row, legitimately notifiable), so keeping the link/event guard at call sites would require proving no `event-`/`link-` row can ever reach that job — for every present and future dispatcher of it.

---

## Task 1: The notifier and its category

Creates the notification type and proves it behaves correctly when called directly. Nothing calls it yet.

**Files:**
- Modify: `config/partna.php` (`notifications.mailables` ~line 1690, `notification_retention_days` ~line 1628)
- Create: `app/Services/Notifications/Dispatchers/IntegrationNotifier.php`
- Test: `tests/Feature/Notifications/IntegrationConnectedNotifierTest.php`

**Interfaces:**
- Consumes: `NotificationPublisher::publish()` (existing), `PlatformRegistry::get(string): ?PlatformDescriptor` (existing, container singleton).
- Produces: `IntegrationNotifier::connected(IntegrationConnection $connection): void` — resolved via `app(IntegrationNotifier::class)` or constructor injection. Tasks 2 and 3 call exactly this signature.

- [ ] **Step 1: Register the category**

In `config/partna.php`, inside `notifications.mailables`, after the `'analytics_weekly' => null,` line:

```php
            'integration_connected' => null,                      // in-app only (user connected an integration)
```

In the same file, inside `notification_retention_days`, after the `'analytics_weekly' => 14,` line:

```php
        'integration_connected' => 30, // connect confirmation — no reason to linger
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Notifications/IntegrationConnectedNotifierTest.php`:

```php
<?php

/** @phpstan-ignore-all */

// The bell notice raised when a user connects an integration. Guards live in the
// notifier (not at its call sites) so any emit point added later inherits them —
// these tests exercise them directly for that reason.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Notifications\Dispatchers\IntegrationNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupIntegrationConnectionsTable();
    setupNotificationsTable();

    // setupNotificationsTable() does NOT create the dedupe index, and
    // NotificationPublisher dedupes via insertOrIgnore ON CONFLICT — without
    // this, duplicate publishes would silently insert twice and the dedupe
    // assertions below would pass vacuously.
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

function icnUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function icnConnection(User $user, array $overrides = []): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['handle' => 'someone'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        ...$overrides,
    ]);
}

function icnRows(User $user)
{
    return DB::table('notifications.notifications')
        ->where('user_id', $user->id)
        ->where('category', 'integration_connected')
        ->get();
}

it('publishes a non-critical in-app notification naming the platform', function () {
    $user = icnUser('icn1');
    $connection = icnConnection($user);

    app(IntegrationNotifier::class)->connected($connection);

    $rows = icnRows($user);
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row->title)->toBe('Instagram connected');
    expect($row->type)->toBe('Success');
    expect((int) $row->critical)->toBe(0);
    expect($row->cta_url)->toBe('/account/integrations');
    expect($row->dedupe_key)->toBe("integration_connected:{$connection->id}");
});

it('never queues a transactional email', function () {
    Queue::fake();
    $user = icnUser('icn2');

    app(IntegrationNotifier::class)->connected(icnConnection($user));

    Queue::assertNothingPushed();
});

it('stays silent for a row that is not yet ok', function () {
    $user = icnUser('icn3');

    app(IntegrationNotifier::class)->connected(icnConnection($user, ['last_refresh_status' => 'pending']));
    app(IntegrationNotifier::class)->connected(icnConnection($user, [
        'resource_id' => 'acct-two',
        'last_refresh_status' => 'error',
    ]));

    expect(icnRows($user))->toHaveCount(0);
});

it('stays silent for per-link and per-event rows', function () {
    $user = icnUser('icn4');

    app(IntegrationNotifier::class)->connected(icnConnection($user, [
        'platform' => 'custom',
        'resource_id' => 'link-abc',
        'resource_kind' => 'link',
    ]));
    app(IntegrationNotifier::class)->connected(icnConnection($user, [
        'platform' => 'eventbrite',
        'resource_id' => 'event-abc',
        'resource_kind' => 'event',
    ]));

    expect(icnRows($user))->toHaveCount(0);
});

it('dedupes repeat calls for the same connection row', function () {
    $user = icnUser('icn5');
    $connection = icnConnection($user);

    app(IntegrationNotifier::class)->connected($connection);
    app(IntegrationNotifier::class)->connected($connection);

    expect(icnRows($user))->toHaveCount(1);
});

it('notifies again for a different connection row on the same platform', function () {
    // A disconnect soft-deletes and a reconnect mints a NEW row (the unique index
    // is partial on deleted_at IS NULL), so the new id is a fresh dedupe key.
    $user = icnUser('icn6');

    $first = icnConnection($user);
    app(IntegrationNotifier::class)->connected($first);
    $first->delete();

    $second = icnConnection($user);
    app(IntegrationNotifier::class)->connected($second);

    expect(icnRows($user))->toHaveCount(2);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=IntegrationConnectedNotifierTest`
Expected: FAIL — `Target class [App\Services\Notifications\Dispatchers\IntegrationNotifier] does not exist.`

- [ ] **Step 4: Write the notifier**

Create `app/Services/Notifications/Dispatchers/IntegrationNotifier.php`:

```php
<?php

namespace App\Services\Notifications\Dispatchers;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

// In-app notice that a user connected an integration, naming it. Non-critical by
// design: NotificationPublisher escalates to email ONLY for critical rows, so
// bell-only is enforced by the engine rather than by remembering. Best-effort —
// a notification failure must never break a connect.
class IntegrationNotifier
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
        private readonly PlatformRegistry $registry,
    ) {}

    /**
     * Fires once per connect EPISODE. The dedupe key is the connection's UUID:
     * idx_platform_connections_unique_active is partial (WHERE deleted_at IS NULL),
     * so a disconnect→reconnect mints a new row and notifies again, while a
     * reconnect in place keeps its id and stays silent — that being a change to an
     * existing connection, not the addition of one.
     *
     * Both guards below live HERE rather than at the call sites: ConnectFetchJob is
     * dispatched by EventsController for a deferred organiser connect, so a
     * call-site guard would require proving no event/link row can ever reach that
     * job, for every present and future dispatcher of it.
     */
    public function connected(IntegrationConnection $connection): void
    {
        // Confirmed success only — deferred connects write 'pending' first, and
        // terminal failures land 'error' / 'unavailable'.
        if ($connection->last_refresh_status !== 'ok') {
            return;
        }

        // Individual links and events are not integrations the user "added";
        // notifying per row would mean eight bells for eight custom links.
        if (in_array($connection->resource_kind, ['event', 'link'], true)) {
            return;
        }

        $label = $this->platformLabel($connection->platform);

        try {
            $this->publisher->publish(
                userId: (string) $connection->user_id,
                frontendType: 'Success',
                category: 'integration_connected',
                title: "{$label} connected",
                body: "Your {$label} connection is live and will now show on your Partna page.",
                dedupeKey: "integration_connected:{$connection->id}",
                ctaUrl: '/account/integrations',
                primaryActionLabel: 'View',
                retentionConfigKey: 'integration_connected',
                critical: false,
            );
        } catch (Throwable $e) {
            report($e);
            Log::warning('IntegrationNotifier: publish failed', [
                'user_id' => $connection->user_id,
                'connection_id' => $connection->id,
                'platform' => $connection->platform,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** Registry label first ("YouTube Music"), Str::headline as the fallback. */
    private function platformLabel(?string $platform): string
    {
        $platform = trim((string) $platform);
        if ($platform === '') {
            return 'Integration';
        }

        return $this->registry->get($platform)?->getLabel() ?? Str::headline($platform);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=IntegrationConnectedNotifierTest`
Expected: PASS, 6 tests.

- [ ] **Step 6: Verify the category-coverage guard is satisfied**

`tests/Feature/Notifications/MailableCategoryCoverageTest.php` requires every registered category to have a literal `category: 'X'` emit site in `app/`. The notifier's named argument satisfies this — confirm rather than assume.

Run: `php artisan test --filter=MailableCategoryCoverageTest`
Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
php artisan pint app/Services/Notifications/Dispatchers/IntegrationNotifier.php
git add config/partna.php app/Services/Notifications/Dispatchers/IntegrationNotifier.php tests/Feature/Notifications/IntegrationConnectedNotifierTest.php
git commit -m "feat(notifications): add IntegrationNotifier and integration_connected category"
```

---

## Task 2: Wire synchronous connects

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php:154-161`
- Test: `tests/Feature/Platforms/IntegrationConnectedNotificationTest.php` (create)

**Interfaces:**
- Consumes: `IntegrationNotifier::connected(IntegrationConnection $connection): void` from Task 1.
- Produces: nothing new.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Platforms/IntegrationConnectedNotificationTest.php`:

```php
<?php

/** @phpstan-ignore-all */

// End-to-end wiring of the integration-connected bell notice at both emit points:
// the controller trait (synchronous connects) and ConnectFetchJob (deferred ones).

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\OEmbedService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();

    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

function icwUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function icwRows(User $user)
{
    return DB::table('notifications.notifications')
        ->where('user_id', $user->id)
        ->where('category', 'integration_connected')
        ->get();
}

it('notifies on a synchronous dashboard connect', function () {
    config(['partna.connect.deferred' => []]);
    $user = icwUser('icw1');

    $this->mock(OEmbedService::class, fn ($m) => $m->shouldReceive('resolve')->once()->andReturn([
        'name' => 'Artist', 'thumbnail' => 'https://i.scdn.co/t.jpg', 'embedUrl' => null,
    ]));

    actingAsUser($user)
        ->postJson('/api/platforms/spotify/connect', ['url' => 'https://open.spotify.com/artist/abc123'])
        ->assertOk();

    $rows = icwRows($user);
    expect($rows)->toHaveCount(1);
    expect($rows->first()->title)->toContain('Spotify');
});

it('does not notify when a custom link is added', function () {
    $user = icwUser('icw2');

    actingAsUser($user)
        ->postJson('/api/platforms/custom/links', ['url' => 'https://example.com', 'label' => 'My site'])
        ->assertSuccessful();

    expect(icwRows($user))->toHaveCount(0);
});

it('does not notify for a connection created outside the dashboard trait', function () {
    // Seeders (pre-account builds, auto-sync) write the model directly and never
    // reach upsertConnection() — the user did not connect these.
    $user = icwUser('icw3');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['handle' => 'seeded'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    expect(icwRows($user))->toHaveCount(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=IntegrationConnectedNotificationTest`
Expected: the first test FAILS asserting count 1 but receiving 0. The other two already pass (nothing notifies yet) — that is expected and correct; they are regression guards, not drivers.

- [ ] **Step 3: Add the import**

In `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php`, add to the `use` block in alphabetical position:

```php
use App\Services\Notifications\Dispatchers\IntegrationNotifier;
```

- [ ] **Step 4: Wire the emit point**

Replace the `return IntegrationConnection::updateOrCreate(...)` block at lines 154-161 with:

```php
        $connection = IntegrationConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'resource_id' => $resourceId ?? $this->defaultResourceId(),
            ],
            $values,
        );

        // Bell notice on a genuine connect. wasRecentlyCreated is checked HERE and
        // not inside the notifier because it is a per-INSTANCE flag, true only on
        // the object that performed the insert — ConnectFetchJob's freshly-loaded
        // row always reports false, which is why that path calls the notifier
        // itself. The status and resource-kind guards live in the notifier, so a
        // 'pending' deferred write falls through silently here.
        if ($connection->wasRecentlyCreated) {
            app(IntegrationNotifier::class)->connected($connection);
        }

        return $connection;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=IntegrationConnectedNotificationTest`
Expected: PASS, 3 tests.

- [ ] **Step 6: Run the platform suite for regressions**

The trait is used by every platform controller, so a mistake here is broad.

Run: `php artisan test tests/Feature/Platforms`
Expected: PASS, no new failures.

- [ ] **Step 7: Format and commit**

```bash
php artisan pint app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php
git add app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php tests/Feature/Platforms/IntegrationConnectedNotificationTest.php
git commit -m "feat(notifications): notify on synchronous integration connect"
```

---

## Task 3: Wire deferred connects

**Files:**
- Modify: `app/Jobs/Platforms/ConnectFetchJob.php` (imports; `handle()` signature ~line 95; the `FetchNotModifiedException` catch ~line 139; the locked write ~line 209-224)
- Test: `tests/Feature/Platforms/IntegrationConnectedNotificationTest.php` (append)

**Interfaces:**
- Consumes: `IntegrationNotifier::connected(IntegrationConnection $connection): void` from Task 1.
- Produces: `ConnectFetchJob::handle()` gains a fourth parameter, `IntegrationNotifier $notifier`. This is safe: every existing test invokes it as `app()->call([new ConnectFetchJob($id, $platform), 'handle'])`, so the container resolves the new argument. Do **not** change the constructor.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Platforms/IntegrationConnectedNotificationTest.php`:

```php
it('does not notify while a deferred connect is still pending', function () {
    $user = icwUser('icw4');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/example'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    expect(icwRows($user))->toHaveCount(0);
});

it('notifies once ConnectFetchJob completes a deferred connect', function () {
    $user = icwUser('icw5');

    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/example'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    app()->call([new App\Jobs\Platforms\ConnectFetchJob($row->id, 'skool'), 'handle']);

    expect($row->fresh()->last_refresh_status)->toBe('ok');

    $rows = icwRows($user);
    expect($rows)->toHaveCount(1);
    expect($rows->first()->title)->toContain('Skool');
});

it('does not notify when ConnectFetchJob fails terminally', function () {
    $user = icwUser('icw6');

    // An unregistered platform takes markTerminal('error', 'unsupported_platform')
    // — the earliest terminal branch in the job, and the one that needs no vendor
    // stubbing to reach.
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'not-a-real-platform',
        'resource_id' => 'not-a-real-platform',
        'payload' => [],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    app()->call([new App\Jobs\Platforms\ConnectFetchJob($row->id, 'not-a-real-platform'), 'handle']);

    expect($row->fresh()->last_refresh_status)->toBe('error');
    expect(icwRows($user))->toHaveCount(0);
});
```

**Note for the implementer:** the "notifies once ConnectFetchJob completes" test needs Skool's fetch strategy to succeed. Read `tests/Feature/Platforms/SkoolAsyncConnectTest.php` and copy its HTTP/scraper stubbing into this test verbatim — do not invent a stub. If Skool proves awkward, substitute whichever deferred platform that file already stubs cleanly and adjust the expected label. The assertion that matters is one notification bearing the platform's label.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=IntegrationConnectedNotificationTest`
Expected: "notifies once ConnectFetchJob completes" FAILS asserting count 1, receiving 0. The two negative tests pass already.

- [ ] **Step 3: Add the import**

In `app/Jobs/Platforms/ConnectFetchJob.php`, add to the `use` block in alphabetical position (after the `App\Services\Http\FetchBudget` line):

```php
use App\Services\Notifications\Dispatchers\IntegrationNotifier;
```

- [ ] **Step 4: Add the parameter to handle()**

Change the signature at line 95 from:

```php
    public function handle(PlatformRegistry $registry, HighlightsPicker $picker, FetchBudget $budget): void
```

to:

```php
    public function handle(PlatformRegistry $registry, HighlightsPicker $picker, FetchBudget $budget, IntegrationNotifier $notifier): void
```

- [ ] **Step 5: Notify on the 304 success path**

In the `catch (FetchNotModifiedException)` block, after the existing `$this->markOk($connection);` and before the `return;`:

```php
            $this->markOk($connection);
            // A 304 is a SUCCESSFUL connect — the vendor confirmed the stored
            // payload is current. Skipping it here would silently drop the notice
            // for exactly the reconnect case. markOk() saves quietly but mutates
            // the in-memory row first, so the notifier's 'ok' guard passes.
            $notifier->connected($connection);

            return;
```

- [ ] **Step 6: Notify on the locked-write success path**

Inside the `try` block, immediately after the `Cache::lock($key, 10)->block(5, function () { ... });` call and before the `} catch (LockTimeoutException $e) {`:

```php
            });

            // Reached only when the locked write succeeded — a thrown
            // LockTimeoutException skips straight to the catch, where
            // markTerminal() lands 'unavailable' and no notice is owed.
            $notifier->connected($connection);
        } catch (LockTimeoutException $e) {
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=IntegrationConnectedNotificationTest`
Expected: PASS, 6 tests.

- [ ] **Step 8: Run the async-connect suites for regressions**

Every deferred-connect test invokes this job; a signature or control-flow mistake shows up here.

Run: `php artisan test tests/Feature/Platforms tests/Feature/Notifications`
Expected: PASS, no new failures.

- [ ] **Step 9: Format and commit**

```bash
php artisan pint app/Jobs/Platforms/ConnectFetchJob.php
git add app/Jobs/Platforms/ConnectFetchJob.php tests/Feature/Platforms/IntegrationConnectedNotificationTest.php
git commit -m "feat(notifications): notify on deferred integration connect completion"
```

---

## Task 4: Full-suite verification

**Files:** none — verification only.

- [ ] **Step 1: Run the full suite**

```bash
COMPOSER_PROCESS_TIMEOUT=0 composer test
```

**Expected: NOT green.** This branch was cut from a red baseline, recorded before
any work began:

| Suite | Baseline |
|---|---|
| `tests/Feature/Platforms` | 1288 passed, **0 failed** |
| `tests/Feature/Notifications` | 71 passed, **12 failed** |

All 12 pre-existing failures are one root cause — `ArgumentCountError` on the
`EmailBrand` constructor (`app/Mail/Branding/EmailBrand.php:17`) — in the
email-branding cluster, disjoint from this feature and already owned by a
parallel `hotfix/emailbrand-phpstan-2026-07-25` branch. They are **not yours to
fix**; do not touch `app/Mail/`.

The gate is **no NEW failures**, not green. Any failure outside those 12, and
any failure in `tests/Feature/Platforms` at all, is a real regression.

Do not pipe the output — piping masks the exit code. `YoutubeTest` is a known
flake; re-run it alone before treating it as a real failure.

- [ ] **Step 2: Run the static-analysis gate**

```bash
vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: no new errors. The baseline has `reportUnmatchedIgnoredErrors` on by default, so a *correct* change can fail the build by resolving a baselined error — if that happens, remove the now-unmatched baseline entry rather than reverting the fix.

- [ ] **Step 3: Report**

Report the actual command output for both. Do not claim completion without it.

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| `integration_connected` category, null mailable | 1 |
| Retention 30 days | 1 |
| `IntegrationNotifier` with both guards internal | 1 |
| Registry label with `Str::headline` fallback | 1 |
| Dedupe key = connection UUID | 1 |
| Emit point (a), trait, `wasRecentlyCreated` | 2 |
| Emit point (b), job, locked write | 3 |
| Emit point (b), job, 304 path | 3 |
| Test: sync connect notifies | 2 |
| Test: deferred pending → none, success → one | 3 |
| Test: terminal failure → none | 3 |
| Test: link/event → none | 1 (direct), 2 (via HTTP) |
| Test: seeder-created → none | 2 |
| Test: guards tested at the notifier directly | 1 |
| Test: no email ever queued | 1 |
| Test: deferred organiser connect still notifies | **gap — see below** |

**Gap accepted:** spec test 7 (a deferred events-organiser connect *does* notify) is covered in principle by Task 3's success test, which proves the job's success path notifies for an account row. A dedicated `EventsController::add` test would need that endpoint's full catalog stubbing for no additional guard strength — the guard being verified is "account rows are not swallowed", and Task 3 verifies exactly that. Flagged rather than silently dropped; add it if the reviewer disagrees.

**Placeholder scan:** none. Task 3 Step 1 contains a documented instruction to copy existing stubbing rather than invent it — a pointer to a real file, not a "figure it out".

**Type consistency:** `connected(IntegrationConnection): void` is used identically in Tasks 1, 2, and 3. Category string, retention key, and dedupe prefix are `integration_connected` throughout. `$notifier` is the parameter name in both job emit points.
