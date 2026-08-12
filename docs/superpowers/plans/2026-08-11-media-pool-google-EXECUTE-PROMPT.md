# EXECUTE PROMPT — Light up the Media pool end to end, using Google Business

**Why now:** The Media pool is the documented destination for all site imagery
(`docs/2026-08-05-platforms-as-sources.md`), but it has **never held a single row**. Every piece
of the lane is built — connector, projector, pool machinery, public wire — and none of it has ever
been exercised for `kind='media'`. Meanwhile the old `site.site_media` gallery lane is still what
renders, and every new feature added to it deepens the P4 teardown.

Google Business is the cheapest possible proof of the whole architecture: it is
**`CostClass::Metered`, not `Actor`**, so it does **not** need the unbuilt actor driver that blocks
Instagram. Prove items → pool → dashboard → public page with Google, and Instagram later becomes
"add a producer to a lane you have watched work" instead of a leap of faith.

Paste everything below the line into a fresh session. It is self-contained.

---

Read `CLAUDE.md` and `docs/2026-08-05-platforms-as-sources.md` in full before touching anything.
That doc is the owner's decision record for this architecture; this prompt finishes one slice of its
"P3 REMAINING" and prepares P4.

**Treat that doc as intent, not as state.** It contains at least one claim the database refutes
(it says Instagram "grab-all is already live via the ig connector"; `ingest.sources` for instagram
shows 0 runs and `content.items` has 0 rows of `kind='media'`). Verify every "already live" claim
against the DB before relying on it.

## Verified state — 2026-08-11, dev (`glncumufgaqcmqhzwrxm`)

```
content.items by kind : release 219, video 130, episode 106, event 14, channel 7, article 1
                        media 0            ← the entire point of this work
ingest.sources        : youtube  4 rows, auto_sync ON,  last run 2026-08-11 09:15  ✅ working
                        spotify  3 rows, auto_sync ON,  last run 2026-08-11 06:15  ✅ working
                        google_business 12 rows, auto_sync OFF, NEVER RUN          ← unit 1
                        instagram        1 row,  auto_sync OFF, NEVER RUN          (out of scope)
ingest.effects        : 0 rows  (billed-effect lane never exercised — Instagram's blocker, not yours)
site.sections pool:*  : 30 provisioned
site.section_items    : 3
```

## Already built — DO NOT rebuild any of this

| Piece | Evidence |
|---|---|
| `GoogleBusinessConnector` with a **`media` stream** | `app/Ingest/Connectors/GoogleBusinessConnector.php:90-95`, `cost: CostClass::Metered` at `:101` |
| `GoogleBusinessMediaProjector` → `kind: 'media'` | `app/Ingest/Projection/GoogleBusinessMediaProjector.php:19-22`, registered in `ProjectorRegistry` |
| `ProjectionWriter` → `content.items` + `content.media_assets` | 467 media_assets rows exist from other sources |
| Pool machinery | `app/Site/Pools/` — `PoolRegistry::POOLS['media']`, `PoolResolver`, `PoolSectionProvisioner` |
| Dashboard pool API | `routes/api/user.php:164-175` — show / select / deselect / reorder / hand-add, generic per pool |
| **Backend public wire** | `IndividualProfilePayloadBuilder::buildPools()` loops **all** `PoolRegistry::POOLS`, so `pools.media` already ships the moment a selection is non-empty. Verify this before assuming backend work is needed. |

## Units, in order

| Unit | What | Size |
|---|---|---|
| **1 — Make google_business actually run** | 12 sources provisioned, `auto_sync=false`, never run. Find out why and fix it | S–M |
| **2 — Prove media items + owned bytes** | A GB run produces `kind='media'` items with mirrored `media_assets` | M |
| **3 — Dashboard Media page** | Partna-App, mirroring the P3 Watch/Listen pages | M |
| **4 — Public rendering** | monorepo/pages renders `pools.media` on the gallery page | M |
| **5 — Live verification** | End-to-end on a real dev account | S |

### Unit 1 — why is `auto_sync` false?

12 `google_business` sources exist with `next_attempt_at` set but `auto_sync=false`, so
`SourceScheduler` never picks them up. YouTube and Spotify rows have `auto_sync=true` and run every
15 minutes, which proves the scheduler itself works.

Find the cause before changing anything. Candidates, in order of likelihood:

