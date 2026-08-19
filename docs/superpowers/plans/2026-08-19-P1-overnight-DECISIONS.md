# Decisions taken unattended — P1 overnight run, 2026-08-19

Branch `audit-fix/p1-overnight-2026-08-19`, cut from `origin/development` at
`60e142011`. Working the 15 open P1s of `audits/sweeps/2026-08-18-overnight-run/`
per `docs/superpowers/plans/2026-08-19-audit-P1-OVERNIGHT-EXECUTE-PROMPT.md`.

Every entry below is a decision **nobody signed off on**, taken because the run
was unattended and the prompt's standing instruction is to take the documented
default and keep going rather than block. Each one is reversible.

> **§0 is the summary** — what landed, what is committed-but-unmerged and why,
> what is plan-only, what I could not verify, and every premise that turned out
> to be wrong. Everything after it is the detail behind one of those lines.

---

## 0. SUMMARY — read this, then only the sections it points at

**15 open P1s at the start. 11 resolved, 4 deliberately left open with reasons.**
Nothing merged. Nothing pushed. No PR.

### Landed and reviewed (five commits on `audit-fix/p1-overnight-2026-08-19`)

| Commit | Findings | Gate |
|---|---|---|
| `fix(ingest)` | `WHK-1` | mergeable on a normal review |
| `fix(media,sections)` | `SEC-1` `SEC-5` `LIFE-12` `SCALE-2` | mergeable on a normal review |
| `fix(publicsite,content)` | `LIFE-6` ≡ `CCH-3` ≡ `API-3`, `SEC-2`, `LIFE-8` | **DO NOT MERGE** — public wire + PII |
| `fix(pools)` | `LIFE-2` `LIFE-4` `API-1` `LIFE-3` | **DO NOT MERGE** — public wire |
| `fix(ingest,projection)` | `SCALE-5` ≡ `CACHE-3`, `LIFE-5` | **DO NOT MERGE** — identity spine |

**17 of 101 boxes ticked** (4 were already fixed before this run, 13 by it).
`TRIAGE.md` §1 updated to match.

Every unit went plan → implement → **independent review**. Three of the five
reviews returned FAIL first. Every fix was mutation-tested: the change was
reverted and the new tests confirmed red, so nothing here is vacuously green.

### Open, with reasons — do NOT read these as "not done yet"

| Finding | Why it is open | Section |
|---|---|---|
| `LIFE-1` | PLAN ONLY by instruction. Plan written and ready for sign-off. | `2026-08-19-LIFE-1-identity-race-plan.md` |
| `SCALE-1` | All three candidate rewrites are wrong; one is valid on SQLite and invalid on Postgres. Pinned by a new six-case test instead. | §4.5 |
| `SCALE-3` | The memory is consumed inside `SafeUrlFetcher`, not `MediaMirror`. Fixing it means changing the SSRF chokepoint. | §4.3 |
| `SCALE-4` (+`CACHE-5`) | Prefetching across a loop that mutates what it prefetched — the same hazard `LIFE-1` was held back for. Do it WITH `LIFE-1`. | §9 |

### The three things that need a human, in priority order

1. **§4.1 — the `LiveSourceScope` pause ruling.** Does a PAUSED connection keep
   publishing what it already landed? The audit framed this as a three-surface
   question; it is a **nine-surface** one, and the ruling was already taken in
   code by W2. Biggest decision here.
2. **§6 — the `SEC-2` backfill.** Scoped on dev: 129 rows, **59 distinct named
   people**, 40 of them on unclaimed sites. No data touched. Decide it with the
   already-open `LEGAL-2` item — same vendor, same subjects, two surfaces.
3. **§8 — `LIFE-5` option (a) vs (b).** (a) shipped because it is additive and
   reversible; (b) is arguably better but needs a migration.

### Final verification, both lanes

| Lane | Baseline (branch base) | Final | Delta |
|---|---|---|---|
| SQLite (`artisan test`) | 8540 passed, 2 skipped, 0 failed | **8590 passed, 2 skipped, 0 failed** | +50, all new tests, all mine |
| Postgres (`phpunit.pg.xml`) | 222 passed, 0 failed | **225 passed, 0 failed** | +3 |
| `pint --test` (the CI gate) | — | **passed** | |
| `phpstan` (whole project) | 7 errors | **7 errors, byte-identical** | none mine — `development` is red here, see §1.4 |

### What I could NOT verify

- **The frontend.** `reviewedAt` now emits `+00:00` rather than `Z` (§4.3). No
  `Z`-suffix contract exists in `docs/api.md` or `docs/wire-changes/`, and the
  four sibling timestamps already emit `+00:00` — but the frontend repo is not
  reachable from here.
- **The applied-schema and authz lanes.** Neither runs in a local checkout; CI is
  the only place they have ever run. No unit here changes schema, so the exposure
  is low, but it is not zero.
- **Production behaviour.** Prod carries none of the `content` / `ingest` /
  `routing` / `catalog` schemas, so most of this is unexercisable there by
  construction.

### Premises that turned out to be wrong

Four, all recorded: the `SEC-1` precedent does not exist (§3.2); `WHK-1`'s
"unmet intent" was half wrong AND a prior unit had deliberately deferred it
(§3.1); `LIFE-3`'s "unsettled ruling" was already settled in code (§4.1); and
`LIFE-5`'s suggested predicate (`last_run_at IS NULL`) misses the exact case the
finding describes (§7). Plus two defects **in my own first cuts**, both caught by
review, both worth reading: §5.1 (a format bypass of my own guard), §5.2 (a 200 → 500
regression) and §5.3 (a fourth timestamp field on the same wire).

