# Instagram Thin-Scrape Detection

**Date:** 2026-08-11
**Status:** Design approved, implementation pending
**Origin:** F4 investigation, 2026-08-11 — `crucibletattooco` pre-account build captured zero posts and reported success identically to two builds in the same wave that captured 12 posts each.
**Scope:** The Instagram scrape path only — `InstagramScraper` and its two consumers (pre-account build, connect/refresh). Google Business, Fresha, and menu scrapes are **out of scope**.

---

## 1. Problem

On 2026-08-10 at 10:22:26 UTC a signup build for `@crucibletattooco` (public, 30k followers,
4,164 posts) captured **no posts at all**. The build finished `build_state='ready'`,
`failure_code=null` — byte-identical to the builds for `@simondoylehair` and `@jess.hair.stylist`
90 seconds either side of it, which each captured 12 posts.

### 1.1 It was a transient upstream fault, and it does not reproduce

Re-running the same actor against the same account on 2026-08-11 returns a complete profile:

| Field | Stored (10 Aug 10:22 UTC) | Live re-probe (11 Aug) |
|---|---|---|
| `followersCount` | 30,042 | 30,041 |
| `postsCount` | **null** | **4,164** |
| `latestPosts` | **0** | **12** |
| `businessCategoryName` | "None" | "None" |
| `private` | — | false |

The account is public and readable. Apify returned a degraded document for that one run.

The actor swap (`b6d518916`, "swap to the apify actor") is **ruled out**: it deployed to dev at
09:59:22 UTC, 23 minutes *before* the scrape. All three accounts in that wave ran on
`apify~instagram-profile-scraper`.

### 1.2 The failure is narrower than "a thin result"

Full name, follower count, profile picture and business category all landed. Exactly two fields
failed: `postsCount` and `latestPosts`. Those are the *count* and the *edges* of the same node in
Instagram's profile document (`edge_owner_to_timeline_media`), whereas `followersCount` comes from
a sibling node. One missing node explains both symptoms while every header field survives.

This is inference from the observed field pattern — the actor's internals are not visible to us —
but it is what the detection predicate in §3 is shaped around, and it is why `postsCount` and
`latestPosts` are treated as **one** signal rather than two independent ones.

### 1.3 `businessCategory: "None"` is not a symptom

`"None"` is this account's normal value: today's fully-successful scrape returns it, and so do
`natgeo`, `amiconirestaurant`, and `hungryjacksau` ("None,Fast food restaurant"), all with complete
data. It is Instagram's literal category string for an account with no subvertical, passed through.
It must not appear in the detection predicate.

### 1.4 Nothing noticed, and nothing retried

- `InstagramScraper::fetchProfileResult()` (`app/Services/Platforms/InstagramScraper.php:108-119`)
  returns `ok($items[0])` for any 2xx item lacking an `error` **string**. A missing timeline node
  carries no `error` key, so it is a clean success.
- `InstagramSourceGenerator::generate()`
  (`app/Services/PreAccount/Generators/InstagramSourceGenerator.php:52-60`) gates only on
  `$result->profile === null`.
- `latestMedia()` already computes the evidence and writes
  `_mediaDiagnostics: {posts: 0, videos: 0, pickedPhoto: false, pickedVideo: false}` into the
  payload, then nothing reads it.

Because it is classified a success, there is no retry — and the evidence says a retry would very
likely have succeeded.

### 1.5 The same fault on the refresh path destroys data

`InstagramConnectJob` serves both the dashboard connect **and** the ~12h refresh sweep, and calls
the same `InstagramConnectionSeeder::seed()`. Given a thin profile, `seed()`
(`app/Services/Platforms/InstagramConnectionSeeder.php:86-151`) rebuilds `$images` from scratch
(→ `[]`), then its stale-reclaim block computes the complement of "files written this run" and
**deletes `photo.jpg`, `reel.mp4`, and `reel-cover.jpg` from R2**.

So the identical upstream flake, landing on a refresh instead of a build, silently blanks the
payload *and* destroys the mirrored media of a live, claimed user's site. Same cause, worse blast
radius, equally unalarmed. This path is in scope.

### 1.6 Rate

Of 22 Instagram connections on dev carrying a username: 7 have zero images (32%), 3 have a null
`postsCount`, 1 carries the exact "followers present, `postsCount` absent" signature. This recurs
at roughly 5–15% of real scrapes; it is not a one-off.

---

## 2. Goals and non-goals

**Goals**

