# Feature Availability Enforcement (non-integration site features) — Design

**Date:** 2026-07-18
**Status:** Approved design, pending implementation plan
**Author:** Josh (+ Claude)
**Sibling spec:** `2026-07-17-platform-availability-enforcement-design.md` (this
extends the same `FeatureAvailability` system to non-integration surfaces).

## 1. Problem

Staff can already author availability rules in `core.feature_availability` —
global or segment-scoped, `enabled`/`disabled` — via `PUT
/staff/feature-availability`. The key convention documented on the service
already reserves `feature.<name>` for non-integration surfaces
(`FeatureAvailability.php` docblock), and the request validator already accepts
those keys.

What's **missing** is any runtime consumer of a `feature.<name>` key. Today the
*only* place any availability key is read is `IntegrationsMetaController`
(`integration.<platform>`, advisory greying of dashboard cards). No
`feature.<name>` key is enforced anywhere. So a staff "disable the enquiry form"
rule is inert — the public submit endpoint still accepts submissions.

This spec adds enforcement for three anonymous-visitor public features so those
rules actually do something.

### The critical nuance vs the integration spec

The platform kill-switch gates the **authenticated owner's** connect endpoints
and filters **`is_active` connection rows** out of the public payload. Neither
mechanism transfers cleanly:

- **These features are submitted by ANONYMOUS visitors** on the public sitepage,
  not by the authenticated owner. `FeatureAvailability::for()` takes the site
  **owner** (a `User`), so each public submit endpoint must resolve the owner
  from the request (subdomain → published `Site` → `Site->user`) and gate on
  that owner.
- **Public endpoints return 422 + an `error` code, not 503 and not 404.** 503 is
  the *authenticated* convention the integration spec used. 404 is reserved in
  these controllers for "the site genuinely doesn't resolve." The codebase's
  applied convention for "this public request can't be processed for a stated
  reason" is **422 with a machine-readable `error` key** —
  `PublicEnquiryController` already returns 422 "This site is not accepting
  enquiries" for the analogous form-off case, and `PublicReportController` carries
  a documented "422 (not 404) on public endpoints" decision. Since v1 does not
  hide the surface, the form is visibly rendered, so 404 hides nothing while 422 +
  `error: FEATURE_UNAVAILABLE` lets the frontend message it inline.
- **Features have no `is_active` connection rows.** There is nothing to bulk-flip
  and no takedown job to copy. "Disabled" in v1 means **block the submit
  endpoint**; hiding the rendered surface from the public payload is a deliberate
  follow-up (see §7 and §8).

## 2. Goals / Non-goals

**Goals**
- A global `disabled` rule for a v1 feature key blocks its public submit endpoint
  for every site.
- A segment-scoped `disabled` rule does the same, limited to sites whose owner is
  in that segment.
- The site **owner** can see, in their own dashboard read, that a feature is
  staff-disabled ("why is my form off?").
- Zero data loss, zero migration, and clean automatic re-enable.

**Non-goals (explicitly out of scope for v1)**
- **No public-payload surface-hiding.** The `contact` / `newsletter` form still
  renders on the public sitepage; a submit against a disabled feature 422s. See
  §8 for why hiding is deferred (it is inherently edge-cache-coupled) and §7 for
  the follow-up.
- **No takedown job / no data mutation.** Enforcement is read-only at request
  time. Nothing flips `is_active`, nothing is soft-deleted. This is *why* there
  is no reactivation problem and no schema change.
- **No staff meta endpoint** (`GET /features/meta` analog of `/platforms/meta`).
  Staff toggle via the existing `PUT /staff/feature-availability`. Deferred to a
  follow-up if the staff dashboard needs to enumerate keys.
- **No services / booking CTA feature.** Click-through only (no submit endpoint);
  enforcing it would be surface-hide-only. Deferred.
- **No new segment criteria.** Consumes `SegmentResolver` output unchanged.

## 3. Design overview

Three additive pieces, no migration:

