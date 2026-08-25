# DEFERRED — PART 2 unit 12 · `#TEST-20` (single-slot family has no lock and no DB backstop)

**Finding:** `#TEST-20` · P2, `audits/sweeps/2026-08-24-unified-actions-delta/CONSOLIDATED.md:1164`
**DEFER trigger:** §1.2 **trigger 3** — the plan needs a **lock reorder in a different class**.
**Written:** 2026-08-26, branch `audit-fix/pre-launch-hardening-2026-08-25`. No code was written. The
source checkbox is left **unticked**.

`EXECUTE-PART-2.md` §4 unit 12 set the rule this deferral follows verbatim:

> Plan the lock ordering explicitly and write it down FIRST. If you cannot demonstrate on paper that
> the resulting order is acyclic — **or if the plan needs a second lock, a lock reorder, or a change to
> `applyFinding()` — DEFER it (trigger 3) with that plan attached.** A deadlock introduced overnight in
> the auto-routing path costs far more than a duplicate button.

The ordering arm needs exactly that lock reorder. **The reservations arm is provably safe and is
shovel-ready** (§5) — it is deferred only because it is half of one finding and the other half is not.

---

## 1. Severity — do not overclaim

Harm today is a **duplicate button on one sitepage, self-healable by disconnecting one of the two
connections.** This is hardening, not data loss. Nothing corrupts.

---

## 2. The lock graph

Three lock nodes. Note `withPlatformSeedLock` and `withConnectionLock` share
`CacheKeyGenerator::platformConnectionLock`, so they are **one node** — but it is parameterised by
platform, which is itself part of the problem.

| # | Edge | Evidence |
|---|---|---|
| E1 | `bookingXorLock → reservationsXorLock` | `BuildsAutoSyncFindings.php:274-280`, `applyFinding()`'s cross-family branch |
| E2 | `bookingXorLock → platformConnectionLock(p)` | `FreshaController.php:149→160`, `:258→263`, `:536→545` |
| E3 | **`platformConnectionLock('online-ordering') → whatever `LinkRouter` takes`** | `GoogleBusinessAutoSync.php:545` opens `withPlatformSeedLock($userId, self::ORDERING_FAMILY)` and **`:602` calls `$this->linkRouter->routeOrdering(...)` from inside that closure** |

Taken **alone**, with no outbound edge: `reservationsXorLock` (`GoogleBusinessAutoSync::seedReservation`
`:198`; `applyFinding` `:301`) · `bookingXorLock` (`LinkRouter::seedBooking` `:304`, `SquareController:53`,
`FreshaController:800`, `FreshaConnectFetch:271`) · `platformConnectionLock` (~20 call sites).

### Verdict per arm

**Reservations — PROVEN ACYCLIC.** Wrapping `LinkRouter::seedReservation()` in `withReservationsXorLock`
adds no outbound edge (the body is DB-only, §4) and no inbound edge (no caller holds a lock, §3). It
simply becomes another "taken alone" holder beside `GoogleBusinessAutoSync::seedReservation`.

**Ordering — DEGENERATE, not merely cyclic. This is the blocker.**

- The lock that would actually fix the race is `platformConnectionLock('online-ordering')` — the key the
  sibling writer `GoogleBusinessAutoSync::seedOrdering` already takes. Serialising against it is the
  entire point.
- **But that is the exact key already held** at `GoogleBusinessAutoSync.php:545` when it calls into
  `LinkRouter` at `:602`. `Cache::lock` is **not reentrant**, so a second `->block(3, …)` on the same key
  self-deadlocks, times out after `SEED_LOCK_BLOCK = 3s`, logs, and returns `$default`. **Every Google
  ordering seed would silently degrade to the contended path.** The repo already knows this failure
  mode: `tests/Feature/Platforms/DeferredConnectSelfDeadlockTest.php`, and `AutoSyncSeederLockTest.php`'s
  header warning that "holding the lock across the dispatch would self-deadlock".
- **The per-brand alternative does not fix the bug.** `withPlatformSeedLock($userId, $surface)` (e.g.
  `uber_eats.order`) avoids the self-deadlock, but the two racing writers would then hold *different*
  keys and exclude nothing. It also introduces `platformConnectionLock(A) → platformConnectionLock(B)`
  nesting, which is only safe under a total order on surface strings — a discipline that does not exist
  in this codebase.

### A standing repo rule this would break

`app/Http/Controllers/Api/Routing/SuggestionsController.php:327-341`:

