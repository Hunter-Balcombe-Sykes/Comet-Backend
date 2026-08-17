# Execute prompt — the fourteen P2s from the programme review (2026-08-17)

Paste everything between the fences into a fresh session.

Audit file: `audits/consolidation/2026-08-17-programme-review/CONSOLIDATED.md`
(35 findings; this prompt covers the fourteen P2s, `PGR-7` … `PGR-20`).

**Run the P1 prompt first if it has not run.** `2026-08-17-programme-review-P1-EXECUTE-PROMPT.md`
Unit A builds the shared three-lane cache helper that `PGR-11` here should reuse
rather than reinvent. Running P2 first is allowed but means writing that
invalidation by hand twice.

Grouping below is by SUBSYSTEM, not by source run — the P2s scatter across six
runs but collapse into seven units, and several only make sense reviewed together.

---

```
Rename this session to audit-fix-p2.

Execute the P2 findings in audits/consolidation/2026-08-17-programme-review/CONSOLIDATED.md,
following scripts/audit/fix-flow.md. Branch audit-fix/programme-review-p2-2026-08-17
off development. Fourteen findings: PGR-7 … PGR-20.

fix-flow's rule zero applies: verify each premise before fixing it. This review
disproved one adjudicated P1 outright and materially restated another, both by
reading the code the finding cited. Do the same here — and if a premise does not
survive, close it WONTFIX with the reason rather than inventing work.

SETUP.
Your OWN git worktree: `git worktree add`, then `cp -a <main>/vendor ./vendor` (a
SYMLINKED vendor makes Pest's ->in('Feature') binding resolve to the main
checkout, the app never boots, and you get ~1100 fake failures) and symlink .env.
Check `git worktree list` first.

⚠️ BASELINE IS RED AND IT IS NOT YOURS. `development` fails six shop
contract/golden-master tests from `feature/store-rows-auto-latest`
(PlatformResourceContractTest ×2, ShopAsyncConnectTest T1/T5,
ShopSelectionLockTest T16c, IntegrationContractGoldenMasterTest). All six fail on
one added line, `+ "autoLatest": false` — ShopBrandResource:42 gained a field
without its exact-JSON contracts being updated. RECORD them at baseline and do
not chase them. Exactly those six and nothing else afterwards = green. Do not
regenerate the golden master: that asserts the new shape is intended, the field
has no wire manifest, and it is not yours.

IDS. The six source runs used overlapping id spaces (#TEST-1 named four different
findings, #CFG-1 four more). Ids are renumbered #PGR-n; each records
"Source: <run> — was #<original>". Tick by matching finding TEXT, never by id.

UNITS — sequential, in this order.

UNIT E — the PII sanitiser bypasses: PGR-18 + PGR-19 + PGR-20  (do together)
  Three findings, one shape: a raw third-party value is persisted on a PUBLIC
  write path while the codebase's own PII-minimising sanitiser sits unused
  beside it. UTM params (PGR-18), User-Agent on three public form paths
  (PGR-19), User-Agent on the analytics ingest hot path (PGR-20).
  - RECOMMENDED SIGN-OFF before implementing. This is not a fix-flow hard gate
    (not auth, not money, not a migration) but it is PII on public write paths
    and it touches the highest-traffic endpoint in the app. Present the plan.
  - Check them against the existing open privacy items before writing anything —
    LEGAL-2 and PRIV-2 are both open and may already own part of this surface.
    Do not create a second, disagreeing answer to the same question.
  - PGR-20 is on the highest-traffic public write path. Sanitising per request
    has a cost; measure it rather than assuming it is free, and keep the
    analytics fail-open posture intact (a sanitiser that throws must not take
    the endpoint down).

UNIT F — the handle / subdomain / edge lane: PGR-11 + PGR-17  (do together)
  Same subsystem, and the pair wants one reviewer holding both.
  - PGR-11: ConvergeSiteSubdomainsCommand commits the raw subdomain rename
    BEFORE cache and KV invalidation. Same shape as the P1 prompt's Unit B —
    a destructive write committed ahead of its invalidations, no transaction.
  - 🔴 KV IS SHARED BETWEEN DEV AND PRODUCTION. Verified live 2026-07-26 and
    still open: both envs resolve CLOUDFLARE_KV_NAMESPACE_ID =
    ce726607804d41a296d6da150b0c537f. A dev-side subdomain write can clobber a
    prod <handle>.partna.au route. Prod has zero users so there is no live
    impact TODAY, but do not exercise this command against real KV to "test"
    it, and do not treat dev KV as isolated. SyncSubdomainToKvJob is the ONLY
    permitted KV writer — do not add a second one.
  - PGR-17: alias 301s cache for 5 minutes, so a rapid handle reclaim can
    briefly misdirect. Read the handle lifecycle first (docs/handle-redirects.md,
    config('partna.handle.*')): aliases carry reclaim_until (+14d) and expires_at
    (+90d) and KV alias entries carry expirationTtl. The question is whether 5
    minutes is wrong or whether reclaim should purge — decide which, do not
    just lower a number.
  - If the P1 session has landed, reuse its shared three-lane helper here rather
    than hand-rolling the invalidation a second time.

UNIT G — backfill/command scaling: PGR-8 + PGR-9 + PGR-10  (do together)
  One class of problem across three commands: unbounded streaming and per-row
  work that does not survive a real backlog. PGR-8 cursor() + an N+1 existence
  check per item; PGR-9 unbounded cursor() with inline per-row R2/image work;
  PGR-10 materialises every doomed asset id before chunking the deletes.
  - These are DATA commands. Every one must stay idempotent and re-runnable —
    that is a programme invariant (#4), not a nicety.
  - PGR-9 touches R2 and image work per row. Do not trigger a real fleet-wide
    run to test it; prove the shape with --dry-run and tests.
  - Preserve --dry-run semantics exactly. A dry run must write NOTHING —
    §18.4 records a fix round where ensure() INSERTed under --dry-run.

UNIT H — the two missing Postgres-lane concurrency tests: PGR-7 + PGR-12
  Both are "a documented race has no test that drives it". Same lane, same
  idiom, one unit.
  - PGR-7: ProjectionWriter::upsertSourceItem()'s SELECT-then-insertOrIgnore is
    deliberately non-atomic (its own comment says so), and
    PoolItemCreateController now derives the coord from the URL — so a
    double-clicked "Add" is two requests writing the SAME coord, and the loser
    of source_items_coord_unique would surface as a 500. Assert: exactly one
    row for the coord, both callers get the same item id, no uncaught 23505.
  - PGR-12: the enquiry-notification SKIP LOCKED contract has no Postgres-lane
    test, unlike the analogous claim-vs-prune race which does.
  - Follow the existing idiom — EffectLedgerConcurrencyTest,
    ProjectionIdentityKeyAtomicityTest, SourceSchedulerConcurrencyTest. SQLite
    CANNOT exercise either: it has no independently-committing second
    connection.
  - ⚠️ tests/Postgres/ treats core.users and site.sites as SHARED fixtures
    created with CREATE TABLE IF NOT EXISTS and never dropped. WHICHEVER FILE
    RUNS FIRST DECIDES THE TABLE SHAPE, and a later file's own CREATE IF NOT
    EXISTS is a silent no-op. If you add a new file that creates a minimal
    site.sites, you will break SubdomainAliasCollisionTest with
    42703 "column subdomain does not exist" and it will look unrelated to you.
  - A concurrency test must PIN THE REFUSAL REASON, not just that something was
    refused — a 404/23505 for the wrong reason passes a sloppy assertion.

UNIT I — the two SQLite-blind guards: PGR-14 + PGR-15  (do together)
  Both are "a real-Postgres-only failure mode with no tripwire".
  - PGR-14: ServiceCollections/MenuCollections::normalizeRow() coerces driver
    values, and nothing feeds it driver-shaped input. This is the asymmetry
    §19.6 called "a defect generator": PDO_PGSQL returns booleans as the
    STRINGS "t"/"f" — and "f" IS TRUTHY — and ints as numeric strings, while
    SQLite hands back native types. A regression would misclassify every
    scraper-owned category as owner-editable, on production only, suite green.
    Construct a raw \stdClass with 't'/'f' and numeric strings; assert native
    bool/int out.
  - PGR-15: no query-surface guard for MenuItem / MenuCategory / MenuItemPlatform,
    whose tables slice 7 dropped while deliberately KEEPING the models as DTO
    carriers. LegacyServiceQuerySurfaceTest guards only Service/ServiceCategory.
    Extend it (or add a sibling) with both its cases — the string sweep AND the
    dynamic-list case — and KEEP its positive control, or the negated
    assertions go vacuous.
  - DO NOT "tidy away" the three models. They are hydrated unpersisted
    (exists = false) by ManualMenuItems for the dashboard shape; deleting them
    breaks the surviving content lane. CLAUDE.md records this.
  - Decide Menu::categories() / Menu::items() explicitly: live relation methods
    on a SURVIVING model (site.menus) pointing at DROPPED tables. Delete them or
    annotate them unusable — do not leave them undocumented.

UNIT J — PGR-13, the link-card read/write split  (small, standalone)
  LinkPoolWriter::add() began writing cover/logo item_media rows on 2026-08-17
  and PoolResolver reads them onto the public payload, but
  LinkPoolReader::cardsInSection() still hardcodes favicon/logo to null (:95-96).
  Public page shows the image; the owner's dashboard shows blank.
  - The two docblocks now CONTRADICT each other — the reader says "deliberately
    not carried onto the pool", LinkPoolWriter:35 says "favicon and logo ARE
    carried (2026-08-17, reversing Phase 3's 'not carried')". Fix both the code
    and the stale docblock; leaving the comment is how the next reader
    re-introduces it.
  - This is same-day work from the link-card-media change and may still be owned
    by another session. Check `git log` on those two files and `git worktree list`
    before editing.

UNIT K — PGR-16, the email_lc index  (XS, standalone)
  Email subscription lookup ignores the existing indexed email_lc column.
  Combine plan+implement per the file's execution policy. Confirm the index
  actually exists and covers the predicate before changing the query — verify
  against supabase/migrations/ DDL, not an assumption.

LANES — run all, and know the traps.
  COMPOSER_PROCESS_TIMEOUT=0 composer test        (serial; the suite exceeds
                                                   composer's 300s default and
                                                   the timeout presents as a hang)
  ./vendor/bin/pest --parallel --processes=4      (paratest takes at most ONE path)
  composer test:pg                                (MANDATORY for units H, and for
                                                   anything touching
                                                   ProjectionWriter. Throwaway
                                                   postgres:16 — the local .env
                                                   DB_HOST is a dead ref.
                                                   DB_DATABASE=partna_test,
                                                   DB_SSLMODE=disable,
                                                   PG_LANE_REQUIRED=1)
  composer test:schema                            (fresh db + every migration;
                                                   SCHEMA_LANE_REQUIRED=1 or it
                                                   skips and reads green.
                                                   QueryPlanTest on
                                                   analytics.site_visits is a
                                                   known AUTOANALYZE flake —
                                                   check pg_stat_all_tables
                                                   .last_autoanalyze first)
  composer test:authz                             (AUTHZ_LANE_REQUIRED=1 — without
                                                   it "31 skipped" reads green
                                                   and tests nothing)
  php -d memory_limit=1G ./vendor/bin/phpstan analyse app --no-progress
  ./vendor/bin/pint

RULES.
  - Units sequential. Plan → implement → INDEPENDENT review (separate instance,
    never the implementer) per unit. Models come from the audit file's
    ## Execution policy — read them from the file, do not substitute. Combine
    plan+impl only for S/XS units (J and K qualify; E, F, G, H, I do not).
  - A box goes to [x] only after tests pass AND the independent review says PASS.
  - Never auto-merge or push to a shared branch; work stays on the audit-fix branch.
  - Tests run SQLite, production is Postgres. Units H and I exist BECAUSE of that
    gap — do not accept a green SQLite run as evidence for either.
  - A premise that does not survive verification closes WONTFIX with its reason.

WHEN DONE. Report ticked / WONTFIX with reasons, and hand the 15 P3s back
untouched — they are not in scope, and CLAUDE.md's rule is that the P3 tail gets
absorbed opportunistically when a file is already open, never run as a campaign.
```