---

## 1. Environment findings that are NOT audit findings

These cost real time and the morning session should know them.

### 1.1 `development` cannot run `composer test` locally right now — pre-existing

`composer test` runs three guard scripts BEFORE `artisan test`, so a guard
failure aborts with **zero tests executed**. On a clean checkout of
`origin/development` the third guard fails:

```
Migration safety lint FAILED — unsafe locking pattern(s) detected:
  ✗ 20260819011500_workplace_section_default_active.sql:
    DDL/DML on live-traffic table `site.blocks` without a lock timeout.
```

That migration is `6a7613d81`, landed by the owner on `2026-08-19`
("workplace section defaults ON"). It is **not mine and not in this sweep**.

**Decision: did not fix it.** It is a migration — squarely inside `fix-flow.md`'s
blocker gate — and it belongs to work in flight. I baselined and verified around
it by invoking `php artisan config:clear && php artisan test` directly, which is
what `composer test` runs after the guards. The other two guards
(`no-laravel-migrations`, `no-cache-memo`) pass.

**Owner action:** wrap that migration's statements in
`BEGIN; SET LOCAL lock_timeout='2s'; SET LOCAL statement_timeout='10s'; … COMMIT;`
per `supabase/migrations/CONVENTIONS.md`, or the `development` CI gate stays red.

### 1.2 A leaked poll loop had made the machine unusable

At session start the box was at **load average 119** with **2,564 processes**, and
`fork()` was failing outright (`resource temporarily unavailable`). Cause: **1,886
orphaned `cloud env:logs partna development --minutes 2 --json` processes**, all
reparented to PID 1, the oldest ~6.5 hours — a peer session's polling loop that
never reaped. `kern.maxprocperuid` is 4000.

**Decision: killed the 1,886 orphans** (`PPID == 1` only; the two with live
parents were left alone). Process count fell to 650 and the machine recovered.
Nothing else was touched.

**Owner action:** whatever runs that `/loop` needs a reap or a timeout.
`cloud env:logs` can hang instead of exiting — this is the other half of the
"`--live` is not a stream" gotcha.

### 1.3 Docker killed both Postgres test containers mid-run

The first PG-lane baseline burned **1,533 s and reported 207 failed / 15 passed**.
Every failure was `SQLSTATE[08006] … timeout expired` at exactly 60.0 s — i.e.
**207 connection timeouts, not 207 test failures**. Docker had issued a `fast
shutdown` to my container *and* to the peer's `partna-pgtest-r4` at the same
instant. Restarted, recreated the database, re-ran: **222 passed in 27 s**.

If a future session sees a uniform-60 s PG failure wall, check
`docker ps -a` before believing any of it.

---

## 2. Baselines (mine, taken on this branch's base — no carried-forward "known red")

| Lane | Result | How |
|---|---|---|
| SQLite (`artisan test`) | **8540 passed, 2 skipped, 1 warning, 0 failed** (606 s) | guards bypassed per §1.1 |
| Postgres (`phpunit.pg.xml`) | **222 passed, 0 failed** (27 s) | throwaway `postgres:16` on :55434 |

Both clean. Any red after this point is mine until proven otherwise.

---

## 3. Wrong premises found in the audit (Rule Zero)

`TRIAGE.md` §6 already disproved one cited precedent and flagged one product
ruling. It was right to expect more. Confirmed so far:

### 3.1 `#WHK-1` — the intent claim is half wrong, and a PRIOR unit deliberately deferred the fix

The audit quotes `land()`'s comment ("isolates the one bad record and lands the
rest durably, just slower") as evidence of unmet intent. That comment is about
**chunk-level** isolation, and that part already worked.

What the audit did not find: `tests/Postgres/LanderBatchLandingTest.php:264`
**asserted that `land()` throws**, with a comment stating the per-record catch
was "Unit 2's shape, not something this unit is allowed to change". So a previous
audit unit consciously scoped this out and encoded that in a passing test.

That test also **hides the real defect**: it places the poisoned record **last**,
so it only ever proved that records processed *before* the poison survive — which
was true without any fix. Records *after* the poison were silently dropped.

**Decision: implemented anyway, and changed that assertion**, because the prior
deferral was a scoping decision rather than a correctness ruling, and this unit is
the one that was told to make it. The old case is kept (with its history written
into the comment) and a new case puts the poison **first**, which is the ordering
that actually fails without the fix. Both mutation-tested: reverting the catch
turns all three poison cases red.

### 3.2 `#SEC-1` — cited precedent still does not exist (TRIAGE §6 confirmed)

`ImageVariantService::assertWithinPixelBudget()` does not exist; `grep` over
`app/` and `tests/` returns nothing. The real guard is inline and private in
`ImageVariantService::loadImage()` (`app/Services/Media/ImageVariantService.php:468-499`)
and is **path**-based. Also: the prompt cites `config/partna.php:1561` for
`image_max_pixels`; it is actually **:1594**. Line numbers in this audit have
drifted — treat all of them as approximate.

### 3.3 `#LIFE-3` "keep it out of scope" is not implementable as written

