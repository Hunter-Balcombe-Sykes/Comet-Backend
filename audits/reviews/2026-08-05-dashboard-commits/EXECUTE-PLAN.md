# Execute plan — `2026-08-05-dashboard-commits`

Pre-implementation plan for sign-off, per `scripts/audit/fix-flow.md` §1a.
**Nothing has been implemented.** Every premise below was verified against the code on 2026-08-05.

- **Branch:** `audit-fix/dashboard-commits-2026-08-05` off `origin/development` (`d9426d31`)
- **Worktree:** `../backend-wt/dashboard-commits-2026-08-05` — the main checkout has 12 uncommitted
  files on `development` that must not ride along.
- **Models** (from the audit's `## Execution policy`): Plan = Opus, Implement = Sonnet,
  Review = Sonnet (separate instance).

## Collision check — clear

`backend-wt/redis-request-breaker-2026-08-05` (live, another session) touches
`QueuedIngestor.php`, `bootstrap/app.php`, `bootstrap/providers.php`, `config/queue.php`,
`scripts/audit/audit.sh`, `app/Services/Redis/`, `app/Http/Middleware/Context/`.
**Zero overlap** with this plan's file set. No file is contended.

---

## Premise verification — all 11 findings CONFIRMED against code

| Finding | Verified how |
|---|---|
| #RANK-1 | `CustomLinksController:257` reads `$linkRanks[$row->resource_id]`; `ContentFreshness:81` writes `$linkItems[$url]`. `DevInsightsController:203-204` calls them "per-URL connection rows". `CardPayload::str()` (`:84-87`) returns the raw value — `$card->url()` is byte-identical to `payload['url']`. Fix is exact. |
| #DEPLOY-1 | Matches known state: prod re-baselined 2026-07-26, dev ~49 migrations ahead. `usesPushToDeploy: true` on prod confirmed in CLAUDE.md. |
| #SILENT-1 | `BuildsAutoSyncFindings:170-178` returns bare `true` on capability denial. Both callers (`GoogleBusinessController:296`, `InstagramController:261`) treat `true` as proceed and flip the finding to `seeded`. |
| #TEST-1 | `createTenant()` writes `account_type => 'partna'` (`tests/Pest.php:1167`); `AccountCapabilities:67-69` returns unconditional `true` for partna. Denial branches unreachable suite-wide. |
| #TEST-5 | `StravaAndCustomLinksContractTest:136,165` + `ShopPayloadFeatureTest:87` all assert `'popularityRank' => null`. |
| #LOG-1 | `MediaUploadService:219-224` throws with no log; sibling `:371-387` logs first. |
| #DRIFT-1 | `SuggestionApplier:39-45` and `PlacementPolicy::capabilityDenial():127-136` are byte-identical match arms. Comment admits it. |
| #API-1 | `grep design-media docs/api.md` → **zero hits**. Routes exist at `routes/api/user.php:379-381`. |
| #PERF-1/2, #CFG-1, #SLOP-1 | Confirmed at cited lines. `POOL_KEYS` has exactly 9 entries beside `'max:9'`. |

---

## Units

### Unit 1 — `rank-keying` (P1: #RANK-1 + #TEST-5) · **no gate** · S

**Change:** `CustomLinksController.php:257` → `$linkRanks[(string) $card->url()] ?? null`, and correct
the misleading comment at `:238` ("keyed by the link's resource_id" is the bug, stated as fact).

**Tests:** seed a real `analytics.content_popularity_scores` row (`content_type='link_item'`,
`content_key` = the link's URL) and assert a **non-null** rank at
`StravaAndCustomLinksContractTest:136,165`. `ShopPayloadFeatureTest:87` is the *shop* half, which is
already correctly keyed by `handle` — leave its `null` case but add a seeded non-null sibling so the
dashboard shop route is covered too.

**Risk:** the three existing `=> null` assertions **will fail** until rewritten. That's the proof they
were vacuous. Must land in one commit.

**Not in scope:** the same shape exists on the *public* Links engine
(`IndividualProfilePayloadBuilder:141` ranks `site.blocks` ids against `ranks['block']`, a
`content_type` nothing writes). Pre-existing, not caused by these commits, flagged by the audit as a
bonus. Recommend a separate finding rather than widening this unit.

### Unit 2 — `silent-paths` (P2: #SILENT-1 + #LOG-1) · ⚠️ **BLOCKER — authorization** · S

**#SILENT-1 — recommended fix: throw, don't sentinel.**

`BuildsAutoSyncFindings:170-178` should `throw new AuthorizationException('booking is not available
for this account')` instead of returning `true`.

Why this over the audit's "distinguishable sentinel":
- `Handler::render()` runs `prepareException()` **before** `renderViaCallbacks()`
  (`vendor/laravel/framework/.../Handler.php:616-620`), so a bare `AuthorizationException` arrives at
  `bootstrap/app.php:186-193` as `AccessDeniedHttpException` → **403 + `Log::warning('Access denied')`**
  for free. No new logging code, no new enum.
- **Zero controller changes.** The throw propagates from a call site that sits *outside* both
  controllers' lock closures and outside `InstagramController`'s `try` (which catches only
  `LockTimeoutException`), so nothing swallows it and the finding is never flipped to `seeded`.
- It makes this path **identical to its twin** in `SuggestionApplier:52` — which is also what
  Unit 3 is about.

**Wire-contract change (needs your call):** the dashboard currently gets **200 + a silently vanished
item**; after this it gets **403 + a message**. That is the point of the fix, but it is a visible
behaviour change on `POST /api/platforms/google-business/apply-sync` and the Instagram equivalent.

**Alternative if you'd rather not move the status code:** keep `return true`, add
`Log::warning` + `report()` at the block point. Observable to engineers, still silently wrong for the
user. I do not recommend it.

**Blast radius:** `BuildsAutoSyncFindings.php` only. One direct-call test exists
(`BookingXorConnectRaceTest:461`) — it uses a partna account, so the new branch can't fire there;
verify, don't assume.

**#LOG-1:** add `Log::warning('Singleton upload lost a concurrent-replace race (conditional claim)',
['site_id' => $site->id, 'purpose' => $purpose])` before the throw at `MediaUploadService:224`,
matching `:371-387`. Trivial, no gate of its own.

### Unit 3 — `capability-drift` (P2: #DRIFT-1) · ⚠️ **BLOCKER — authorization** · S

Extract the duplicated arms into one caller-agnostic method. Proposed shape: a small
`App\Routing\RoutingCapabilityGate::denialFor(User $user, string $routingClass): ?string` holding the
three arms; `PlacementPolicy::capabilityDenial()` and `SuggestionApplier::apply()` both delegate.
Keeps `AccountCapabilities` as the only capability reader (CLAUDE.md law) and gives drift one home.

**Plus a parity test** asserting both surfaces produce the same verdict for every `routing_class`
(`booking`, `reservations`, `ordering`, and an unknown class → null) across partna / business-food /
business-non-food. The test is the durable guard; the extraction just removes the opportunity.

**Behaviour must not change** — the two copies are currently identical, so this is a pure refactor.
Reviewer should diff verdicts before/after, not just read the code.

### Unit 4 — `missing-tests` (P2: #TEST-1) · no gate · M · **run after Units 2 and 3**

Four regression tests, one per shipped fix:
1. `DesignSingletonMediaTest` — the mid-store soft-delete → 409 (`MediaUploadService:219-224`). The
   existing `DesignSingletonMediaConcurrencyTest` exercises a *different*, INSERT-time collision.
2. `SuggestionsInboxTest` — `SuggestionApplier`'s capability denial → 403 + intent flipped to
   `blocked`/`gate`. **No test file exists for `SuggestionApplier` at all today.**
3. New/extended `GoogleBusinessController` coverage — `BuildsAutoSyncFindings`' denial (asserting
   whatever Unit 2 lands on).
4. `CustomLinksControllerTest` — reorder touches `site.updated_at` (`:65-88` asserts response order
   only).

**Fixture recipe (this is the whole trap):** `createTenant($h, ['account_type' => 'business',
'sector' => …])`.
- booking denial → **food** sector (`'restaurant'`, `SectorTaxonomy::FOOD_SECTORS:34-36`)
- reservations / ordering denial → **non-food or null** sector
Call `AccountCapabilities::flushCache()` if the account is mutated mid-test — it's `WeakMap`-memoised.

### Unit 5 — `docs` (P2: #API-1) · no gate · S

`docs/api.md` only. Document `GET`/`POST /api/design-media` and
`DELETE /api/design-media/{purpose}` (`routes/api/user.php:379-381`), including the 404 (unknown or
empty slot) and the POST's 409 conflict contract.

### Unit 6 — `p3-tail` (P3: #PERF-1 + #PERF-2 + #CFG-1 + #SLOP-1) · no gate · S

Mechanical, in files Units 1–5 already open:
- **#PERF-1** pass `$rows` into `removeSingleton()`.
- **#PERF-2** `$user->site?->id` at `ShopController:1012` and `CustomLinksController:243-244`
  (`UserCacheService::getByAuthId()` eager-loads `site`; the relation *method* bypasses it).
- **#CFG-1** `'max:'.count(self::POOL_KEYS)`. The draft's "derive from `SitepageId`" suggestion is
  **wrong** — that's a different 15-case taxonomy. `max:16`/`max:26` in the same file already sit
  beside their consts, so this matches local convention.
- **#SLOP-1** reunite the orphaned docblock with `LEGACY_BUTTON_REF_TO_ACTION_ID`.

---

## Standalone — #DEPLOY-1 (P1) · ⚠️ **BLOCKER — prod DB, irreversible**

**Not an audit-fix unit and must not be worked on this branch.** It is: apply ~49 outstanding
`supabase/migrations/*.sql` (from `20260727110000_connections_surface_key.sql` forward) to prod
`edplucmvkcnokyygxqsb` per `docs/deploy/routine-deploy.md`, **before** the next
`git push origin development:production`.

Standing constraints that make this genuinely dangerous:
- Prod has `usesPushToDeploy: true` — **the push IS the deploy**, no CI gate, no approval step.
- Supabase org is on the **Free** plan — no PITR, no managed backups. The `partna-db-backup` R2 dump
  is the only rollback.
- Code-then-migration ordering produces `42703`/`42P01` on live public endpoints.

Recommend running it as its own deploy session with your explicit go-ahead, sequenced immediately
before the prod push — not folded into this audit branch.

---

## Coverage gaps carried forward (stated, not silently dropped)

- `frontend-backend-contract` / `cross-repo-dead-code` **were not run** — no frontend checkout on
  this machine. `POOL_KEYS` explicitly mirrors `Partna-App/components/blocks/site-page.tsx` and that
  mirror stays **unverified** after #CFG-1.
- **`LIFE-1`** (read-then-create race in `SuggestionApplier`, drafted P2 @ 0.75) was never
  adjudicated. Out of scope here; verify before acting on it.
- `scripts/audit/system-prompt.md` is **stale** (says `account_type` is `'individual'`,
  `architecture_id` CHECK is `'one'`). Pipeline defect, unrelated to these commits, still needs
  fixing + a grep of `lenses/`.
- **Unranked custom-link clicks:** whether the theme tags a custom-link click with the URL (matching
  `content_key`) or with the card id is **unverifiable from the backend**. #RANK-1's fix is correct
  against the scorer's own contract either way, but real engagement ranks only populate if the
  frontend sends the URL. Worth a frontend check when a checkout is available.
