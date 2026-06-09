# Platform connection authorization + Form Request extraction (CONS-10 + CONS-35)

**Date:** 2026-06-09
**Branch:** `audit-fix/pilot-scraping-2026-06-08` → merge into `development`
**Audit source:** `audits/phase-pilot-scraping/audit-2026-06-06-pilot-scraping-CONSOLIDATED.md` (#CONS-10 P2/L, #CONS-35 P3)

## Problem

Every platform-connection access enforces ownership *only* by scoping the query
through `$user->integrationConnections()`. The registered `IntegrationConnectionPolicy`
is **never invoked**, so its `denyIfPendingDeletion()` rule is dead code — a
professional whose account is pending deletion can still write/delete platform
connections via the dashboard. Validation is also done inline (`$request->validate([...])`)
across all 8 controllers, against the project's Form-Request mandate (CONS-35).

`AppleController` compounds the issue: it bypasses the shared
`ManagesIntegrationConnection` trait entirely with its own private
`read()/put()/forgetOne()/withLock()` because one controller serves two platforms
(`apple_music` + `apple_podcast`, each stored as `platform = resource_id = <string>`).

## Goals

1. **CONS-10** — every read/write/delete of an `IntegrationConnection` runs through
   the policy gate, so `denyIfPendingDeletion` + the 404-not-403 ownership contract
   actually execute.
2. **CONS-35** — input validation moves out of controllers into Form Request classes.
3. Eliminate the duplicate Apple access path (foundation: one gated chokepoint).

## Architecture

Two clean layers, each with one responsibility (no redundancy):

| Concern | Home | Detail |
|---|---|---|
| **Validation** | Form Request per write action | `rules()` holds the existing rules verbatim; `authorize()` returns `true` (authz is not its job) |
| **Authorization** | The trait chokepoint | `authorizeForUser` inside the 3 trait methods |
| **Apple's 2 platforms** | Apple adopts the trait | dynamic `platform()`; private helpers deleted |

### Why authz at the trait, not in `authorize()` (divergence from the audit's note)

The audit's CONS-35 note suggests `Form Request authorize()` as the home for the
`authorizeForUser` calls. We deliberately put authz at the **trait chokepoint** instead:

- It also covers **read** paths and any **future non-HTTP writer** (jobs, commands),
  which `authorize()` cannot.
- It closes the exact risk the audit named under CONS-10 — *"a future controller that
  fetches an `IntegrationConnection` by UUID directly would bypass both scoping and the
  policy gate."* A Form Request only protects routes that use that specific request.
- It keeps the separation clean: Form Request = "is the input well-formed?",
  Policy (via the trait) = "may this actor touch this resource?".

`authorizeForUser` resolves at runtime to the using controller's method
(`Controller` uses `AuthorizesRequests`) — confirmed available on all platform
controllers and on Apple after migration.

### Authz placement in the trait

```php
// connectionFor(): gate reads — but only a found row; null stays null (preserves
// the "not found" contract, no throw on absent rows).
$connection = $user->integrationConnections()->where(...)->first();
if ($connection) {
    $this->authorizeForUser($user, 'view', $connection);
}
return $connection;

// writeConnection(): decide create vs update BEFORE the upsert, so the right
// ability (both run denyIfPendingDeletion) gates the write.
$existing = $this->connectionFor($user, $resourceId);     // 'view' passes (owner)
if ($existing) {
    $this->authorizeForUser($user, 'update', $existing);
} else {
    $skeleton = new IntegrationConnection([
        'user_id' => $user->id,
        'platform' => $this->platform(),
        'resource_id' => $resourceId ?? $this->defaultResourceId(),
    ]);
    $this->authorizeForUser($user, 'create', $skeleton);
}
return IntegrationConnection::updateOrCreate(...);

// forgetConnection(): gate the delete.
$connection = $this->connectionFor($user, $resourceId);   // 'view' passes (owner)
if ($connection) {
    $this->authorizeForUser($user, 'delete', $connection);
    $connection->delete();
}
```

Notes:
- `view` has no pending-deletion gate (pure ownership) → reads keep working while an
  account is pending deletion; only writes/deletes get the 423. This is correct.
- The redundant `view`-then-`update` on writes is harmless (both check ownership;
  only the write ability adds the deletion gate).

### Direct-write paths the trait does NOT cover (verified)

`InstagramController::connect` writes a *pending placeholder* via `IntegrationConnection::updateOrCreate(...)`
**directly** (not via `writeConnection`, because the placeholder shape differs:
`is_active=false`, `last_refresh_status='pending'`, `payload=null`). The trait gate
won't fire here. Add an explicit gate before that direct write:

```php
$existing = $this->connectionFor($user);            // 'view' passes for the owner
if ($existing) {
    $this->authorizeForUser($user, 'update', $existing);
} else {
    $skeleton = new IntegrationConnection([
        'user_id' => $user->id,
        'platform' => $this->platform(),
        'resource_id' => $this->defaultResourceId(),
    ]);
    $this->authorizeForUser($user, 'create', $skeleton);
}
// …then the existing IntegrationConnection::updateOrCreate placeholder write
```

This closes the pending-deletion gap on the Instagram connect *initiation*; the
follow-on `InstagramConnectJob` only completes an already-authorized connect.

**Confirmed out of scope:** `InstagramConnectJob` and `PlatformRefresher` write the
model directly and are system-initiated (the job continues a user-authorized connect;
the refresher is a cron). They are not user-initiated dashboard writes, so they are not
part of CONS-10. (A future "skip pending-deletion accounts in the refresh cron" is a
separate concern — note it, don't build it.)

### Verified: integration routes lack the pending-deletion middleware

`routes/api/integrations.php` registers all platform routes with
`['user.api', 'throttle:authenticated']` — it OMITS `EnforcePendingDeletionReadOnly`
(unlike the main `routes/api/user.php` group). So today pending-deletion users genuinely
*can* write platform connections, and the policy gate is the sole fix. A useful side
effect: because no middleware short-circuits first, the HTTP tests below truly exercise
the policy, not the middleware.

### Apple trait migration

Apple implements the trait's `abstract platform()` via a per-action property:

```php
use ManagesIntegrationConnection;

private string $activePlatform = self::MUSIC;   // set first in every generic op

protected function platform(): string
{
    return $this->activePlatform;
}
```

Each generic operation sets `$this->activePlatform = $cfg['platform']` as its first
line, then calls `writeConnection()/connectionFor()/forgetConnection()/withConnectionLock()`.
Apple's private `read()/put()/forgetOne()/withLock()` are deleted. Safe because Laravel
resolves a fresh controller instance per request (no cross-request state bleed); the
contract is documented with a comment, and every op sets the property before any trait
call.

Apple's storage shape is preserved: trait keys on `(platform(), resource_id ?? platform())`
→ `(apple_music, apple_music)` / `(apple_podcast, apple_podcast)`, identical to today.
The per-platform lock key (`platforms:{platform()}:lock:{user}`) already separates
music from podcast — no `$suffix` needed.

**Bare `forget()` clears both platforms** (`forgetOne(MUSIC)` + `forgetOne(PODCAST)`),
i.e. one request touching two platforms. Migrate it with an explicit loop so the gate
runs per row:

```php
public function forget(Request $request): JsonResponse
{
    $user = $this->currentUser($request);
    foreach ([self::MUSIC, self::PODCAST] as $platform) {
        $this->activePlatform = $platform;
        $this->forgetConnection($user);   // 'delete' authz per platform
    }
    return $this->success(['music' => null, 'podcast' => null]);
}
```

Keep the `abstract platform()` contract intact for the 7 single-platform controllers —
do NOT widen the trait's method signatures to serve Apple. The dynamic `platform()` +
`activePlatform` keeps the outlier contained (CLAUDE.md minimal-blast-radius).

## Form Requests (CONS-35)

One Form Request per **validating write action**; rules copied verbatim. Read/forget
actions with no input need no Form Request. Namespace: `App\Http\Requests\Platforms\`.

| Controller | Action | Request class | Rules (verbatim from current code) |
|---|---|---|---|
| Facebook | connect | `ConnectFacebookRequest` | `username` |
| TikTok | connect | `ConnectTiktokRequest` | `username` |
| Eventbrite | connect | `ConnectEventbriteRequest` | `url` |
| YouTube | connect | `ConnectYoutubeRequest` | channel input |
| YouTube | highlights | `SaveYoutubeHighlightsRequest` | ids array |
| Instagram | saveSelection | `SaveInstagramSelectionRequest` | selection ids |
| Fresha | connect | `ConnectFreshaRequest` | url |
| Fresha | saveSelection | `SaveFreshaSelectionRequest` | selection |
| Fresha | employeeServices | `FreshaEmployeeServicesRequest` | employee |
| Fresha | setServiceVisibility | `SetFreshaServiceVisibilityRequest` | visibility |
| Shopify | addBrand | `AddShopifyBrandRequest` | brand fields |
| Shopify | updateBrand | `UpdateShopifyBrandRequest` | brand fields |
| Shopify | setProducts | `SetShopifyProductsRequest` | product ids |
| Apple | connect (music) | `ConnectAppleMusicRequest` | `artist` |
| Apple | connect (podcast) | `ConnectApplePodcastRequest` | `show` |
| Apple | highlights (music) | `SaveAppleMusicHighlightsRequest` | `albumIds[]` |
| Apple | highlights (podcast) | `SaveApplePodcastHighlightsRequest` | `episodeIds[]` |

**Apple wrinkle:** Apple's `connectFor()`/`highlightsFor()` are generic over a `$cfg`
whose field name differs by platform (`artist`/`show`, `albumIds`/`episodeIds`).
Because music and podcast already have distinct routes, give each its own Form Request
(fixed `rules()`), and have the public action methods (`connectMusic`/`connectPodcast`,
`musicHighlights`/`podcastHighlights`) type-hint the matching request. The generic
helpers then receive already-validated input.

> Note (Apple highlights): the validated array key still differs by platform; the
> generic helper reads it via `$cfg['idsField']`, so the Form Request just guarantees
> the field is present/well-formed. Keep the helper's `$validated[$cfg['idsField']]`
> read, sourcing from `$request->validated()`.

## Testing (Pest)

New feature tests (`tests/Feature/Platforms/`), reusing existing platform-test setup:

1. **Pending-deletion gate (the CONS-10 core):** a pending-deletion user gets **423**
   on connect/save/forget for a representative trait controller (e.g. Eventbrite or
   YouTube) **and** for Apple (both music and podcast, since it's the migrated path).
2. **Reads survive pending deletion:** the same user can still GET their selection (200).
3. **Happy path unchanged:** an active owner can connect/read/save/forget normally.
4. **Cross-user isolation:** user B cannot read/write user A's connection (404, via the
   policy's `denyAsNotFound`) — confirm the gate, not just scoping, now drives this.
5. **Form Request validation:** invalid input still 422s through each new request
   (smoke-test a representative subset, not all 17).

Run the full suite in the **main checkout** (not a worktree — feature tests break
there per project notes): `composer test`.

## Out of scope / non-goals

- No DB migration, no RLS (that's CONS-13, parked separately).
- No change to the public read endpoint / Resource (that's CONS-11).
- No behavioral change to scraping, refresh jobs, or payload shape.
- Don't reshape the trait's method signatures for the 7 single-platform controllers —
  the Apple migration is achieved via the dynamic `platform()`, leaving the trait's
  clean contract intact.

## Risks

- **Largest blast radius is Apple** (deleting 4 helpers, rewiring 5 generic ops). Both
  Apple platforms must be covered by tests.
- **Existing platform tests** must keep passing — the authz gate must not 404/423 the
  happy paths (owner, active account).
- 17 new small classes is a lot of surface; rules must be copied **verbatim** to avoid
  silently tightening/loosening validation.