1. `SourceProvisioner` defaults `auto_sync` off for this cost class or source.
2. The per-connection `display_settings.auto_sync_latest` toggle (the "one toggle grammar" in the
   2026-08-05 doc) is off by default and gates provisioning.
3. A deliberate hold, because Places is the only uncapped paid API in the project.

**(3) is a real possibility and it is a STOP condition.** Google Places is metered and billable.
If the reason auto_sync is off is cost control, do not simply flip it — write up what enabling it
would cost per account per cycle and get Josh's sign-off first. This hits `fix-flow.md`'s blocker
gate (money).

Prefer a **manual, single-source trigger** for units 1–2 rather than enabling the scheduler
globally. One run against one source proves the lane; 12 sources on a 15-minute cadence is a
billing decision.

### Unit 2 — media items and owned bytes

After a successful run, verify **in the database**, not from logs:

```sql
select count(*) from content.items where kind='media';
select storage_path, source_url, mime_type, width, height, fingerprint
from content.media_assets order by created_at desc limit 5;
```

The thing that must be true: `storage_path` points at **our R2**, not
`lh3.googleusercontent.com`. The owned-bytes policy is why this lane exists — Google's place-photo
URLs are stable enough to hotlink, but `site.platform_connections.payload` already hotlinks them
and the pool is supposed to own them.

**If the media pipeline does not actually mirror bytes, that is the finding** — stop and report it
rather than working around it. `InstagramMediaProjector`'s docblock assumes mirroring exists
("the media pipeline mirrors bytes to R2 (owned-bytes policy)"), and Instagram *requires* it
because its CDN URLs expire. If it turns out to be unbuilt, that is a shared blocker for both
prompts and Josh needs to know immediately.

### Unit 3 — dashboard Media page

Partna-App. The Watch and Listen pages landed in P3 (`lib/queries/pools.ts`, `WatchPage`,
`ListenPage`) and are the template — the pool API is generic, so this should be close to a copy.

Per the 2026-08-05 doc, this unit also owns: **remove the Posts pool** from the site-page
picker/nav and delete the post-grid page component. Confirm with Josh before deleting anything
user-facing.

### Unit 4 — public rendering

The backend already ships `pools.media` (verify first — see the table above). The work is in
partna-monorepo: `resolve-site-content.ts` adapts pool items to the engine media shape, and `staple`
renders them on the gallery page.

**The decisive open question this unit answers:** the sitepage gallery section currently reads
`profile.gallery` (the `site_media` lane). After this unit it should read `pools.media`. Until both
exist you will have two sources feeding one section — decide explicitly whether they coexist during
transition or whether `pools.media` wins outright, and write the decision into the 2026-08-05 doc.

### Unit 5 — live verification

Live-verify on a real dev account the way P2/P3 did. `maha.restaurant` has a Google Business
connection with **10 photos** in its payload and is the natural candidate. Verify the rendered page,
not just the payload — P2 discovered the CDN held a stale page after a pool write, which is why
pool mutations now dispatch `CloudflareCachePurgeJob`.

## Ground rules

- **Never create Laravel migrations.** Schema changes are raw SQL in `supabase/migrations/`.
  Apply to dev first, verify, then prod. One `CONCURRENTLY` statement per file.
- **Logs come from the Cloud CLI**, never the boost log tools:
  `cloud env:logs partna development --minutes 15`. Local log files are stale test output.
- **Work in a git worktree based explicitly off `origin/development`.** `origin/HEAD` is
  `production` and is months stale. This is a shared checkout — check `git worktree list` and the
  sibling worktrees' `git status` before assuming a file is free. Never `git stash`.
- **Tests run SQLite, production is Postgres.** Anything constraint- or trigger-bound must be
  verified against `supabase/migrations/` DDL or in the Postgres lane, not by a green
  `composer test`.
- **`composer test` before you call anything done.** Then check Nightwatch.
- **Do not commit or push without Josh's go-ahead.** He handles commits and reviews everything.
- **Money is involved.** Places is the only uncapped paid API in this project. Any change that
  increases call volume goes through the blocker gate: written plan, Josh's sign-off, then code.

## Definition of done

1. `select count(*) from content.items where kind='media'` returns > 0 for a Google source.
2. Those items' `media_assets.storage_path` values point at our R2.
3. The dashboard Media page lists them and selection persists.
4. A selected item renders on the live dev sitepage's gallery section.
5. `docs/2026-08-05-platforms-as-sources.md` is updated with what actually shipped — including
   correcting any claim in it you found to be false.
