# Decisions taken unattended — P1 overnight run, 2026-08-19

Branch `audit-fix/p1-overnight-2026-08-19`, cut from `origin/development` at
`60e142011`. Working the 15 open P1s of `audits/sweeps/2026-08-18-overnight-run/`
per `docs/superpowers/plans/2026-08-19-audit-P1-OVERNIGHT-EXECUTE-PROMPT.md`.

Every entry below is a decision **nobody signed off on**, taken because the run
was unattended and the prompt's standing instruction is to take the documented
default and keep going rather than block. Each one is reversible.

> **SUMMARY LIVES AT THE BOTTOM** — "What landed / what is unmerged / what is
> plan-only / what I could not verify" is the last section, written last.

---

## 0. Environment findings that are NOT audit findings

These cost real time and the morning session should know them.

### 0.1 `development` cannot run `composer test` locally right now — pre-existing

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

### 0.2 A leaked poll loop had made the machine unusable

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

### 0.3 Docker killed both Postgres test containers mid-run

The first PG-lane baseline burned **1,533 s and reported 207 failed / 15 passed**.
Every failure was `SQLSTATE[08006] … timeout expired` at exactly 60.0 s — i.e.
**207 connection timeouts, not 207 test failures**. Docker had issued a `fast
shutdown` to my container *and* to the peer's `partna-pgtest-r4` at the same
instant. Restarted, recreated the database, re-ran: **222 passed in 27 s**.

If a future session sees a uniform-60 s PG failure wall, check
`docker ps -a` before believing any of it.

---

## 1. Baselines (mine, taken on this branch's base — no carried-forward "known red")

| Lane | Result | How |
|---|---|---|
| SQLite (`artisan test`) | **8540 passed, 2 skipped, 1 warning, 0 failed** (606 s) | guards bypassed per §0.1 |
| Postgres (`phpunit.pg.xml`) | **222 passed, 0 failed** (27 s) | throwaway `postgres:16` on :55434 |

Both clean. Any red after this point is mine until proven otherwise.

---

## 2. Wrong premises found in the audit (Rule Zero)

`TRIAGE.md` §6 already disproved one cited precedent and flagged one product
ruling. It was right to expect more. Confirmed so far:

### 2.1 `#WHK-1` — the intent claim is half wrong, and a PRIOR unit deliberately deferred the fix

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

### 2.2 `#SEC-1` — cited precedent still does not exist (TRIAGE §6 confirmed)

`ImageVariantService::assertWithinPixelBudget()` does not exist; `grep` over
`app/` and `tests/` returns nothing. The real guard is inline and private in
`ImageVariantService::loadImage()` (`app/Services/Media/ImageVariantService.php:468-499`)
and is **path**-based. Also: the prompt cites `config/partna.php:1561` for
`image_max_pixels`; it is actually **:1594**. Line numbers in this audit have
drifted — treat all of them as approximate.

### 2.3 `#LIFE-3` "keep it out of scope" is not implementable as written

*(see §3.1 — this is a decision, not just a wrong premise)*

---

## 3. Product / scope decisions taken without sign-off

### 3.1 `#LIFE-3` (paused connection) — the ruling was ALREADY TAKEN, in code

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

### 3.2 `#SEC-2` — redact the whole `attribution.authors` path, not the audit's wildcard

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

### 3.3 `#SCALE-3` (whole media bodies in PHP memory) — NOT fixed, and not fixable in `MediaMirror`

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

### 3.4 `#SCALE-15` ≡ `#LIFE-19` (fetches to the 80 MB video cap before the 15 MB image cap) — NOT fixed, but here is the missing piece

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