1. **Feature registry** — a typed `PublicFeature` backed enum that is the single
   source of truth for the enforceable `feature.<name>` keys + labels.
2. **Submit gate** — a shared controller concern `GatesPublicFeature`, one method,
   called after each public submit controller resolves its site. Returns 422 +
   `error: FEATURE_UNAVAILABLE` when the resolved owner has the feature disabled.
3. **Owner-facing surfacing** — a `feature_availability` map on `SiteResource`,
   emitted only on the owner's own `GET /site` / `PATCH /site`.

## 4. v1 feature set

| Feature | Enum case → key | Submit endpoint | Controller | Public surface (NOT hidden in v1) |
|---|---|---|---|---|
| Enquiry / contact form | `PublicFeature::Enquiries` → `feature.enquiries` | `POST /public/enquiry` | `PublicEnquiryController::submit` | `contact` section block |
| Email / newsletter signup | `PublicFeature::EmailSignup` → `feature.email_signup` | `POST /public/subscribe` | `PublicEmailSubscriptionController::subscribe` | `newsletter` section block |
| Customer-lead capture | `PublicFeature::CustomerLeads` → `feature.customer_leads` | `POST /public/customers` | `PublicCustomerLeadController::store` | *none* (theme-side form) |

Customer-lead capture has no rendered payload surface at all, so it is
submit-block-only by nature — there would be nothing to hide even if v1 hid
surfaces.

## 5. Component 1 — Feature registry (typed enum)

A backed enum `App\Enums\PublicFeature` (matching the codebase's enum convention —
`AccountType`, `SitepageId`, `EnquiryStatus` all live in `app/Enums/`):

```php
// The enforceable non-integration public features. The backing value is the
// '<name>' part of the availability key 'feature.<name>'. Single source of truth
// for the submit gate and the owner-surfacing loop. Adding a feature = one case
// + one gate call in the new endpoint.
enum PublicFeature: string
{
    case Enquiries     = 'enquiries';
    case EmailSignup   = 'email_signup';
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
            self::Enquiries     => 'Enquiry form',
            self::EmailSignup   => 'Email signup',
            self::CustomerLeads => 'Customer lead capture',
        };
    }
}
```

**Why an enum, not a config map.** A closed set of feature identities referenced
in code (not runtime-configurable) is exactly what a backed enum is for. It gives
type-safe references (`PublicFeature::Enquiries`) so a **typo can't silently
fail-open** — the single biggest risk of a stringly-typed gate, since an unmatched
key resolves to "available." `PublicFeature::cases()` drives the owner-surfacing
loop; `label()` centralises display names. Staff CRUD stays permissive (accepts
any regex-valid `feature.<name>`); the enum governs only which keys code
*enforces* — a superset/subset relationship identical to how integrations treat
`PlatformRegistry` vs the free-form CRUD.

## 6. Component 2 — Submit gate (the enforcement)

A shared controller concern used by the three public submit controllers:

```php
// App\Http\Controllers\Concerns\GatesPublicFeature (used by ApiController subclasses,
// so $this->error() — the shared JSON error shape — is available).
protected function assertPublicFeatureAvailable(?Site $site, PublicFeature $feature): void
{
    // resolvePublishedSite() already guarantees an active owner, but stay
    // fail-open on a null owner rather than 500 on a data-integrity edge.
    $owner = $site?->user;
    if ($owner && ! FeatureAvailability::for($owner)->allows($feature->availabilityKey())) {
        // 422 + machine-readable error (matches PublicEnquiryController's existing
        // "not accepting enquiries" and PublicReportController's "422 not 404 on
        // public endpoints"). Throw (not return) so the helper halts the controller;
        // HttpResponseException reuses the controller's $this->error() JSON body.
        throw new HttpResponseException(
            $this->error('This feature is currently unavailable.', 422, [], ['error' => 'FEATURE_UNAVAILABLE'])
        );
    }
}
```