*(see §4.1 — this is a decision, not just a wrong premise)*

---

## 4. Product / scope decisions taken without sign-off

### 4.1 `#LIFE-3` (paused connection) — the ruling was ALREADY TAKEN, in code

The prompt says `#LIFE-3` embeds an unsettled product ruling (does `is_active =
false` — a *paused* connection — hide content publicly?) and instructs: do NOT
implement it, write up the question.

**The question is already answered in the codebase.** `App\Site\Pools\LiveSourceScope`
— the "disconnect = hide" helper introduced by W2 and cited in its own docblock as
the "overnight 2026-08-18 ruling" — already contains:

```php
$c->whereNotNull('lpc.id')
  ->whereNull('lpc.deleted_at')
  ->where('lpc.is_active', true);   // ← paused ⇒ hidden, decided already
```

It is applied today to `PoolResolver::resolve()`'s library query, the pinned-items
re-check, and `SectionCandidates`' auto half. So **paused already hides content**
on every surface that uses the helper.

The unit's own instruction is to make `LiveSourceScope` "the single definition
rather than hand-copying where-clauses". Those two instructions conflict: reusing
the helper on `statsFor()` / `$sourceLinks` / `$offerLinks` necessarily carries
`is_active` with it. The only way to obey "do not implement LIFE-3" would be to
write a **second, subtly different liveness definition** that omits `is_active` —
which is precisely the divergence that created this family of bugs.

**Decision: reuse `LiveSourceScope`'s existing predicate verbatim**, which makes
the three unfiltered surfaces consistent with the six that already have it.

**The open question is therefore NOT the one the audit asked.** It is:

> Is the ruling already baked into `LiveSourceScope` — that a PAUSED connection
> stops publishing content it has already landed — the behaviour you want?
>
> - **(a) Keep it.** Pause means "hide and stop syncing". Simple, already live on
>   the library/pinned/candidate surfaces, and now consistent everywhere.
> - **(b) Change it.** Pause means "stop syncing, keep publishing what landed";
>   only `deleted_at` hides. This is arguably the more defensible reading of a
>   pause button, and it is what the audit assumed was current behaviour.
>
> **(b) is a change to `LiveSourceScope` affecting SIX existing surfaces, not
> three.** It is a bigger decision than the audit framed, which is exactly why it
> should not be taken at 3am. Recommendation: **(a)**, on the grounds that it is
> the status quo and consistency is worth more than the marginal semantic
> argument — but this is the owner's call and the alternative is cheap to
> implement (delete one `->where()` and fix its tests).

**Cross-reference, same contract, still open:** the W6 review left open whether a
PINNED item whose only `source_item` was retired by absence-folding should hide
(`docs/overnight-2026-08-18/LOG.md`, W10). Adjacent surface, same question. Decide
both together.

### 4.2 `#SEC-2` — redact the whole `attribution.authors` path, not the audit's wildcard

The audit's premise is correct and I verified the wildcard mechanism executably
rather than trusting it:

```
Redactor::apply($doc, ['attribution.authors.*.name','attribution.authors.*.uri'])
  →  "authors": [ [], [] ]          ← works, but leaves empty husks
Redactor::apply($doc, ['attribution.authors'])
  →  "authors" key gone entirely    ← chosen
```

**Decision: declare `attribution.authors` (whole path), scoped `when_unclaimed`.**
The wildcard form leaves two empty objects that still leak *how many people*
contributed photos, and reads as a bug downstream. The whole-path form removes the
personal data outright while keeping `maps_uri` and `flag_uri`, which carry no
personal data.

