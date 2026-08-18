# EXECUTE PROMPT — Instagram pre-account builds: does the scraped media ever reach a surface?

**Give this file to a fresh session. It is self-contained.**

## ⚠️ READ THIS FIRST — the premise is NOT established

This started as "fix the `designMedia` regression". **Do not fix that.** The investigation that
produced this file falsified its own premise partway through, and the residue is a genuinely open
question, not a known defect. Your first job is to settle it. Only then, and only if it is broken,
do you fix anything.

**Do not restore `designMedia`, `profile.gallery`, `profile.curatedGallery` or `siteImages` under any
circumstances.** They were deleted deliberately by slice 7 unit E (owner ruling 2026-08-14 — "apps/pages
is rebuilt, not repaired"), recorded in `IndividualProfilePayloadBuilder`'s class docblock. Curated
imagery is the **`media` pool** now. Reintroducing any of those four is an automatic reject.

---

## What is actually verified (2026-08-17)

Established by direct query and by reading the code — treat as fact:

1. **`designMedia` is gone.** `IndividualProfilePayloadBuilder` docblock: *"Slice 7 unit E deleted
   `profile.gallery`, `profile.curatedGallery`, `designMedia` and `siteImages` outright."* That lane was
   how Instagram's mirrored photo/reel used to reach the page.
2. **The Instagram pre-account path still mirrors media.** `InstagramConnectionSeeder` mirrors four fixed
   filenames (`photo.jpg`, `reel.mp4`, `reel-cover.jpg`, `profile.jpg`) and writes their URLs into
   `site.platform_connections.payload`. Verified serving at **HTTP 206 `image/jpeg`** on 2026-08-17.
3. **The scrape returns 12 posts.** `_mediaDiagnostics.posts: 12` on all three builds of the 08-17 wave;
   the generator picks 1 photo + 1 reel from them.
4. **Nothing on this path writes `site.site_media`** — and that is correct. No file under
   `app/Services/Platforms/` or `app/Jobs/Platforms/` references it; the authenticated connect path does
   not write it either. `site_media` is NOT the surface. Do not chase it.
5. **Ingest provisioning works on this path.** `InstagramSourceGenerator:67` uses a plain
   `IntegrationConnection::updateOrCreate()` (fires events) →
   `IntegrationConnectionObserver::saved()` → `syncIngestSource()` → `SourceProvisioner::sync()`.
   Every gate in `sync()` passes for an IG pre-account connection (`resource_kind` NULL,
   `surface_key = instagram.profile`, `is_active` true, not trashed, `payload.username` present).
   Confirmed by row: build `tobiasbalcombe` (2026-08-12) has **1 `ingest.sources` row**.
6. **The media pool CAN be populated for an unclaimed account from `instagram.profile`.** User
   `business1` (status `unclaimed`) has 1 `content.sources` row (kind `connection`, label `instagram`),
   12 `content.source_items`, and **12 `content.items` of kind `media`**. `InstagramMediaProjector`
   exists and works.
7. **The ingest run is asynchronous and SLOW to arrive.** `tobiasbalcombe`: build created `10:47:42`,
   ingest run started **`15:45:02`** — roughly **five hours** later, `trigger: 'schedule'`.
8. **That run succeeded but produced no media:** `outcome: 'ok'`, `records_seen: 1`,
   `records_changed: 1`, `streams: {media: "ok", profile: "ok"}`, and
   `detail.notes: [{"code": "no_posts", "message": "No posts parsed from the actor result"}]`.
   Five days on, `tobiasbalcombe` has **0 items of any kind**.
9. Its source row carries **`auto_sync: false`** with `next_attempt_at = 2026-08-19`.

## What is NOT established — and why

The 08-17 wave (`kimcosmik`, `themilleraffect`, `supernormal_180`) measured its media pool **~5 minutes**
after each build and the builds were pruned **~1 hour** later. Given fact 7, the ingest run had almost
certainly **not fired yet**, and the accounts no longer exist. So:

> **"Instagram pre-account builds never surface their scraped media" is an OPEN QUESTION, not a finding.**

The one long-lived data point (`tobiasbalcombe`) is contaminated by `no_posts` — its actor result carried
no posts at all, which is a scrape-content problem, not a wiring problem. It cannot distinguish
"the wiring is broken" from "this particular scrape was empty".

**Do not carry the earlier framing into your work.** Do not cite "the media has no consumer" as given.

---

# PHASE 0 — Settle the premise (do this before touching any code)

**Deliverable: a yes/no answer with row-level evidence.** If the answer is "it works", you write that up
and stop — that is a complete and successful outcome for this task.

## 0.1 — Read-only reconnaissance first

Answer these from the existing database and the code. No new builds yet.

```sql
-- Every Instagram-sourced pre-account build since the ingest lane existed (oldest
-- ingest.sources row is 2026-07-28 — anything older proves nothing, see the trap below).
select b.source_ref, b.created_at, u.handle, u.status,
  (select count(*) from ingest.sources i where i.user_id=u.id) as ingest_src,
  (select count(*) from ingest.runs r
     where r.source_id in (select id from ingest.sources where user_id=u.id)) as runs,
  (select count(*) from content.items i where i.user_id=u.id and i.kind='media') as media_items
from core.pre_account_builds b join core.users u on u.id=b.user_id
where b.source_type='instagram' and b.created_at > '2026-07-28'
order by b.created_at desc;
```

Then, for every run found, pull `outcome`, `records_seen`, `records_changed`, `effects_count` and
especially **`detail`** from `ingest.runs`. `detail.notes[].code` is where the truth lives —
`no_posts` means the actor came back empty and that account tells you nothing about the wiring.

Also establish, by reading code:

- **What actually triggers the first ingest run**, and how long after connect. `SourceScheduler`,
  `ingest.sources.next_attempt_at`, `auto_sync`. Is there any immediate/eager first run on connect, or
  is the first pass always the scheduler? This is the single most important thing to understand.
- **`auto_sync: false` on `tobiasbalcombe`'s instagram source.** `SourceProvisioner` sets
  `auto_sync => self::schedulable($manifest)`. Work out whether Instagram is schedulable, and if it is
  not, how a run happened at all with `trigger: 'schedule'`. Either the manifest says one thing and the
  scheduler another, or `auto_sync` means something narrower than "will be scheduled". **Resolve this
  contradiction explicitly** — it decides whether an IG source ever re-runs after its first pass.
- **`InstagramMediaProjector`** — what it needs in order to emit `media`-kind items, and which stream
  feeds it.
- Whether the ingest lane's Instagram scrape is a **second paid Apify call**, distinct from the one
  `InstagramConnectionSeeder` already made at build time. If it is, say so loudly in the report: it means
  one signup buys two scrapes of the same profile.

## 0.2 — The decisive experiment

Only if 0.1 leaves the question open.

**Cost gate: this spends a paid Apify scrape (possibly two — see above). Confirm with Josh before
running it.** State the expected cost in your ask.

1. **Check the per-IP cap first** and re-derive your own hash — the value in any older file is stale.
   Cap is `config('partna.pre_account.max_unclaimed_per_ip')` (3). Hash is unsalted
   `hash('sha256', CF-Connecting-IP)` — `PreAccountBuildController.php:39`. Read
   `core.pre_account_builds` grouped by `created_ip_hash` rather than filtering on an assumed hash.
2. Create **one** build from an Instagram handle that unambiguously has recent posts (verify by eye in a
   browser first — this is the whole point; a `no_posts` result wastes the run).
3. Record at **T+5 min**: `ingest.sources` row present? `next_attempt_at`? `auto_sync`?
   `content.items` count?
4. **Then leave it alone and re-measure at T+6h and T+24h.** Record `ingest.runs.outcome`,
   `records_seen`, `detail.notes`, and `content.items where kind='media'`.
5. **Do not delete the build** until the 24h reading is taken and written down. Releasing a cap slot is
   Josh's call — ask.

**This is a wait, not a poll.** Do not sit in a loop burning tokens; take the reading, write it down,
and come back. If the session ends before T+24h, leave the build live and record in the report exactly
what still needs measuring and when.

## 0.3 — The verdict

Write one of these three, with evidence:

- **A — It works.** Media items appear after the run lands. The 08-17 wave measured too early. Nothing to
  fix; record the true latency (build → media on the page) so nobody re-raises this. **Most likely
  outcome — do not treat it as a disappointment.**
- **B — It is wired but starved.** The run fires and returns `no_posts` reliably for pre-account builds.
  The defect is in the actor call or its parsing, NOT in the pool plumbing. Go to Phase 1B.
- **C — It is not wired.** The run never fires, or fires and produces records that never become
  `media`-kind items. Go to Phase 1C.

---

# PHASE 1 — Fix, only if Phase 0 returned B or C

**Blocker gate applies** (`scripts/audit/fix-flow.md`): this touches a paid third-party scrape path and
the public wire. **Write a plan and get sign-off before implementing.** Branch
`audit-fix/ig-media-pool-<date>` off `development`.

### If B — starved

The question is why the ingest actor returns no posts for a profile that demonstrably has them, when the
build-time `InstagramScraper` call got 12. Compare the two call sites: same actor? same input shape?
same account state? Note `reference_actor_row_arrived_but_carries_no_payload` — an "empty" actor result
is a keyed row with no payload, and identical `bodyLength` across two runs means a deterministic wall,
not flakiness. Do not assume flakiness without checking that.

Relevant prior art: the 08-10 wave's F4 was a zero-post scrape that turned out to be **per-run** actor
flakiness (two of three runs in the same hour were fine). If this is the same class of problem, the
finding is that **nothing treats a zero-post result on a high-post account as suspect** — there is no
sanity check and no retry, so a bad run is written as truth. Fixing that is a legitimate outcome.

### If C — not wired

Fix the pre-account path so a build's Instagram media reaches the **`media` pool**. Constraints:

- **Media pool only.** Not `site_media`, not `designMedia`, not `gallery`.
- Reuse `InstagramMediaProjector` and the existing ingest lane. Do not write a second, parallel
  projection path for the pre-account case — `business1` proves the existing one produces 12 items.
- If the gap is that the first run is merely too far away, changing *when* it runs is a scheduling
  change with a cost implication (an eager run on connect = a scrape per signup). **That is a product
  decision — put it to Josh in the plan, do not just make it.**
- Respect `PurgeSoftDeleted::PURGE_EXEMPT` and the three-lane cache contract on any pool mutation
  (`BuildState::bump()` + `site.sites.updated_at` + conditional `CloudflareCachePurgeJob`) — see
  `CLAUDE.md`. Lane 2 is the one people forget.
- Pre-account users are `unclaimed` and have **no email**; anything you add must tolerate that.

### Tests

- Pest, in `tests/Feature/`. New coverage must fail before the fix and pass after — **mutation-verify it**,
  do not just watch it go green.
- **`tests/Postgres/` is mandatory if you touch `ProjectionWriter`** (`composer test:pg`). Its stand-in
  DDL is hand-written and drifts silently; a green SQLite run proves nothing there. See
  `reference_sqlite_passes_what_postgres_rejects`.
- Content fixtures need `item_anchors` or a later manual write re-mints the coordinate
  (`reference_content_fixture_needs_item_anchor`).

---

## Traps that have already cost time on this exact question

- **`nasa` and `natgeo` are NOT a valid control group.** Both were built 2026-07-20; the oldest
  `ingest.sources` row is **2026-07-28**. Their zero ingest sources predate the lane and prove nothing.
  This mistake was made once already and produced a confident wrong conclusion.
- **`business1` is not a pre-account build.** Its IG username (`Basette_barberia_`) does not match its
  handle. It is a dashboard connect. Useful as proof the projector *works*; useless as proof the
  *signup path* works.
- **Measuring at T+5 min proves nothing.** The run lands hours later. Any reading taken before the first
  `ingest.runs` row exists is meaningless.
- **`ingest.runs.source_id` points at `ingest.sources`, not `content.sources`.** Joining it to
  `content.sources` returns 0 for every user and reads as "no runs ever". This produced a false negative
  during the investigation.
- **`content.source_items.source_id` points at `content.sources`, NOT `ingest.sources`**
  (`reference_source_items_source_id_points_at_content_sources`).
- **A link item's URL lives in `content.f_link`, not `content.item_links`.** `item_links` is 0 rows for
  auto-routed links while the URLs serve fine on the wire. Do not read an empty `item_links` as data loss.
- **`site.site_media` being empty is correct** on this path. It is not the surface. (Fact 4.)
- Dev Supabase ref `glncumufgaqcmqhzwrxm`; prod lacks the `content`, `ingest`, `routing` and `catalog`
  schemas **entirely** — never check this against prod.
- Logs via `cloud env:logs partna development` only; the boost log tools serve stale local output and are
  forbidden. `--minutes N` caps at **100 records** and silently truncates — use narrow `--from`/`--to`
  windows and check the coverage timestamps.

## Deliverable

`docs/reviews/2026-08-17-instagram-media-pool-RESULTS.md`:

1. **The verdict — A, B or C** — stated in the first paragraph, with the rows that prove it.
2. The true **build → media-on-page latency**, measured, whatever the verdict.
3. The resolved `auto_sync: false` contradiction, and whether an IG source ever re-runs.
4. Whether the ingest lane costs a **second** Apify scrape per signup.
5. If A: what the 08-17 wave got wrong and why, so this is not re-raised a third time.
6. If B or C: the plan, the fix, the mutation-verified tests, and what you deliberately did not do.

**A verdict of "it already works" is a success.** Write it plainly and stop.