The `PublicFeature` type on the parameter means callers pass
`PublicFeature::Enquiries`, not a raw string — a bad key is a compile-time error,
not a silent fail-open. (Exact halt mechanic is an implementation detail; the plan
settles the final form. The `$this->error(..., $headers, $extra)` signature above
mirrors `PublicReportController`'s existing 422 + `error`-key call.)

Called immediately after each controller resolves its `$site`:

- **`PublicEnquiryController::submit`** — after `$site` is resolved (~line 71),
  **before** the existing `contact`-block check. Both return 422, so ordering the
  gate first makes the staff-disable signal (`error: FEATURE_UNAVAILABLE`) win
  over the "no contact block" message rather than the two racing.
- **`PublicEmailSubscriptionController::subscribe`** — after `$site` is resolved
  (~line 80).
- **`PublicCustomerLeadController::store`** — after `$pro = $site->user` is
  resolved (~line 85).

**Why a concern, not a route middleware.** The integration spec could use a
route middleware because `LoadCurrentUser` sets a `professional` request
attribute *before* it runs. These public routes have no such attribute — the
owner is resolved *inside* each controller (subdomain → `PublicSiteResolver` →
`$site->user`). A middleware would have to re-run that resolution; a concern
called after the controller already has `$site` reuses the work and is precise.

**Key properties**
- **Fail-open.** `UserFeatureAvailability::allows()` returns `true` when no rule
  applies (absence = available) and the guard skips a null owner, so the 422
  fires only on an explicit `disabled` rule.
- **Segments are free.** `FeatureAvailability::for($owner)` already resolves
  segment rules per user (segment beats global, deny wins). A segment-scoped
  `feature.enquiries` disable gates exactly the submitters whose owner is in that
  segment — no extra code.
- **Owner load cost.** `$site->user` is one lazy-load on a POST submit path (not
  the cached GET sitepage); acceptable — the request already writes DB rows.
- **Cache freshness.** Staff CRUD calls `FeatureAvailability::flush()` on write,
  so the gate sees a new rule within the 60s snapshot TTL (immediately after a
  flush).

## 7. Component 3 — Owner-facing surfacing

Add a `feature_availability` map to `SiteResource`, emitted only on the owner's
own site read via a new opt-in fluent method (mirroring the existing
`withRationale()` so staff/self/visibility responses that don't need it skip the
read):

```php
// SiteResource — opt-in, set by UserSiteController::show/update with the owner.
// Resolve the per-user snapshot ONCE (not per key — ::for() hits the cache each call).
$availability = FeatureAvailability::for($this->owner);
// ...
$this->withFeatureAvailability
    ? ['feature_availability' => collect(PublicFeature::cases())
        ->mapWithKeys(fn (PublicFeature $f) => [
            $f->value => $availability->allows($f->availabilityKey()),
        ])->all()]
    : [],
```

Response shape:

```json
"feature_availability": { "enquiries": true, "email_signup": false, "customer_leads": true }
```

Wired in `UserSiteController::show()` and `update()`:
`(new SiteResource($site))->withRationale()->withFeatureAvailability($professional)`.

**No edge-cache coupling.** The owner (`$professional`) *is* the site owner, so
this is one already-cached (`FeatureAvailability::for`, 60s) snapshot on an
authenticated, per-user, non-edge-cached read. The dashboard uses it to show
"disabled by Partna" on the relevant block editor. This is the cheap place to
surface availability — unlike the public payload (§8).

## 8. Why public-payload surface-hiding is deferred

Hiding the `contact` / `newsletter` surface on the **public** sitepage is
inherently edge-cache-coupled, for *any* mechanism:

- The public sitepage is Cloudflare-edge-cached (the router Worker does
  `caches.default.put`). A staff availability change touches no block, so nothing
  busts the edge today.
- Therefore surface-hiding necessarily adds an "iterate affected sites → purge
  edge cache" job — the exact cost the integration spec called out (§2 there:
  "hot-path cost + edge-cache invalidation") when it refused read-time payload
  enforcement.