> "`applyFinding` stays OUTSIDE the platform lock. The load-bearing reason is ORDERING: it takes its own
> booking/reservations XOR lock internally, and this call fully releasing first is what keeps that
> ordering acyclic (§9.4 of the U1 plan)."

The codebase deliberately has **no `platformConnectionLock → reservationsXorLock` edge**. The Google
ordering path would be the first one.

---

## 3. Inbound call graph of the two seeders

Only three call sites reach `LinkRouter` in all of `app/`:

| Entry | Reaches | Lock held at entry? |
|---|---|---|
| `InstagramAutoSync.php:158` | `routeUnsafe → routeClassified:143/148` → both seeders | **None.** `InstagramConnectionSeeder.php:220` runs `autoSync->seed()` *before* its `Cache::lock` at `:284-286` — the file's own PWL-7 comment says so. |
| `CustomLinkSeeder.php:59` | same | **None.** `writeCard()`'s `platformConnectionLock('custom')` (`:165`) is on the *fallback* path only, after `route()` returns. |
| `GoogleBusinessAutoSync.php:602` | `seedOnlineOrdering` **only** | **YES — `platformConnectionLock('online-ordering')`, held since `:545`.** The blocker. |

**Re-entrancy:** `LinkRouter::$routing` is set in `route()` (`:57-62`) but **`routeOrdering()` does not set
it** — the Google path bypasses the in-process reentrancy marker entirely. No path takes
`reservationsXorLock` twice today: `applyFinding`'s reservations branch calls `runApply` (DB-only plus
`applyFindingHandled → dispatchInstagram`), and the `apply.instagram`-with-slot-recipe combination is
structurally excluded by the escape hatch at `BuildsAutoSyncFindings.php:240-250`.

---

## 4. What the seeder bodies call inside the proposed span

Both are **DB-only** — no vendor HTTP, no dispatch: the family/store read, `CardPayload::fromArray()` and
`sameUrl()` (pure), `SourceReconciler::recordCapBlock` → `IriCanonicalizer::canonicalize` (pure) +
`upsertIntent` (a DB upsert, no lock), `wasDisconnected()` (`BuildsAutoSyncFindings.php:810`, one
`exists()`), and `write()` (`:116`, one `updateOrCreate`).

So the "never hold a lock across a vendor call" constraint is **satisfied**. That is NOT why this is
deferred.

---

## 5. 12a — the reservations arm, ready to implement

Proven acyclic (§2). Effort **S**. Deferred only because it is half of one finding.

Wrap from the `$family = IntegrationConnection::query()…` read through the `write()` in
`LinkRouter::seedReservation()` (`:425-489`) with
`$this->withReservationsXorLock($userId, fn () => …, $default)`.

**`$default` on lock timeout must be `RouteResult::custom(handled: true)`** — not an unhandled
`custom()`, because the owner ruling of 2026-08-19 retired auto-publishing an unasked-for card for this
family; and not a silent `null`. `runUnderSeedLock` already emits
`platforms.auto_sync.reservations_lock_timeout` stamped `source=App\Services\Platforms\LinkRouter`,
which is the refusal pin this repo requires on a concurrency path.

**Caller impact, stated:** `handled: true` means `CustomLinkSeeder::seed` returns `null` and
`InstagramAutoSync` files no card — the link is dropped-with-a-log for that run, and the next scrape
re-routes it.

**Tests.** Mirror `tests/Feature/Platforms/AutoSyncSeederLockTest.php:68-92`: pre-acquire
`"platforms:reservations-xor:lock:{$user->id}"`, route an OpenTable URL through `InstagramAutoSync`,
assert zero rows written plus the warning. Mutation-prove by removing the wrap. Add a second test in the
`:94` style using `DB::listen` to prove the lock spans **both** the family read and the write, not just
the write.

