# Execute prompt — all 15 open P1s from the 2026-08-18 overnight-run sweep

**Built for an unattended overnight run.** Supersedes
`2026-08-18-overnight-audit-P1-EXECUTE-PROMPT.md`, which covered a 10-finding
tranche and was written for an attended session that could answer questions
mid-run. Its verified content (the disproved `assertWithinPixelBudget` precedent,
the 25P02 note, the SQLite-can't-reproduce warning) is carried forward below.

State: `audits/sweeps/2026-08-18-overnight-run/TRIAGE.md`, verified 2026-08-19
against `deaba1a2b`. 101 findings, 4 fixed, 97 open, **15 open P1s**.

**The overnight adaptation.** `fix-flow.md` pauses P0 / auth / money / DB / L-XL
units for sign-off. At 3am there is nobody to sign off, and a session that waits
burns the night. So the gate changes shape: **gated units are worked and
committed on the branch, and explicitly NOT merged.** Nothing gated reaches
`development` without a morning review. Two units are further restricted to
plan-only or memo-only, marked below.

---

```
Rename this session to audit-fix-p1-overnight.

Execute the 15 open P1 findings from
audits/sweeps/2026-08-18-overnight-run/CONSOLIDATED.md, following
scripts/audit/fix-flow.md, with the unit order and constraints below.
Read audits/sweeps/2026-08-18-overnight-run/TRIAGE.md FIRST — it records which
findings are already fixed, which IDs are the same defect, and known errors in
the audit file itself.

Branch audit-fix/p1-overnight-2026-08-19 off development.

RULE ZERO — VERIFY THE PREMISE BEFORE YOU FIX IT.
Everything in that audit was machine-drafted and adjudicated; none of it is
proven. TRIAGE.md §6 already disproves one cited precedent and flags one product
ruling the audit assumed as fact. Expect more. A `//` comment offered as Evidence
narrates history, not current state. If a premise is wrong, write that down as
the unit's outcome and move on — "resolved as an open question" is a legitimate
close, and closing a finding WONTFIX with a stated reason beats a fake fix.

YOU ARE UNATTENDED. NEVER BLOCK ON A HUMAN.
  - No question stops the run. If a unit needs a decision, take the documented
    default below, record the decision and its reasoning in the unit's commit
    body, and keep going.
  - If a unit goes sideways, stop THAT unit, commit whatever is green, write what
    went wrong, and start the next one. Do not spend the night on one problem.
  - Never leave the tree broken between units. One commit per unit, tests green
    at each commit, so a crash at 4am loses one unit and not the night.
  - Do not merge, do not open a PR, do not push to development. Leave the branch.

SETUP.
  Work in your OWN git worktree, OUTSIDE the repo — under your scratchpad, NOT in
  .worktrees/. A peer session has repeatedly deleted .worktrees/ and switched the
  shared checkout's HEAD mid-task; during the audit run it deleted a worktree out
  from under a running process. Check `git worktree list` and `git status` in the
  main checkout before you start, and re-check that PoolResolver.php,
  ProjectionWriter.php and MediaMirror.php are not owned by another worktree.
  Copy vendor, do NOT symlink it (`cp -a <main>/vendor ./vendor`) — a symlinked
  vendor makes Pest's ->in('Feature') binding resolve to the main checkout, the
  app never boots, and you get ~1100 fake failures. Symlink .env.

BASELINE.
  Take your own and treat ANY failure as yours until proven otherwise. Do not
  carry forward a "known red" list from any prompt or session. The overnight run's
  checkpoint claimed 8349 passed / 0 failed at 49f02e231; development has moved
  many commits past that under concurrent sessions, so that number is history.
  Record the baseline for BOTH lanes before unit 1: `composer test` and
  `composer test:pg`. Tests run SQLite; production is Postgres. Verify any
  constraint-bound write against supabase/migrations/ DDL, not a green suite.

---
UNIT 1 — Lander per-record isolation: #WHK-1                    [no gate]
  Lander::landRecordsIndividually() (app/Ingest/Landing/Lander.php:297). land()'s own comment states the
  intent — "isolates the one bad record and lands the rest durably, just slower" —
  but the foreach has no per-record try/catch around its DB::transaction(), so the
  first poison record aborts every later record in the chunk. RunExecutor::execute()
  (app/Ingest/Runtime/RunExecutor.php:144) calls land() without a try/catch, unlike its drain() and
  projectStream() siblings which both catch/report/degrade, so it propagates and
  skips every remaining STREAM for that source. RunSourceJob is $tries = 1 —
  nothing re-attempts.
  - Keep the catch OUTSIDE the transaction closure. Lander.php:70-75 explains why:
    a caught-and-recovered failure inside an open Postgres transaction poisons it
    (25P02).
  - ⚠ SQLITE CANNOT REPRODUCE THIS. The scenario is a literal NUL byte in a scraped
    caption rejected by jsonb with 22P05 — Postgres-only. Write the regression test
    into tests/Postgres/ and run `composer test:pg`. LanderTest.php currently has
    NO coverage of the fallback's isolation behaviour.
  - First because it is self-contained, high-value, and needs no decision.

UNIT 2 — MediaMirror family: #SEC-1 + #SCALE-3                  [no gate]
  Riders in the same file, absorb them: #SEC-5, #SCALE-15 (≡#LIFE-19), #LIFE-12.
    #SEC-1  (P1) mirror() (:107-111) enforces only strlen($body) > 15 MB before
            WebpEncoder::encode(), which calls imagecreatefromstring($body)
            (WebpEncoder.php:31) with no pixel-count check. Decompression-bomb DoS
            on the ingest queue, from a source the file's own docblock calls
            "untrusted by definition".
    #SCALE-3 (P1) whole fetched bodies held in PHP memory before storing.
    #SEC-5  (P2) content.media_assets updates key on id only, never user_id.
    #SCALE-15≡#LIFE-19 (P2) fetches to the 80 MB video cap before the 15 MB image cap.
    #LIFE-12 (P2) fail() has no aggregate escalation, so a systemic mirror outage
            stays invisible to Nightwatch. (Orphaned by the audit's own bundling —
            it appears in no bundle. Rehomed here.)
  - ⚠ THE AUDIT'S CITED PRECEDENT DOES NOT EXIST. #SEC-1 says to reuse
    `ImageVariantService::assertWithinPixelBudget()`. THERE IS NO SUCH METHOD.
    The real guard is inline and private in ImageVariantService::loadImage()
    (app/Services/Media/ImageVariantService.php:468-474): assertImageMime($path),
    @getimagesize($path), then
    width*height > config('partna.image_max_pixels', 24_000_000) -> throw
    UnprocessableImageException.
  - It is also the WRONG SHAPE to copy. That guard is PATH-based; MediaMirror holds
    BYTES. getimagesize() takes a filename, so the audit's literal instruction
    ("run a header-only getimagesize() on $body") does not work. Use
    getimagesizefromstring($body) — already the pattern in
    app/Services/WebsiteScan/GalleryAutoGrabber.php:130 and
    app/Services/Design/LogoAutoGrabber.php:419.
    (The superseded prompt cited these two as bare filenames; both live under
    different directories than the name suggests — do not waste time hunting.)
  - Preferred fix: extract ONE string-based pixel-budget guard both paths call,
    rather than a third hand-rolled copy. config('partna.image_max_pixels') is real
    (config/partna.php:1561) — use it, do not invent a key.
  - Regression test: a small, highly-compressed, enormous-dimension PNG (e.g.
    20000x20000) must be rejected BEFORE imagecreatefromstring() runs.

UNIT 3 — SectionCandidates query shaping: #SCALE-1 + #SCALE-2   [no gate]
  #SCALE-1 (:116-130) the default/occurrence sort runs a correlated scalar subquery
  per candidate row on the public path. #SCALE-2 (connectionSourceLatestArm, :338)
  runs a correlated COUNT scanning the whole item table for auto-selection.
  - The existing comments explain WHY they are correlated subqueries and not joins:
    f_occurrence is keyed (item_id, source_id), so an item carried by two sources
    emits two candidate rows through a join. Any rewrite MUST preserve that — a
    lateral join or a pre-aggregated CTE, not a naive join.
  - Correctness first. A faster query that duplicates or drops candidate rows is
    worse than a slow correct one. Pin the current output shape in a test BEFORE
    changing the query, then prove the rewrite matches it.

UNIT 4 — buildPools degraded-cache gap: #LIFE-6                 [gate: do not merge]
  Closing this closes #CCH-3 and #API-3 — same defect, three IDs (TRIAGE.md §2).
  IndividualProfilePayloadBuilder::buildPools() (:240-248): one QueryException
  returns [] for EVERY pool, not just the failing one, and the empty result caches
  for the full 60s TTL. Most likely to fire under DB load — i.e. when a page is
  popular.
  - VERIFIED CONTEXT, use it: the degraded-TTL machinery already EXISTS —
    degradedCacheTtl() (10s, :702) and lastBuildDegraded() (:689). But
    lastBuildDegraded() delegates to SitepageDataResolverService::hasDegraded(),
    a DIFFERENT resolver reached via its safeQuery() path. PoolResolver has no
    degraded concept at all, so the pool catch can never mark the build degraded.
    The wiring is the fix, not new machinery.
  - DECISION, pre-taken, do not stall: a failing pool should drop THAT pool and
    mark the build degraded (10s TTL); the pools that resolved should still
    publish. Do not return [] for all seven, and do not serve a full-TTL empty.
  - Public wire + cache contract, so: commit on the branch, DO NOT MERGE.
  - These are READ paths. The three-lane cache contract (BuildState::bump +
    site.sites.updated_at + edge purge) does NOT apply. Do not add lane busts.

UNIT 5 — PoolResolver liveness + wire: #LIFE-2 #LIFE-4 #API-1 (+#LIFE-8)
                                                                [gate: do not merge]
  The highest-return unit in the sweep. W2 introduced LiveSourceScope as the
  "disconnect = hide" contract and applied it to PoolResolver::resolve()'s library
  query and pinned-items re-check (:219, :230) — and to nothing else.
    #LIFE-2 statsFor() (:309-321) — joins content.source_stats -> content.source_items
            with NO removed_at and NO connection-liveness filter. Disconnect a Google
            listing because the reviews are bad and the rating keeps publishing.
    #LIFE-4 $sourceLinks (:514-525) — no liveness filter at all. Feeds linkSet(), so
            an item kept alive by one live source can still publish a "book now" link
            to a platform the owner disconnected. NOTE: the newer $offerLinks path
            (:531-545, added after the audit) has the SAME gap — fix both.
    #API-1  the nested sources[] array got the ISO-8601 fix (:613, with a comment
            citing the review), but the three TOP-LEVEL fields visitors actually see
            did not: publishedAt (:881), firstSeenAt (:882), startsAt (:907) still
            emit naive "Y-m-d H:i:s", which a browser's Date() reads as LOCAL time.
    #LIFE-8 ItemLinkRules::syncedPlatformsFor() (:82-92) — counts a retired link as
            still-synced, so the owner cannot hand-add a replacement.
  - ⚠ #LIFE-3 IS DELIBERATELY OUT OF SCOPE. It asserts that is_active = false (a
    PAUSED connection) should hide its content publicly. That is a PRODUCT RULING,
    not a bug — a paused connection arguably should keep publishing what it already
    landed and merely stop syncing. Do NOT implement it. Write the question, both
    options and your recommendation into
    docs/superpowers/plans/2026-08-19-P1-overnight-DECISIONS.md and move on.
    #LIFE-2/#LIFE-4/#LIFE-8 (disconnected/deleted) are not in doubt.
  - RELATED OPEN RULING, cross-reference rather than decide: the W6 review left open
    whether a PINNED item whose only source_item is retired by absence-folding should
    hide (LOG.md, W10). Same contract, adjacent surface. Note it in the same file.
  - Make LiveSourceScope the single definition rather than hand-copying where-clauses.
    A helper that four call sites forget to call is exactly how this bug was born.
  - Read paths only — no pool mutation, so no three-lane cache bust. DO check whether
    the 60s payload cache means a fix needs invalidation to be observable in a probe.
  - Changes the public wire: commit on the branch, DO NOT MERGE.