- Both hiding mechanisms considered carry that job **plus** a downside:
  - *Read-time filter + purge-only job* — no data mutation, clean re-enable, but
    threads availability into the builder + view-read path and adds the purge job.
  - *Block-`is_active`-flip takedown* — reuses the existing filter/cache-bust but
    **mutates the user's own authored block** and doesn't auto re-enable (worse
    than the integration case, where the flipped row is a staff-managed
    connection, not user content).

The **submit gate** (§6) has none of this cost — POSTs aren't edge-cached — and
is the security-critical, data-ingestion-stopping layer. v1 ships that; the
surface-hider is a well-scoped follow-up (§10).

## 9. Re-enable semantics & what does NOT change

Enforcement is **read-only** at submit-time and owner-read-time. Nothing is
mutated or baked into a long-lived cache, so re-enable (setting a rule to
`enabled` or deleting it) is automatic and immediate — the staff CRUD's existing
`FeatureAvailability::flush()` bumps the snapshot version and the next request
sees the change. There is no takedown, no reactivation gap, and no need for a
schema column to distinguish "staff-disabled" from "user-disabled".

**Unchanged:** no migration; the public payload and `site.public_site_payload`
view; the Cloudflare edge cache; `FeatureAvailability` read logic;
`SegmentResolver`; and the staff availability CRUD (it already accepts
`feature.<name>` keys and flushes on write).

## 10. Testing (Pest, Feature)

For each of the three endpoints (`/public/enquiry`, `/public/subscribe`,
`/public/customers`):

- **Disabled → 422 + `FEATURE_UNAVAILABLE`.** A global `disabled` rule for the
  feature key makes the submit return 422 with the `error` code and asserts **no
  row was created and no job dispatched** (`Bus::fake` / `Queue::fake` — e.g.
  `DispatchEnquiryNotificationsJob`, `SendSubscriptionConfirmationJob`).
- **Enabled / no rule → 200.** Absence of a rule (and an explicit `enabled` rule)
  lets the submit through (absence = available).
- **Segment scoping.** With a segment-scoped `disabled` rule: 422 for a submitter
  whose owner is in the segment; 200 for one whose owner is not.

Plus:

- **Precedence:** `PublicEnquiryController` with `feature.enquiries` disabled
  returns 422 with `error: FEATURE_UNAVAILABLE` even when a live `contact` block
  exists (the feature gate's signal wins over the block's "not accepting" message).
- **Owner surfacing:** `GET /site` includes `feature_availability` reflecting the
  active rules (false when disabled, true otherwise); the map is absent on
  responses that don't opt in.
- **Re-enable:** after deleting the rule + flush, the submit succeeds again with
  no other action (no reactivation needed).

**Postgres vs SQLite caveat (CLAUDE.md):** `FeatureAvailability::resolveOverrides`
fails open (returns `[]`) when the `core.feature_availability` table is absent, so
the SQLite mirror must seed the table for the disabled-path tests to exercise a
real rule rather than the fail-open branch.

## 11. Follow-ups (not this spec)

- **Public-payload surface-hiding** (§8) — read-time filter + purge-only job, or
  block-flip takedown; ships the "form disappears when disabled" UX.
- **Staff `GET /features/meta`** — analog of `/platforms/meta` so the staff
  dashboard can enumerate toggleable feature keys + current availability instead
  of hardcoding key strings.
- **Services / booking CTA** feature (surface-hide-only; no submit endpoint).
- **Gating any future non-`/public` write path** that could ingest one of these
  features' data.

## 12. Rollout / verification

- Ship behind the normal branch → `development` deploy (serves both API domains).
- No migration to apply.
- Verify against real Postgres (not just the SQLite mirror): with a `disabled`
  rule live, confirm a real `/public/enquiry` submit returns 422 (`error:
  FEATURE_UNAVAILABLE`) and writes no `Enquiry` row, and that `GET /site` reflects
  the disabled state.
