# EXECUTE PROMPT — Instagram into the Media pool: build the billed-effect (actor) driver

**Why now:** Instagram is the reason the Media pool exists
(`docs/2026-08-05-platforms-as-sources.md`: *"Post sources (Instagram today) feed the Media pool
instead: we extract as many photos/videos from the user's posts as the platform allows and offer
them all as selectable Media options"*). The connector and projector are **already written and
registered**. The lane is blocked on exactly one unbuilt thing: the driver that performs a billed
effect.

Today Instagram mirrors **one** photo into `platform_connections.payload` under a fixed filename
that every refresh overwrites in place. That is the interim lane and it is scheduled for demolition
at P4. This prompt is the real destination.

**Run the Google prompt first** (`2026-08-11-media-pool-google-EXECUTE-PROMPT.md`). It proves
items → pool → dashboard → public page using a `Metered` source that needs no driver. Doing
Instagram first means building the driver *and* the pool surface *and* the rendering simultaneously,
with no known-good reference.

Paste everything below the line into a fresh session. It is self-contained.

---

Read `CLAUDE.md`, `docs/2026-08-05-platforms-as-sources.md`, and
`app/Ingest/Connectors/InstagramConnector.php` (the whole docblock) before touching anything.

**Treat the 2026-08-05 doc as intent, not state.** It claims Instagram "grab-all is already live via
the ig connector". The database refutes this: 0 runs, 0 `media` items, 0 rows in `ingest.effects`.
Verify every "already live" claim against the DB.

## Verified state — 2026-08-11, dev (`glncumufgaqcmqhzwrxm`)

```
ingest.effects              : 0 rows       ← NO billed effect has EVER run, for any connector
content.items kind='media'  : 0 rows
ingest.sources instagram    : 1 row, auto_sync OFF (correct — see below), never run
platform_connections        : IG payload holds exactly 1 mirrored photo + 1 reel (the interim lane)
```

## Already built — DO NOT rebuild

| Piece | Evidence |
|---|---|
| `InstagramConnector` — `profile` + `media` streams, carousel handling, both field-name shapes | `app/Ingest/Connectors/InstagramConnector.php` |
| `InstagramMediaProjector` → `kind: 'media'` | registered in `ProjectorRegistry.php:31` |
| `EffectLedger` — charge-once by request digest, refuse-don't-retry on abandoned effects | `app/Ingest/Runtime/EffectLedger.php` |
| `Io::effect()` contract + `HttpIo::effect()` wrapper (ledger, digest, freshness bucketing) | `app/Ingest/Runtime/HttpIo.php:81-110` |
| Pool machinery, dashboard pool API, `buildPools()` public wire | see the Google prompt |

**`auto_sync=false` for Instagram is correct and must stay.** `CostClass::Actor` means
`SourceProvisioner` provisions with the scheduler off *by construction*, so a run only ever happens
on an explicit manual/connect trigger. Do not "fix" it.

## The blocker

```php
// app/Ingest/Runtime/HttpIo.php:116
private function runBilledEffect(string $kind, string $name, array $input): array
{
    throw new \RuntimeException(
        "No billed-effect driver is wired for kind '{$kind}' (effect '{$name}'). ".
        'Actor and AI drivers land with their connectors at P7; until then a '.
        'connector must not declare a billed effect it cannot perform.'
    );
}
```

## Units, in order

| Unit | What | Size |
|---|---|---|
| **0 — PLAN AND SIGN-OFF** | This whole prompt spends money. Nothing starts without it | — |
| **1 — Durable effect-result storage** | The hidden sub-blocker. Read §1 before sizing anything | M–L |
| **2 — The actor driver** | Implement `runBilledEffect` for `kind='actor'` + budget enforcement | L |
| **3 — Trigger on connect/refresh** | Dispatch an ingest run for a manual-only source | M |
| **4 — Prove it end to end** | Real IG account → media items → pool → public page | M |
| **5 — Retire the interim lane** | Only after 4 is live. See §5 | M |

### Unit 0 — the blocker gate, non-negotiable

This touches **money** (Apify billing) and a **charge-once ledger**. Per
`scripts/audit/fix-flow.md`, write the plan and get Josh's sign-off before implementing. Include in
the plan: expected cost per connect, per-user cooldown, daily caps, and what happens on a
double-tap.

The existing guarantee you must not weaken: `GeneratePreAccountSiteJob` and `InstagramConnectJob`
both carry an explicit **"never re-bill the scrape"** contract (`maxExceptions = 1`,
`failOnTimeout = true`). `EffectLedger` generalises the same policy. A driver that retries a paid
actor run on failure breaks a deliberate, load-bearing design decision.

### Unit 1 — durable result storage (read this before sizing)

`EffectLedger` stores effect results inline in `meta` up to `RESULT_INLINE_MAX_BYTES` = **1 MB**.
Its own comment says what happens beyond that:

> *Bigger payloads are summarised only; their replay is REFUSED (never ok-with-null) until the P7
> drivers wire `body_ref` to durable off-row storage — local disk is ephemeral across Cloud workers,
> so a file pointer would lie.*

**A 12-post Instagram actor result will very likely exceed 1 MB.** Measure it before designing:
per `reference_instagram_raw_item_vs_stored_payload`, you can pull real actor results **for free**
from Apify run history (retained ~7 days) — `GET /v2/acts/<actor>/runs?limit=10&desc=1` → each
`defaultDatasetId` → `GET /v2/datasets/<id>/items`. Run it via
`cloud command:run development --cmd="php artisan tinker --execute=\"\$(echo <b64> | base64 -d)\""`
so the `APIFY_TOKEN` stays server-side.

If results exceed 1 MB, `body_ref` → R2 must be built **before** the driver, or every replay is
refused and the charge-once guarantee degrades into charge-again.

### Unit 2 — the actor driver

Implement `runBilledEffect` for `kind='actor'`. Reuse the Apify transport that
`InstagramScraper` already uses — **the transport, not the payload shape.** The connector consumes
raw actor items through `Fields::firstString`, deliberately unlike `InstagramScraper`'s hand-built
12-key projection.

The connector's docblock states what the driver owns:

> *the hard caps live where the money moves: ApifyBudget's per-actor + global daily caps and the
> per-user cooldown (`config partna.limits.apify` / `.platforms.instagram`) are enforced by the
> actor driver*

So: budget caps, cooldown, and a refused-effect verdict folding into `Unavailable` exactly like an
unreachable vendor. Do not put caps in the connector — it only *describes* the effect.

**Actor field drift is load-bearing.** `apify~instagram-profile-scraper` (default) returns camelCase
and *omits* absent keys; `figue~instagram-profile-scraper` returns raw GraphQL snake_case. The actor
is a config-level rollback lever (`config/partna.php:396`), and this drift has already caused one
production bug (`business_category_name`). Read every field through
`Fields::firstString($item, [...])` with all plausible spellings — the connector already does this;
match it.

### Unit 3 — trigger

Instagram never self-schedules. Decide and implement where a run is dispatched from: the dashboard
connect (`InstagramController::connect` → `InstagramConnectJob`), refresh
(`RefreshController.php:187`), and the pre-account build
(`InstagramSourceGenerator` → `InstagramConnectionSeeder`) are the three entry points.

**Do not double-bill.** Those paths already pay Apify once through `InstagramScraper`. Either the
ingest run replaces that scrape or it reuses its result — running both is paying twice for the same
data on every connect. Resolving this is the single most important design decision in this unit.

### Unit 4 — prove it

Verify in the database, not from logs:

```sql
select count(*) from content.items where kind='media';
select storage_path, source_url, fingerprint from content.media_assets order by created_at desc limit 10;
```

`storage_path` **must** be our R2. Instagram's CDN URLs are signed and expire — a hotlinked media
asset is a dead image within days. This is exactly why the owned-bytes policy exists, and why
`InstagramMediaProjector` notes the fingerprint is *"keyed on the shortcode-stable ref, not the
signed URL."*

Real accounts on dev with usable feeds: `jess.hair.stylist` (12 posts, 3 videos),
`simondoylehair` (12 posts, 5 videos). `roberthuntercuts` has 1 post and 0 videos — use it as the
sparse-account case. `crucibletattooco` scraped 0 posts — use it as the empty case.

### Unit 5 — retire the interim lane (only after unit 4 is live)

Once Media pool photos render publicly, the old lane is redundant and its removal is P4 work:
`site.site_media` gallery seeding, the `ig-photo` / `ig-post` / `ig-reel` `ContentSelection` types,
and `InstagramConnectionSeeder`'s photo mirroring.

**Do not start this until unit 4 is live and verified on a real page.** The 2026-08-05 doc's own
sequencing decision applies: *"public sections must never collapse to one item per account, which is
what an early removal causes."*

Also retire `docs/superpowers/specs/2026-08-11-instagram-multi-photo-mirror-design.md`, which
designed a 6-photo fill for the interim `site_media` lane. It is superseded by this work. Its one
durable insight is already present here: content-address by shortcode, never by rank, because a
fixed filename silently swaps the bytes behind a URL a user has picked.

## Ground rules

- **Never create Laravel migrations.** Raw SQL in `supabase/migrations/`, dev first.
- **Logs from the Cloud CLI only**: `cloud env:logs partna development --minutes 15`.
- **Worktree off `origin/development`** (`origin/HEAD` is `production`, months stale). Shared
  checkout — check `git worktree list` first. Never `git stash`.
- **Tests run SQLite, production is Postgres.** A green `composer test` proves nothing about a
  trigger, a CHECK, or a partial index.
- **`composer test` before done**, then Nightwatch.
- **Do not commit or push without Josh's go-ahead.**
- **Never re-bill a scrape to make a test pass.** If the ledger refuses an effect, that is the
  ledger working. Resolve it deliberately (`ingest:effects --resolve`), never by weakening the
  guard.
- **Legal.** Rehosting Instagram media at scale amplifies a set-aside decision (2026-05-31 legal
  review, knowingly parked 2026-07-18). Going from 1 mirrored photo to a full grab-all, rendered as
  page content, is a materially larger version of it. Flag to Josh at unit 0; do not decide it
  yourself.

## Definition of done

1. A billed effect runs, settles once, and appears in `ingest.effects`.
2. A replay of the same request reuses the stored result and does **not** re-bill.
3. `content.items where kind='media'` is populated from a real Instagram account.
4. Their `media_assets.storage_path` values point at our R2 and still resolve after the source CDN
   URL has expired.
5. Selected media renders on the live dev sitepage.
6. `docs/2026-08-05-platforms-as-sources.md` is corrected and updated with what shipped.