UNIT 6 — GB photo-attribution PII: #SEC-2         [gate: code only, NO data changes]
  GoogleBusinessConnector::manifest() (:98-103) declares
  redactions ['author','author_uri','author_photo'] scoped when_unclaimed, and
  Redactor::strip() walks them as top-level dot-paths — which correctly strips
  mapReview() output. mapPhoto() (:271) emits a DIFFERENTLY SHAPED 'attribution'
  array (authors[].name / authors[].uri / maps_uri / flag_uri) that no declared path
  names, so ProjectionWriter::resolveMediaAssets() persists the full blob into
  content.media_assets.attribution regardless of claim status.
  - Confirm Redactor::strip()'s wildcard support against a real nested path BEFORE
    writing 'attribution.authors.*.name'. The audit asserts wildcards work; prove it.
  - Ship the code fix + regression test. DO NOT touch already-landed data. Rows exist
    on dev. Write the backfill question — scope, row count, redact-vs-delete — into
    the DECISIONS file. A redaction pass over live rows is a separate, reviewed job.
  - Cross-reference the already-open LEGAL-2 reviewer-PII item; same data-subject
    exposure on two surfaces.
  - Commit on the branch, DO NOT MERGE.

UNIT 7 — ProjectionWriter write amplification: #SCALE-4 + #SCALE-5
                                                    [gate: #LIFE-1 is PLAN ONLY]
  #SCALE-4 bindGroup() (:720-745) issues one item_anchors read per resolved identity
  group and one INSERT per coord (the latter is also #CACHE-5 — same function).
  #SCALE-5 writeFacets() (:971, upserts at :985-1030) fires one upsert per facet per item, every run;
  the code comment even concedes "the singleton-facet upserts below stay per…"
  (also #CACHE-3).
  - Both are mechanical batching. Riders in the same file if time allows and tests
    stay green: #CACHE-1≡#SCALE-11 (recordCandidates row-at-a-time insert), #SCALE-7.
  - ⚠ #LIFE-1 IS PLAN ONLY — DO NOT IMPLEMENT IT TONIGHT. It is an L-effort
    concurrency-correctness change to the core content-identity spine (unlocked,
    untransacted resolveItems/bindGroup, touching mergeInto()'s hard-delete path).
    Unattended is the wrong setting for it. Produce a written plan — failure modes,
    lock strategy, blast radius, test approach — at
    docs/superpowers/plans/2026-08-19-LIFE-1-identity-race-plan.md and stop there.
  - ⚠ TOUCHING ProjectionWriter MEANS RUNNING tests/Postgres/ (`composer test:pg`),
    not just tests/Feature/Ingest/. That lane's stand-in DDL is hand-written and
    drifts silently from writer changes — slice 5a turned it red for 7 tests and two
    reviews missed it on a green SQLite run.
  - Commit on the branch, DO NOT MERGE.

UNIT 8 — Eager ingest source stranding: #LIFE-5    [gate: do not merge]
  IntegrationConnectionObserver::maybeRunEagerly() (:277-333). The method's own
  comment is explicit: the eager trigger fires ONCE on creation, nothing retries it,
  and auto_sync=false keeps the scheduler away no matter what next_attempt_at says.
  A transient dispatch failure means that user's Instagram media never arrives —
  indefinitely, with only a Log::warning Nightwatch does not alert on. Direct
  consequence of the eagerOnConnect work W3/W6 shipped: new surface, not legacy.
  - DECISION, pre-taken, do not stall: implement (a), a daily reconcile command that
    finds eager-provisioned ingest.sources with auto_sync=false and no successful
    last_run_at and re-claims them. Chosen because it is ADDITIVE and REVERSIBLE —
    it changes no existing write path, and if the owner prefers (b) a persisted
    "needs eager run" flag the scheduler selects, the command is deleted cheaply.
    Record the choice and the alternative in the DECISIONS file.
  - If you add a scheduled entry, read #LIFE-16/#LIFE-17 first (out of scope, but
    they record exactly the three chained calls the overnight run's two new entries
    omitted: expiry on withoutOverlapping, runInBackground, description/onFailure).
    Do not repeat that omission. Compare against the KV backfill entry
    (routes/console.php:340-345), which does it correctly.
  - Commit on the branch, DO NOT MERGE.

---
WHEN DONE.
  - Run BOTH lanes and report both with real output: `composer test` and
    `composer test:pg`. Units 1 and 7 are Postgres-sensitive; a green SQLite run
    says nothing about either.
  - php artisan pint on touched files.
  - Tick the checkboxes in CONSOLIDATED.md ONLY for findings you actually resolved,
    and tick every ID in a duplicate row (TRIAGE.md §2) when you close its defect —
    #LIFE-6 closes #CCH-3 and #API-3 too. A ticked box means "resolved as an open
    question", not necessarily "the code changed"; a WONTFIX with a stated reason is
    a legitimate tick. Update TRIAGE.md §1 counts to match.
  - Leave a single summary at the top of the DECISIONS file: what landed, what is
    committed-but-unmerged and why, what is plan-only, what you could not verify,
    and every premise that turned out to be wrong.
  - Do not merge. Do not push to development. Do not open a PR.
```

---

## Morning checklist for the owner

Four things will be waiting, and three of them need you specifically:

1. **`2026-08-19-P1-overnight-DECISIONS.md`** — carries the `LIFE-3` pause-semantics
   ruling (does a paused connection keep publishing?), the `SEC-2` backfill scope
   decision, and the `LIFE-5` (a)-vs-(b) confirmation.
2. **`2026-08-19-LIFE-1-identity-race-plan.md`** — plan only, for sign-off before any
   implementation.
3. **The unmerged branch** `audit-fix/p1-overnight-2026-08-19` — units 4–8 are
   committed but deliberately not merged: public wire, PII, and the identity spine.
4. **Units 1–3** (`WHK-1`, MediaMirror family, `SectionCandidates`) should be clean
   and mergeable on a normal review.