1. A zero-post capture must not be indistinguishable from a complete one.
2. A transient blip should self-heal without human intervention, at bounded cost.
3. A flaky refresh must never destroy a live user's payload or mirrored media.

**Non-goals**

- Making Apify reliable. This design assumes the upstream will keep doing this.
- Detecting *every* degraded capture. §3 is deliberately conservative; see §3.2.
- Changing the pre-account build's Apify spend model, or introducing `ApifyBudget` claims to a
  build path that does not currently make them.

---

## 3. The predicate

`InstagramScraper::isThinProfile(array $profile): bool`

```
thin  =  no `error` key
      && private !== true                            // private accounts legitimately show no posts
      && (  postsCount is null/absent                 // the observed crucibletattooco signature
         || (postsCount > 0 && latestPosts empty) )   // self-contradicting payload
```

"`latestPosts` empty" means absent, not an array, or an array of length zero — all three are the
same signal (§1.2) and must be handled identically. `postsCount` "null/absent" likewise covers a
non-numeric value.

### 3.1 What is deliberately NOT thin

`postsCount === 0` together with an empty `latestPosts` is **self-consistent** and indistinguishable
from a genuinely empty new account. It is not flagged.

This is why `maha.restaurant` (0 followers / 0 posts, on two separate rows) stays unflagged. It is
plausibly the same fault, but flagging it requires a guess that the data does not support, and a
false positive blocks a real signup. `roberthuntercuts` (3 followers, `postsCount: 1`, 1 post) is
correctly unflagged by the `postsCount > 0 && latestPosts empty` clause not firing.

### 3.2 Conservative by design

The predicate errs toward missing a degraded capture rather than flagging a sparse-but-real
account. A false negative costs one thin site; a false positive tells a real prospect their build
is broken.

---

## 4. Retry

One immediate in-scraper retry, on the thin predicate only.

- Gated on `ApifyBudget::tryClaim('instagram')` before the second run. `InstagramScraper` does not
  currently claim budget — the claim happens at the controller layer
  (`InstagramController.php:381`, `RefreshController.php:183`) — so without this gate the retry
  would spend a paid actor run outside the daily cap. `MenuApifyScraper.php:172` is the precedent
  for claiming inside a scraper.
- If the claim is **denied**, skip the retry and report thin. Budget exhaustion must never turn
  into an unbounded spend.
- Exactly one extra run maximum, on the ~5–15% of scrapes that come back thin.

`GeneratePreAccountSiteJob`'s "never re-bill the scrape" guarantee (`maxExceptions=1`,
`failOnTimeout=true`, `tries=0`) is **untouched** — that guarantee governs *job* retries, whereas
this is one bounded call inside a single job run.

---

## 5. Consumer behaviour

The two existing entry points already encode the two behaviours this design needs, so the split
falls out of which method a caller uses.

| Entry point | Method | On thin |
|---|---|---|
| Pre-account build | `fetchProfileResult()` | `ok($profile, thin: true)` — seed anyway, flag the build |
| Connect / refresh | `fetchProfile()` | **null** — existing loud-failure path, `seed()` never runs |

`ProfileFetchResult` gains a `public bool $thin` (default `false`), set only on the `ok()` path.
`fetchProfile()` returns `null` when the result is thin.

### 5.1 Build path — accept, but flag

There is no prior data to protect, and the site must render something. `InstagramSourceGenerator`
reads `$result->thin`, seeds normally, and marks the build suspect. `build_state` stays `'ready'`.

**Marker:** a new nullable column `core.pre_account_builds.thin_scrape_at timestamptz`.

Reusing `failure_code` was rejected: it is documented as meaningful only when
`build_state='failed'` (`app/Models/Core/User/PreAccountBuild.php:23`), and both consumers
(`UserStaffResource.php:77`, `PreAccountBuildStatusResource.php:40`) pass it straight to the wire,
so a `ready` build carrying a failure code would read as broken in the staff UI.

One raw-SQL migration in `supabase/migrations/`. No CHECK constraint, no index — `build_state`
carries the only CHECK on this table and is not being extended.

### 5.2 Connect / refresh path — reject, preserve

No new branch in `InstagramConnectJob`. `fetchProfile()` returning null routes into the existing
handler at `InstagramConnectJob.php:127-137`, which fails the job loudly (Horizon failure →
Nightwatch) and returns **without calling `seed()`**. Because the destructive stale-reclaim lives
inside `seed()`, not running it is what preserves both the payload and the R2 objects.