**The Feature lane CAN prove this.** These are `Cache::lock` on `CACHE_STORE=array` (`phpunit.xml:42`),
NOT Postgres advisory locks — `AutoSyncSeederLockTest.php:72-92` already proves contention by
pre-acquiring the real key. No `tests/Postgres/` work is needed. (The EXECUTE file's worry that "SQLite
cannot exercise a Postgres advisory lock" does not apply here.)

---

## 6. 12b — the ordering arm, genuinely blocked

Needs owner sign-off on one of:

**(a) Narrow `GoogleBusinessAutoSync::seedOrdering`'s `withPlatformSeedLock`** so it is *released across*
the `routeOrdering` call and re-taken for the Google-card `write()` at `:637`, pushing the lock down into
`LinkRouter::seedOnlineOrdering` keyed `'online-ordering'`. Preserves the single serialisation key and
creates no new edge — but it re-opens the read-then-write span that GB's `$existingOrdering` /
`$existingStoreKeys` eager-load depends on, and it invalidates the three ordering assertions in
`AutoSyncSeederLockTest.php:138-184` plus `$ran`'s `MenuFetchJob`-suppression semantics. **L, standalone.**

**(b) Accept the exposure** on the IG-vs-GB cross-harvest race and close `#TEST-20`'s ordering half
WONTFIX with this analysis attached.

**Recommendation: (b) before launch, (a) after.** The harm is a duplicate button on one sitepage,
self-healed by disconnecting one; the fix touches the lock scope of the auto-routing hot path.

---

## 7. Independent check of the DB-backstop claim — the finding is CORRECT

`supabase/migrations/20260727110005_connections_idx_unique_active.sql:7-9`:

```sql
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "idx_platform_connections_unique_active"
    ON "site"."platform_connections" ("user_id", "surface_key", "resource_id")
    WHERE ("deleted_at" IS NULL);
```

(The baseline's `(user_id, platform, resource_id)` version at `20260726000000_baseline_pilot.sql:3215`
was implicitly dropped by `…110004`'s `DROP COLUMN platform`.)

- **Reservations:** `resourceId = brandResourceId($platform)` and the surface differs per brand, so
  OpenTable and Resy are `(u, opentable.reserve, opentable)` and `(u, resy.reserve, resy)`. Both insert.
  **Not backstopped** — confirming the finding's core claim and its distinction from `#TEST-19`.
  **Nuance the finding omits:** a *same-brand* race **is** backstopped — one side gets `23505`, caught by
  `route()`'s try/catch (`:205-209`) → `report()` + `custom()`. So the exposure is strictly **cross-brand**.
- **Ordering:** `resourceId = 'order-'.sha1(strtolower($url))`, so two different store URLs on the *same*
  brand get different `resource_id` under the same surface → both insert, breaking the "ONE store per
  ordering brand" owner ruling. **Not backstopped even same-brand.**
- The other indexes do not help: `…_canonical` keys on `canonical_key`; `…_primary_per_class` is partial
  on `is_primary`, which nothing sets on these paths.

---

## 8. Corrections to the brief and the finding — record these, the paraphrases will be reused

1. **`EXECUTE-PART-2.md` §4 unit 12 names the wrong file.** It describes the unlocked seeders as being in
   `BuildsAutoSyncFindings`. They are in **`LinkRouter`**, which *uses* that trait (`LinkRouter.php:32`),
   so the lock helper is already in scope. The probe in §2 (`withReservationsXorLock` at ~:481) points at
   where the helper is *defined*, not where the fix goes.
2. **"`LinkRouter` has no `Cache::lock` anywhere in the file" is FALSE.** `LinkRouter.php:304` already
   takes `withBookingXorLock`. A grep for `Cache::lock|withReservations|withPlatformSeed` misses it
   because it goes through the trait helper under a third name. **`LinkRouter` is already a participant in
   the lock graph** — which is precisely why its `seedBooking` arm is fine and these two are not.
3. **`ReservationsController` no longer exists.** Both `BuildsAutoSyncFindings.php:268` and
   `ManagesIntegrationConnection.php:555` still name it as a `reservationsXorLock` holder; those comments
   are stale. It was deleted with the pseudo-platform lane on 2026-08-19 (recorded at `LinkRouter.php:428`).
   There is no `OnlineOrderingController` either. Today the only holders are
   `GoogleBusinessAutoSync::seedReservation` and `applyFinding`.
4. **The finding calls the two arms "the identical pattern". They are not.** Reservations is a
   family-wide (`routing_class`) XOR taken by nobody in the call chain. Ordering is per-brand *and* sits
   inside an already-held lock of the very key it needs. Treating them as one fix is what makes this unit
   look S when it is not.

---

## 9. What I did NOT do

- No code written, no lock added, no test added.
- Source checkbox at `unified-actions-delta/CONSOLIDATED.md:1164` left **`- [ ]` unticked** on purpose.
- Did not implement 12a despite proving it safe — it is half a finding, and `EXECUTE-PART-2.md` §4 set an
  explicit low bar for DEFER on this unit. Shipping half a concurrency change unattended, against that
  instruction, is not a call to make without Josh.