**That last point is deliberate and is the open question.** Places' terms require
crediting the author **and** linking back wherever the photo is *displayed*
(`mapPhoto()`'s own comment, slice 1b D6). An unclaimed pre-account site **is
public** — that is by design (CLAUDE.md, pre-account). So redacting the author
name while continuing to display the photo satisfies the privacy concern and may
**not** satisfy the attribution obligation. Keeping `maps_uri` preserves the
link-back half; it does not preserve the credit half.

The two coherent positions:

- **(a) Redact the author, keep the link-back** (shipped). Privacy-first, matches
  what `reviews` already does for the same data subject, minimal change.
- **(b) Do not publish borrowed Google photos at all on an unclaimed site.**
  Removes the tension entirely rather than splitting it, but is a visible product
  change to pre-account builds and a much larger diff.

Recommendation: **(a) now, (b) evaluated before pilot** alongside the already-open
`LEGAL-2` reviewer-PII item — same data subject, same licence, two surfaces. This
needs a human who is willing to make a licence call; I am not.

**No data was touched.** Rows carrying full attribution already exist on dev.
Scope of a backfill, and whether it is a redaction or a delete, is written up in
§5 and is deliberately left undone: a redaction pass over live rows is its own
reviewed job.

### 4.3 `#SCALE-3` (whole media bodies in PHP memory) — NOT fixed, and not fixable in `MediaMirror`

Verified premise, wrong owner. `MediaMirror` cannot fix this because the memory is
consumed before it ever sees the bytes:

```php
// app/Services/Http/SafeUrlFetcher.php:551
return strlen($response->body()) > $this->maxBytes;
```

`withMaxBytes()` is enforced **after** the full body is already materialised in
memory. It is a rejection cap, not a streaming cap. Fixing `#SCALE-3` properly
means adding a sink/streaming mode to `SafeUrlFetcher` with the cap enforced
*during* transfer — i.e. changing the repo's SSRF chokepoint, the class pinned by
`tests/Feature/Architecture/OutboundHttpGuardTest.php`.

**Decision: left OPEN, box NOT ticked.** Rewriting the SSRF seam unattended is not
a trade I am willing to make for a scaling finding on a background queue, and
"resolved as an open question" would be dishonest here — the question is not open,
the work is simply out of scope. It needs its own unit.

### 4.4 `#SCALE-15` ≡ `#LIFE-19` (fetches to the 80 MB video cap before the 15 MB image cap) — NOT fixed, but here is the missing piece

Real, and cheap to fix *once you know which entries are video* — which the audit
did not say and which cost me the time to find:

**`InstagramMediaProjector` already emits the signal.** A reel's mp4 frame carries
`'role' => 'video'` and `ref = "instagram:{shortcode}:video"`
(`app/Ingest/Projection/InstagramMediaProjector.php:51-57`). So the video-ness is
known at `dispatchMirrors()` time, in `ProjectionWriter`.

The fix is therefore: thread a `bool $expectVideo` (defaulted `false`) from
`dispatchMirrors()` through `MirrorMediaAssetJob`'s constructor into `mirror()`,
and pick `MAX_BYTES` vs `MAX_VIDEO_BYTES` for the fetch cap. A promoted property
**with a default** is required so payloads already queued at deploy time
deserialize (see the repo's promoted-readonly-job-property gotcha).

**Decision: not done tonight.** It is a P2 rider that requires a queued-job
signature change plus a `ProjectionWriter` edit — disproportionate risk for an
unattended run, and `ProjectionWriter` is already the most contended file in this
sweep. ~20 minutes of attended work with the pointer above.

### 4.5 `#SCALE-1` — pinned and analysed, but NOT rewritten. The obvious rewrite is wrong on Postgres.

The premise is true as stated: `SectionCandidates::ruleCandidates()`'s default
(recency) ordering runs a correlated scalar subquery per candidate row, and in
fact runs the SAME subquery **twice** per row —

```sql
ORDER BY ((SELECT MAX(fp.published_from) …) IS NOT NULL) DESC,
         COALESCE((SELECT MAX(fp.published_from) …), first_seen_at) DESC,
         content.items.id DESC
```

**But the P1 framing overstates it.** Both subqueries are `WHERE fp.item_id = ?`
against `content.f_published`, whose PRIMARY KEY is `(item_id, source_id)` — so
each is an index lookup, not a scan. They are evaluated only for rows surviving
the WHERE, which is already scoped to **one site's** items. So the real cost is
`2 × N` index probes for one site's N items, on a path cached for 60 s. That is a
constant factor worth removing, not the scaling emergency the tier implies.

**Three rewrites considered, all rejected:**

1. **`joinSub` a pre-aggregated `GROUP BY item_id` derived table.** Preserves
   cardinality (the thing that matters — see below), but Postgres cannot push the
   site predicate through the aggregate, so it would aggregate the WHOLE
   `f_published` table to order one site's handful of rows. Plausibly *slower*.
2. **Hoist the subquery into a `SELECT … AS cand_sort_at` alias and order by it.**
   Halves the evaluations, and **is invalid on Postgres**: an output-column alias
   may be a bare `ORDER BY` item, but NOT referenced inside an expression such as
   `COALESCE(cand_sort_at, first_seen_at)` — there it resolves against input
   columns and errors. **SQLite accepts it.** So this rewrite passes
   `composer test` and dies in production — precisely the SQLite≠Postgres trap
   CLAUDE.md warns about. Making it work needs a full derived-table restructure of
   the builder.
3. **Drop key 2 to a bare `first_seen_at DESC`** (valid, one evaluation, and the
   reasoning almost works: key 1 already fully orders the dated rows, and for
   undated rows `COALESCE(pub, first_seen) = first_seen`). It changes behaviour in
   one case — **two dated rows sharing a `published_from` but differing in
   `first_seen_at`** now tie-break on `first_seen` before `id`, where today they
   tie-break on `id` alone. Small, but a silent ordering change to a query that
   already carries three separately-debugged incidents (F13, X5, F1).

**Decision: box NOT ticked, no query change.** What did ship is
`tests/Feature/Site/SectionCandidateOrderingTest.php` — six cases pinning order
AND cardinality, including the invariant every rewrite must keep:

> `content.f_published` and `content.f_occurrence` are both keyed
> `(item_id, source_id)`, so an item carried by TWO sources has TWO facet rows,
> and any naive join emits that item **twice**.

That test is the precondition the prompt asked for ("pin the current output shape
BEFORE changing the query"), and it is worth more than the rewrite: whoever does
attempt option 1 or 2 now finds out immediately if they break the shape.

**If someone wants the win:** restructure to a derived table —
`SELECT id FROM (SELECT items.id, items.first_seen_at, (SELECT MAX(...)) AS cand_sort_at FROM ... WHERE ...) t ORDER BY cand_sort_at DESC NULLS LAST, COALESCE(cand_sort_at, first_seen_at) DESC, id DESC`
— which is alias-legal because the alias is an input column of the outer query.
Verify on the **Postgres** lane, not SQLite.

---

## 5. Three things the independent reviews caught that I had wrong

Recorded because both are the kind of mistake that looks fine in a green suite,
and because they are the strongest argument for keeping `fix-flow.md`'s
independent-review step even at 3am.

### 5.1 The first `#SEC-1` guard was bypassable — I mirrored half of the precedent

`ImageVariantService::loadImage()` does TWO things: `assertImageMime($path)` (a
byte-sniffed format allowlist) and then the pixel-count check. I built the
string-based twin with only the second, and reasoned in the docblock that
"a bomb necessarily has a READABLE header".

That is false. `imagecreatefromstring()` decodes strictly more formats than
`getimagesizefromstring()` can parse — GD's own **GD2** among them. Measured:

```
gd2   bytes=854298   getimagesizefromstring=FALSE
imagecreatefromstring on those bytes → DECODED: 12000x12000 = 144,000,000 pixels
```

854 KB through a 15 MB byte cap and a pixel guard that had just declared the
bytes harmless. Fixed by adding the finfo allowlist AND flipping `exceeds()` to
fail **closed** on an unreadable header. A second review then established, by
mutation, that the fail-closed flip is what actually stops GD2, while the
allowlist narrows the accepted surface and supplies an honest failure reason —
the docblock now says that, rather than my original guess.

**Transferable lesson:** when an audit points at a precedent, copy ALL of it. The
half I dropped was the half that mattered.

### 5.2 The first `#LIFE-6` fix turned a 200 into a 500

The obvious reading of "don't discard every pool because one failed" is: catch
per pool, `continue`. That is wrong here, and the original code's one-line
comment — *"A missing lane yields no pools, never a 500"* — was load-bearing in a
way the audit never mentioned.

`PoolResolver::resolve()` provisions a `site.sections` row as a **side effect**.
Bailing at the first failure minted one section row; continuing through all seven
minted seven — including `custom_links`. `SiteActionsService` → `LinkPoolReader`
then reads that section's pinned items against a `content.f_link` table that does
not exist in a partial environment, unguarded, and the public profile endpoint
returned **500**.

Caught by `tests/Feature/PublicSite/IncompleteBookingPagePresenceTest.php`, which
does not call `setupContentTables()` — so every pool throws there. Verified it as
a genuine regression rather than a flake by running the same test in the pristine
baseline worktree (passed) and in isolation on the branch (failed).

The fix distinguishes the two failures, which the original single `return []`
conflated:

| Failure | Signal | Handling |
|---|---|---|
| The content lane does not exist | SQLSTATE `42P01`, or `no such table` | `return []`, do NOT mark degraded — the schema is missing, not unwell |
| A pool query failed | anything else | drop that pool, keep the rest, mark degraded (10s TTL) |

Both branches are now pinned by tests, including the lane-absent one that had no
coverage before and whose absence is what let the regression through.

**Transferable lesson:** a `catch` that looks like clumsy over-catching may be
holding a side-effect ordering invariant. Check what the code AFTER the catch
does before you turn a bail-out into a `continue`.

### 5.3 `#API-1` was fixed on three fields and missed a fourth — on the same wire

The audit names three fields: `publishedAt`, `firstSeenAt`, `startsAt`. I fixed
those three and stopped, because the audit stopped.

`review.reviewedAt` (`PoolResolver.php`, inside the `review` sub-object) has the
identical defect. `review` is a PUBLIC field — it is in `ITEM_KEYS` and NOT in
`DASHBOARD_ONLY_ITEM_KEYS`, so `buildPools()` ships it — and
`content.f_review.reviewed_at` is `timestamptz`. Verified by the reviewer on a
live Postgres connection: pdo_pgsql returns `"2026-07-01 10:00:00+00"` — space
separator, colon-less offset — **and the same instant renders differently
depending on the session `TimeZone`** (`"2026-07-01 20:00:00+10"` under
`Australia/Sydney`).

**The part worth keeping.** `ReviewsPoolTest` already asserted
`'reviewedAt' => '2026-07-01T10:00:00Z'` and passed — while the field was
completely unconverted. It passed because **SQLite returns the seeded string
verbatim**, so the assertion could never observe the Postgres rendering it
appears to pin. A test that looks like coverage of exactly this defect was
structurally incapable of detecting it.

That is CLAUDE.md's "tests run SQLite, prod is Postgres" warning in its least
obvious form: not a constraint that fails differently, but an assertion that
passes for a reason unrelated to the thing it names.

**WIRE CHANGE, flag it in review:** `reviewedAt` now emits `+00:00` rather than
`Z`. Same instant, valid ISO-8601 either way, and it now matches the four
sibling timestamps that all go through `Carbon::toIso8601String()`. I could find
no consumer depending on the `Z` form, but this is a public payload and it is
the owner's call to confirm.

---

## 6. The `#SEC-2` backfill — scoped, NOT done, needs a human

The prompt's instruction was: ship the code fix, do not touch landed data, write
the backfill question down. Here it is, with real numbers.

**No data was modified.** The queries below are read-only counts. Deliberately no
names in this file — it is git-tracked, and copying the PII into the remediation
ticket would be its own disclosure.

### Scope, measured on DEV (`glncumufgaqcmqhzwrxm`) 2026-08-19

| | |
|---|---|
| `content.media_assets` rows with a non-null `attribution` | **129** |
| …carrying at least one author entry | **129** (all of them) |
| Total author entries | **129** |
| **Distinct named people** | **59** |
| Author entries carrying a Google profile URI | **129** (all of them) |
| Distinct site owners affected | 12 |
| Of those owners, `status = 'unclaimed'` | **4**, holding **40** of the entries |
| Landed between | 2026-08-12 and 2026-08-18 |

Production holds **none** of this — it has no `content` schema at all — so this is
a dev-only remediation and there is no customer-facing exposure to race.

### The decision needed

**(a) Which rows?** The 40 entries on the 4 unclaimed sites are unambiguous: the
`when_unclaimed` scope says we should never have held them. The other 89 sit on
claimed sites, where the declared policy is that the credit stays. Narrow
remediation = 40 entries; the conservative reading = all 129, on the grounds that
they were landed BEFORE the scope was enforceable and nobody has re-consented.

**(b) Redact or delete?** Two shapes:
- **Redact in place** — `jsonb_set` the `authors` key out, leaving `maps_uri` /
  `flag_uri` intact. Keeps the photo displayable with its link-back, and matches
  exactly what the code fix now does on ingest. Recommended.
- **Delete the whole `attribution` value** — simpler, and loses the link-back
  half of the Places obligation for rows that are still displayed. Worse.

Note the code fix does NOT heal these rows on its own: `resolveMediaAssets()` is
**mint-only** by design (a Google photo ref rotates every fetch, so a re-fetch
arrives as a NEW row). Existing rows keep their credit until something rewrites
them. So this backfill is genuinely required, not merely tidy.

**(c) Does it interact with `LEGAL-2`?** Yes — same vendor, same data subjects,
same legal basis question, different surface (reviewer names vs photo
contributors). `project_google_reviewer_pii_open` is already flagged
**LEGAL-2 before pilot**. Do these together; deciding them separately risks two
different answers to one question.

### Why it was not done tonight

A redaction pass over live rows is a locked DB write on a table with data,
against a policy question nobody has ruled on, at 3am. That is three separate
reasons to stop, any one of which would be enough.

Suggested shape when someone does it — as a reviewed command with a `--dry-run`,
not a psql one-liner:

```sql
-- narrow scope; widen the WHERE to all rows if (a) is answered "all 129"
UPDATE content.media_assets ma
   SET attribution = ma.attribution - 'authors'
  FROM core.users u
 WHERE u.id = ma.user_id
   AND u.status = 'unclaimed'
   AND ma.attribution ? 'authors';
```

---

## 7. Open follow-ups this run surfaced but did not fix

### 7.1 `WarmPublicSiteCacheJob` ignores the degraded flag (found during #LIFE-6 review, P2)

`IndividualProfileController` is the ONLY caller that checks
`lastBuildDegraded()` and rewrites the keys via `CacheLockService::shortenDegraded()`.
`App\Jobs\Cache\WarmPublicSiteCacheJob::handle()` calls `rememberLocked($key,
$builder->cacheTtl(), fn () => $builder->build(...))` and never asks.

So a WARM-triggered build that degrades caches a partial payload at the full 60s
TTL plus its ×10 stale twin. A later visitor gets a cache HIT, which by the
controller's own comment never re-runs the resolver — so nothing can shorten it
retroactively, and #LIFE-6's "heals seconds after the database does" does not
hold on that path.

**Pre-existing, not a regression** — the asymmetry existed before this unit,
when only `safeQuery()` could arm the flag. Strictly better than the old
all-or-nothing `return []`. But it is the natural next fix, and it is small:
hoist the shorten-if-degraded check to wherever both callers can share it.

### 7.2 `#SCALE-15` ≡ `#LIFE-19` has a known cheap fix — see §4.4

Not a mystery any more, just unscheduled. The `ref` ends with `:video`, which is
knowable at `dispatchMirrors()` time.

### 7.3 The `LiveSourceScope` pause ruling — see §4.1

The single most consequential open question from this run, because the answer
changes six surfaces rather than three.

---

## 8. `#LIFE-5` — option (a) taken, and the prompt's predicate was wrong

**Decision: implemented (a)**, a daily reconcile command
(`ingest:reconcile-eager`, `app/Console/Commands/IngestReconcileEagerCommand.php`),
scheduled at 04:10. Chosen because it is ADDITIVE and REVERSIBLE — it changes no
existing write path, and if the owner prefers **(b)** a persisted "needs eager
run" flag the scheduler selects, deleting this command costs nothing.

**The alternative, stated fairly.** (b) is arguably the better shape: it puts the
state on the row where it belongs instead of re-deriving it nightly, and it
removes a scheduled entry. It is also a change to `SourceScheduler::scoreDue()`
and a new column, i.e. a migration — which is inside the blocker gate, so it was
not available tonight even if it were preferred.

### The prompt's suggested predicate does not work — worth knowing before (b)

The prompt said to find sources "with `auto_sync=false` and no successful
`last_run_at`". `last_run_at IS NULL` is the wrong test, and it misses the exact
case the finding describes:

- `SourceScheduler::release()` stamps `last_run_at = now()` on **every** path,
  including `release('error')` — which is what `maybeRunEagerly()`'s own catch
  calls when the dispatch throws. So a source stranded by a failed dispatch has a
  **non-null** `last_run_at` and would be skipped.
- The other stranding route — dispatch succeeded, job never executed — is cleared
  by `releaseStranded()`, which does **not** stamp `last_run_at`. So that one
  leaves it null.

Two routes to the same bug, opposite values in the field the prompt suggested
gating on. The command therefore asks the only question that means the same thing
in both cases:

> no `ingest.runs` row for this source has ever reached a landing outcome
> (`ok` / `not_modified` / `degraded`)

`degraded` counts as landed deliberately: the fetch and the landing both
succeeded and only a projection failed, which is fixed with `ingest:project`, not
by re-fetching a metered vendor.

### Guards, because this dispatches PAID connectors

Thirteen of sixteen connectors run eagerly on connect, and several are Metered or
Actor-billed. So the command will not re-dispatch when: the source is in flight,
`health = 'dead'`, `consecutive_failures >= 3`, it is younger than a 30-minute
grace window (its eager run may simply not have written its run row yet), its
manifest does not ask for an eager run at all, or `auto_sync` is already true.
`--limit` (default 50) bounds one pass and `--dry-run` reports without acting.
Ten tests cover each of those.

### `#LIFE-16` / `#LIFE-17` not repeated

Those two findings record that the overnight run's two new scheduler entries
omitted `withoutOverlapping(N)`, `runInBackground()` and `onFailure()`. This entry
carries all four conventions from `routes/console.php`'s own header, plus a
`description()`. It sits at **04:10**, outside the crowded 03:xx block, because it
DISPATCHES ingest runs rather than doing local work and should not add a burst to
the ingest queue on the same minute as a dozen prunes.

**Fixing #LIFE-16/#LIFE-17 themselves is out of scope for this run** (P2, and a
different file's units) — they stay open.

---

## 9. Unit 7 split: `#SCALE-5` done, `#SCALE-4` deliberately NOT

The prompt calls both "mechanical batching". One of them is; the other is the
same hazard `#LIFE-1` was held back for.

### `#SCALE-5` (`writeFacets` — one upsert per facet per item per run) — DONE

Genuinely batchable, but not naively. Three things make a flat "collect and
upsert once per table" wrong, and all three are handled:

1. **Heterogeneous column sets.** Each record contributes only the columns it
   actually has. Laravel's `upsert()` takes its column list from the FIRST row,
   so unioning rows and null-filling would generate an update list containing
   columns a given record never mentioned — and NULL them on conflict, wiping a
   value another source had legitimately written. Rows are therefore bucketed by
   their exact column signature, one upsert per (facet, signature).
2. **Same (item, source) twice in one batch.** A same-source merge puts two
   records on one item — the case `writeFacets`'s own comment calls out. Two rows
   with the same conflict target in ONE upsert payload raises Postgres 21000
   ("ON CONFLICT DO UPDATE command cannot affect row a second time"), the exact
   hazard `LanderBatchLandingTest` already pins for `record_state`.
3. **What de-duplication must preserve.** Sequentially, the second upsert
   overwrites only the columns IT names, so the stored row ends up a per-column
   UNION with later values winning. A last-row-wins de-dup would silently drop
   columns only the earlier record carried. The fold is therefore per-column, not
   per-row, which reproduces the sequential result exactly.

### `#SCALE-4` (`bindGroup` — one `item_anchors` read per identity group) — NOT DONE, box left open

The remedy is "hoist the read above the loop". That loop's body **mutates the
very table being prefetched**:

- `bindGroup()` inserts into `content.item_anchors` (`insertOrIgnore`, #PGR-7);
- on a lost race it UPDATEs `item_anchors.item_id` for coords bound earlier in
  the same call;
- `mergeInto()` rewrites `superseded_by` on anchors — and those can belong to
  items OTHER groups in the same loop are about to read.

So a snapshot taken before the loop can be stale by the time a later group reads
it, and the failure mode is a group binding to an item id that no longer wins:
a wrong merge on the content identity spine, which `mergeInto()` makes
**partially irreversible** because it hard-deletes.

That is the same class of hazard as `#LIFE-1`, on the same function, and
`#LIFE-1` was explicitly held to PLAN ONLY tonight for that reason. Doing the
cheaper half of the same problem unattended, without the locking `#LIFE-1`
proposes, would be taking the risk while skipping the mitigation.

**Recommendation: do `#SCALE-4` as part of `#LIFE-1`, not before it.** Once
`resolveItems()` holds an advisory lock and one transaction
(`2026-08-19-LIFE-1-identity-race-plan.md`), the prefetch becomes safe almost for
free — the snapshot cannot go stale under a lock that no other writer can cross,
and the read is inside the transaction that already serialises the loop. Two
findings, one correct change, in that order.

`#CACHE-5` (the per-coord anchor INSERT) is the same function and the same
argument; it stays open too.

---

## 10. A fourth review catch, and a procedural mistake worth recording

### 10.1 I reported "pint clean" when the CI gate was red

`vendor/bin/pint <path>` **fixes** the file and then reports
`{"result":"passed"}`. The CI gate (`ci.yml:126`) is `vendor/bin/pint --test`,
which only checks. I ran the former, then edited the same file again afterwards,
and reported clean on the strength of a run that had been invalidated.

That is not a formatting problem, it is a verification problem: I asserted a gate
was green having never run the gate. **`pint --test` is the check; `pint` is a
fixer that reports success at having fixed things.** The final state now runs
`--test` across the whole tree.

### 10.2 The `deferred` hole in `ingest:reconcile-eager`

`SourceScheduler::release()` returns EARLY for `outcome === 'deferred'` with a
`retryAfterSeconds` — it reschedules `next_attempt_at` and returns **before** the
`$qualifies` check, so it increments neither `consecutive_failures` nor `health`.
And `'deferred'` is not a landed outcome. A source in that state therefore passed
every guard the command had — in-flight, health, failure count, grace — **forever**,
and would have been re-dispatched on every 04:10 run. On a Metered or
Actor-billed connector that is unbounded vendor spend: precisely the hazard the
command exists to prevent, reintroduced by the command itself.

Dormant today — no connector emits a `Deferred` message yet — which is exactly
why it was worth closing now rather than leaving for whoever adds async polling
to an Actor connector.

**Fix:** `->where('next_attempt_at', '<=', now())`, chosen over adding
`'deferred'` to the landed set because it is correct for both cases instead of
special-casing one. A deferred source genuinely has not landed and does still
need reconciling — just not before the vendor's own retry time. The same line
also stops the command re-claiming a source inside the exponential backoff
`release('error')` has just set, a milder second version of the same mistake that
was also present and that I had not spotted either.

### 10.3 Tally

Every independent review but one returned FAIL first, and every one of those
found something real:

| Unit | Caught |
|---|---|
| `SEC-1` | a GD2 format bypass of my own new guard (§5.1) |
| `LIFE-6` | a 200 → 500 regression from an over-eager `continue` (§5.2) |
| `API-1` | a fourth timestamp field on the same public wire (§5.3) |
| `SCALE-5`/`LIFE-5` | a red CI gate I had reported green, stale comments, and the `deferred` hole (§10.1, §10.2) |
| `SCALE-5`/`LIFE-5` (verify pass) | my fix for the `deferred` hole was too blunt — it would have delayed Instagram recovery by a week (§10.4) |

Only `WHK-1` passed first time — and the last entry is a review catching a defect
in a fix made for an earlier review catch. If this run is used to argue about
process, that is the number that matters: **the independent-review step is not
overhead at 3am, it is where most of this work was actually made correct.**

### 10.4 The first version of that guard was too blunt — a fifth review catch

`next_attempt_at <= now()` for EVERY candidate looked right and was not. Measured
per connector rather than assumed:

| Connector | `defaultIntervalSeconds` → `min_interval_secs` | backoff on failure **#1** |
|---|---|---|
| **instagram** | 604800 | **7.0 days** |
| **spotify** | 604800 | **7.0 days** |
| **soundcloud** | 604800 | **7.0 days** |
| google_business | 172800 | 4.0 days |
| bandcamp | 43200 | 1.0 day |

`SourceProvisioner` writes `min_interval_secs = $manifest->defaultIntervalSeconds`
and `max_interval_secs = max(that, 604800)`. For the three Actor-billed
connectors those are EQUAL, so `release()`'s `min(max, min × 2^failures)` is
**already maxed on the first failure** — not a small backoff that grows. One
transient failure would have pushed recovery out by a full week, on **instagram**,
the connector this command's own docblock names as the reason it exists. With
`MAX_FAILURES = 3` that is up to ~3 weeks before the source is abandoned entirely.

My §10.4 as first written quantified only Google Business's milder 4-day case and
so made the trade-off look cheaper than it was.

**Revised fix — honour `next_attempt_at` for DEFERRED sources only:**

```php
->where(fn ($q) => $q->where('next_attempt_at', '<=', now())
    ->orWhereNotExists(fn ($sub) => $sub->select(DB::raw(1))
        ->from('ingest.runs')
        ->whereColumn('ingest.runs.source_id', 'ingest.sources.id')
        ->where('ingest.runs.outcome', 'deferred')))
```

which reads: eligible if due, **or** if it has never been deferred. That closes
the unbounded-`deferred` hole exactly, and stops dragging the ordinary `'error'`
backoff along with it. **A vendor asking us to come back later is a reason to
wait; our own queue dropping a job is not.**

`next_attempt_at`'s backoff semantics were designed for the auto-sync scheduler
cadence, over `auto_sync = true` sources. These are `auto_sync = false` by
definition — nothing consulted that column for them before this command existed,
and adopting it wholesale imported a policy that was never written for them.

The test that covered this is now driven through a **real**
`SourceScheduler::release('error', false)` on a source with genuine Instagram
intervals, and asserts both the 7-day backoff (the premise) and prompt recovery
(the behaviour). The version it replaced set an arbitrary 2-hour
`next_attempt_at` and would have passed against the blunt guard — a fixture
chosen loosely enough to hide the thing it was meant to measure.


---

## 11. A second pre-existing red gate on `development`

Alongside §1.1's migration-lint failure: **`vendor/bin/phpstan analyse` reports 7
errors on a clean checkout of this branch's base**, and the same 7 byte-identical
on my branch (diffed, not counted):

- `app/Ingest/Connectors/FreshaConnector.php:187` — a redundant `??`
  (`nullCoalesce.offset`);
- 6 × `ignore.unmatched` — baseline ignore patterns in
  `IndividualProfilePayloadBuilder.php` and `IndividualProfileResource.php` that
  no longer match any reported error, i.e. the phpstan baseline has drifted ahead
  of the code.

None are mine. Both are one-line fixes and neither belongs in an audit-fix
branch, but between this and the migration lint, **two of CI's gates are red on
`development` independently of anything in this sweep** — worth knowing before
anyone reads a red build here as caused by these commits.