`markFailed()` is refined to record `last_refresh_error='thin_scrape'` rather than the generic
`'job_failed'`, so the case is queryable and distinguishable from real breakage. The connection is
left `last_refresh_status='unavailable'`; the next sweep retries it.

**Accepted trade-off:** an Instagram account that genuinely deletes all its posts keeps showing its
previous media until a non-thin scrape succeeds. A stale-but-correct site beats a freshly-emptied
one.

**Accepted trade-off:** a *first-time* dashboard connect that comes back thin is rejected rather
than seeded thin. The user sees "unavailable" and can retry, which is the better outcome than a
silently empty connection.

---

## 6. Observability

`Log::warning('instagram.thin_profile', [...])` carrying:

- `username_hash` — `hash('sha256', mb_strtolower($username))`, following the existing convention at
  `InstagramScraper.php:69`. Never the raw handle alongside `user_id`.
- `user_id`, `postsCount`, `followersCount`, `latestPosts` count
- `retried` (bool) and `recovered` (bool) — so the retry's hit rate is measurable from logs rather
  than guessed.

The refresh path's Horizon job failure already reaches Nightwatch. No new alerting is added.

---

## 7. Testing

Pest feature tests, faking `Http` per case.

**Predicate**

| Case | Fixture | Expected |
|---|---|---|
| The observed fault | followers present, `postsCount` absent, `latestPosts` `[]` | thin |
| Genuinely empty account | `postsCount: 0`, `latestPosts: []` | **not** thin |
| Sparse but real | `postsCount: 1`, one post | **not** thin |
| Private account | `private: true`, no posts | **not** thin |
| Self-contradicting | `postsCount: 4164`, `latestPosts: []` | thin |
| Healthy | `postsCount: 365`, 12 posts | **not** thin |

**Retry**

- Thin then healthy → clean success, `thin` false, no flag, exactly 2 HTTP calls.
- Thin twice → `thin` true, exactly 2 HTTP calls (never 3).
- `ApifyBudget` claim denied → exactly 1 HTTP call, reported thin.

**Build path**

- Retry still thin → `build_state='ready'`, `thin_scrape_at` set, site renders.
- Healthy → `thin_scrape_at` null.

**Refresh path (the data-loss guard — the most important test here)**

- Thin refresh of a populated connection → `seed()` never invoked; payload unchanged; **assert on
  the R2 disk that `photo.jpg` / `reel.mp4` / `reel-cover.jpg` still exist**. Asserting only on the
  payload would pass while the media was being deleted.
- `last_refresh_status='unavailable'`, `last_refresh_error='thin_scrape'`.

**Schema**

Tests run SQLite, production is Postgres (CLAUDE.md). The new column must be added to the SQLite
test schema and the model `$casts` must agree with the Postgres DDL (`timestamptz` → `datetime`
cast). Verify the migration DDL against `supabase/migrations/` rather than relying on a green
suite.

---

## 8. Files touched

| File | Change |
|---|---|
| `app/Services/Platforms/InstagramScraper.php` | `isThinProfile()`, retry + budget claim, thin logging, `fetchProfile()` null-on-thin |
| `app/Services/Platforms/ProfileFetchResult.php` | `public bool $thin` on `ok()` |
| `app/Services/PreAccount/Generators/InstagramSourceGenerator.php` | read `$result->thin`, set `thin_scrape_at` |
| `app/Models/Core/User/PreAccountBuild.php` | `thin_scrape_at` cast + docblock; not fillable (SEC-4 convention) |
| `app/Jobs/Platforms/InstagramConnectJob.php` | `markFailed()` records `'thin_scrape'` |
| `supabase/migrations/<ts>_pre_account_builds_thin_scrape.sql` | `ADD COLUMN thin_scrape_at timestamptz NULL` |
| `tests/Feature/Platforms/…` | per §7 |

---

## 9. Rejected alternatives

**Standalone `ThinProfileDetector` service.** Explicit, but it is a cross-cutting guard with two
call sites that must each remember to call it. House convention
(`feedback_crosscutting_guard_placement`) puts such guards at the choke point.

**Gate inside `seed()`.** Wrong layer — the seeder is the writer, and the build path deliberately
wants to write a thin payload.

**Mark the build `failed`.** Strongest signal, but a genuinely sparse account gets told its build
failed, and staff/ManyChat builds would stop publishing.

**Write on refresh but suppress the R2 delete.** Leaves a blanked payload plus orphaned R2 objects
for the GC command to reclaim — worse than both alternatives.

**Log/alert only, no DB state.** Nothing queryable afterwards, and no basis for an automatic re-run.
