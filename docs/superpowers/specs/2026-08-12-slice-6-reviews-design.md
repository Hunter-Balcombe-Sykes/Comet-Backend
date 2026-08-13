# Slice 6 — Reviews → `content.*` (design)

Part of `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §7.
Kickoff: `docs/superpowers/plans/2026-08-12-slice-6-reviews-KICKOFF-PROMPT.md`.

Branch `feat/slice-6-reviews`, worktree `.worktrees/slice-6-reviews`, cut from
`origin/development` at `4ab37744a`. Slice 1b confirmed merged by ancestry
(`eaa6dafd2`, `131a9e1ef`, `ad3e51908`), not by its checkpoint — invariant #5.

---

## 1. Entry gate — measured on dev (`glncumufgaqcmqhzwrxm`), 2026-08-13

```
content.items WHERE removed_at IS NULL, by kind
  article 1 | channel 7 | episode 110 | event 13 | media 67
  product 51 | release 223 | review 15 | service 18 | video 133

ingest.effects
  actor / instagram      / ok / 2
  api   / places.details / ok / 3

content.f_review                          = 15
content.source_items WHERE kind='review'  = 15
site.platform_connections google-business = 16
core.users                                = 24 active, 10 unclaimed

ingest.sources google_business  = 12 total; 3 have run, 9 never run
  auto_sync = false on all 12; min_interval_secs = 604800 on all 12
  streams on the 3 that ran: profile / reviews / media, all health ok,
  last_run_at 2026-08-12 03:54, 15:49, 15:50

ingest.record_versions (google_business), by stream → projected source_items
  media   40 → 40
  reviews 15 → 15
  profile  3 →  0
```

### 1.1 Five premises in the kickoff prompt are wrong. Dev wins.

1. **`content.f_review` is not 0, it is 15.** Same for `content.source_items`
   kind `review` and `content.items` kind `review`.
2. **`GoogleBusinessReviewProjector` has executed.** The connector's three
   streams share one `$io->effect('api','places.details')` call, so 1b's media
   runs carried reviews with them. Unit 1 is a verification job, not a build.
3. **The pool does not ship "with no payload-builder change."**
   `PoolResolver::itemPayloads()` joins nine facet tables; `f_review` and
   `f_rated` are not among them, and no public lane reads `f_review` at all.
   A registry-only change ships a review card with no rating and no text.
4. **The `profile` stream is vestigial, not unfinished.** 3 record_versions, 0
   source_items. `ProjectorRegistry`'s docblock defers profile_fields to
   "field bindings (plan §14)" — a lane that was built
   (`20260728150000_field_bindings.sql`) and deliberately dropped
   (`20260805110000_drop_field_bindings.sql`) in the 2026-08-05 audit wave
   because it "never gained a production caller". The docblock now points at
   nothing. The identity fold it was meant to serve happens instead via
   `IdentitySync` off `IntegrationConnectionObserver::saved` on the legacy
   connection payload, which is why nothing consumes the stream's records.
5. **`reviewSummary` is not the only companion.** The legacy payload couples
   four keys behind one owner toggle (`DisplaySettingsFilter:33`): `reviews`,
   `reviewSummary`, `rating`, `reviewCount`. Only the first has a `content.*`
   home today.

### 1.2 Verification hazard

Effect replay means a source that ran inside the 7-day freshness window
(`partna.ingest.effect_freshness_seconds`) returns the cached Details payload
without calling the driver. Live verification of any mapping change uses one of
the **9 never-run sources**. Re-running a recent source proves nothing.

---

## 2. The PII contract

`content.f_review` holds `author_name`, `author_photo_url`, `rating`, `text`,
`reviewed_at`, PK `(item_id, source_id)`.

### 2.1 `when_unclaimed` redaction holds — measured

| user status | rows | author_name | author_photo | text | rating |
|---|---|---|---|---|---|
| `active` | 5 | 5 | 5 | 5 | 5 |
| `unclaimed` | 10 | 0 | 0 | 10 | 10 |

Redaction is applied **at landing**, not at projection: `RunExecutor:146`
resolves `Manifest::redactionsFor($pull->isClaimed)` and passes it to the
`Lander`, which writes `ingest.record_versions`. The stored doc for an
unclaimed account is permanently redacted.

**Consequence, and it is the real contract:** claiming does not restore
attribution. Re-projection re-reads the redacted doc. Attribution appears only
after a fresh billed fetch past the freshness window — and `auto_sync` is off
on all 12 sources. This fails closed and is correct, but it is not what
"a claim transition behaves correctly" intuitively suggests, so it is asserted
explicitly rather than assumed.

### 2.2 Defect: reviewer names exist in three tables, two of them ungoverned

`GoogleBusinessReviewProjector:34` sets `headline = $author ?? 'Google review'`.
`ProjectionWriter:949-951` folds any non-empty headline into the `f_text`
facet; `:1459-1479` resolves it back into `content.items.headline_cache`.

| copy | redaction | `PruneOrphanedReviewPiiCommand` | DSAR |
|---|---|---|---|
| `f_review.author_name` | yes | deletes | omitted by `streamContentFReview` |
| `items.headline_cache` | no | **no** | **exported** (`:482`) |
| `f_text.headline` | no | **no** | **exported** (`streamContentFText`) |

Measured on dev: 5/5 claimed review items carry the reviewer's display name in
all three. Unclaimed carry the literal `'Google review'` in the latter two, so
there is no unclaimed leak — this is a claimed-account disclosure and
retention defect.

Two documented guarantees are therefore untrue today:

- `DsarPayloadFilter::WITHHELD_DISCLOSURE` and the comment at
  `DataExportPayloadBuilder:237-242` claim the export never discloses reviewer
  identity.
- `PruneOrphanedReviewPiiCommand`'s docblock claims it hard-deletes
  `author_name`, `author_photo_url` and verbatim text.

`KindRegistry` independently corroborates the fix: it declares `review`'s
facets as `['f_review','f_rated','f_published']` — no `f_text`. The writer
produces an `f_text` row the kind registry says reviews do not have.

### 2.3 The fix has two parts, and part two is not optional

**Part one — stop writing it.** `'headline' => null` in the projector, matching
`GoogleBusinessMediaProjector:44`, which 1b set null by contract. With no
headline, no `f_text` contribution is folded, and `headline_cache` resolves
null. Both extra copies stop at source.

**Part two — clean up what exists.** `upsertSingletonFacet` is upsert-only; it
never deletes. The 5 existing `f_text` rows survive the projector change, and
because `headline_cache` is resolved *from* those rows, the reviewer's name
keeps being served. A one-off cleanup deletes `f_text` rows for `review`-kind
items, nulls the affected `headline_cache`, and bumps all three cache lanes
(`BuildState::bump`, `site.sites.updated_at`, `CloudflareCachePurgeJob`) — the
same discipline the prune command already applies, for the same reason.

### 2.4 `author_uri`

Owner decision 2026-08-13: full wire parity. A migration adds `author_uri` to
`content.f_review`. The connector already declares `author_uri` as
`when_unclaimed`, so it inherits redaction with no manifest change. It joins
the DSAR omission list beside `author_name`, `author_photo_url` and `text`.

---

## 3. Unit 1 — verify the ingest lane

Reviews already land. This unit proves the contract rather than building it.

- Redaction asserted in both directions, and across a claim transition against
  the §2.1 contract.
- `PruneOrphanedReviewPiiCommand` exercised against real rows for the first
  time; docblock corrected to describe what it deletes after §2.3 lands.
- `author_uri` migration + projector mapping.
- Reviews stay `SourceProfile::Sample`: `orderField` null, `mayDelete()` false,
  no `Covered` message. Not "improved" into an exhaustive stream.

No new billed calls. The effect digest stays `['place_id' => …]`; adding an
input key would double the Details bill for every user.

---

## 4. Unit 2 — the reviews pool

Owner decision 2026-08-12: reviews get a pool.

### 4.1 Registry

`POOLS['reviews'] = ['review']`, `PAGE_KEYS`/`PAGE_LABELS` → `reviews`/
`Reviews`, and its own `SECTION_SHAPE`: `[['op' => 'kind_is']]`, `order_by`
`recency`.

- **Not** in `LATEST_TAG_POOLS`. A "latest review" tag would misrepresent a
  vendor-curated sample as a chronology.
- **Not** on the default shape. The default's `latest_per_auto_source` emits
  one item per source, which for a five-review sample means one review visible
  and four hidden — the pathology media and events each hit.

### 4.2 Wire shape

`PoolResolver::itemPayloads()` gains a nested `review` block —
`{rating, text, authorName, authorPhotoUrl, authorUri, reviewedAt}` — null off
every non-review kind, following the `price` precedent and the "wire shape does
not change with kind" contract `startsAt`/`venue`/`frames` already keep.

This is what makes the null headline safe. Attribution comes from `f_review`,
which redaction, pruning and DSAR all govern, instead of from a derived cache
that nothing governs.

### 4.3 Curation — exclusion only, no pinning

Owner decision 2026-08-13. Curation state is `pinned` | `excluded`
(`PoolResolver:57-68`). The restriction is declared once in `PoolRegistry`
(`EXCLUDE_ONLY_POOLS`) so the resolver and the write endpoint read one source
of truth; a pin request for a review item is refused 422.

Recorded for the legal review: selective removal is what the draft privacy
clause's "genuine, attributable feedback" wording leans on. §6.3 carries the
disclosure obligation this creates.

### 4.4 Two gaps not named in the kickoff

**The owner's own toggle does not reach the pool.** `DisplaySettingsFilter` is
applied only in `PublicIntegrationConnectionResource` and the GB dashboard
paths. `buildPools()` calls `PoolResolver::resolve()` with no gate, so shipping
the pool as-is republishes reviews for an owner who switched them **off**.
Review items are filtered by their originating connection's suppression, keyed
per-source rather than per-pool so it stays correct if a second review platform
lands.

**Manual authoring must be refused.** Nothing currently stops an owner creating
an item of kind `review` through `PoolItemCreateController` — fabricating a
testimonial attributed to a customer. The endpoint refuses `review`.

### 4.5 Provisioning

Sections provisioned for existing users with a `google_business` source, same
pattern as prior slices.

---

## 5. Unit 3 — full retirement

Owner decision 2026-08-13: bring the aggregates into `content.*` rather than
run both lanes.

### 5.1 `content.source_stats`

New table, PK `source_id` (FK → `content.sources`, CASCADE), columns
`rating_avg`, `rating_count`, `summary_text`, `updated_at`, all nullable. The
first source-level fact in `content.*`.

### 5.2 How it is written

`GoogleBusinessConnector::reviewsMessages()` emits the aggregates alongside its
review records — `rating`, `review_count`, `review_summary`, all present in the
same `$place` payload, so no extra billed call. `ProjectionWriter` gains a
source-scoped write path that lands them on `source_stats`.

**Revised 2026-08-13, and the reason matters.** The aggregates describe the
place, so the `profile` stream is their natural subject and this spec first
put the writer there. That was wrong: per §1.1 #4 the profile stream is
vestigial — its intended consumer was deliberately deleted, and the identity
fold it existed for runs elsewhere. Slice 7 is the teardown slice, and a
reasonable teardown reads "profile stream has no consumer, remove it". Hanging
the public wire's rating and review count off it would put a live dependency
on machinery already scheduled for plausible deletion.

The reviews stream is the one this slice exists to keep alive, so the writer
goes there. The cost is conceptual: a `Sample` stream emits a source-level fact
about the place rather than about its records. That is the cheaper mistake.

Deliberately NOT done: landing the place as a `channel`-kind item. It would use
the existing pipeline with no new seam, but deciding a connected place *is an
item* binds seven other Identity connectors and belongs to a wider decision.

### 5.3 Redaction, mirroring the legacy precedent

`GoogleBusinessPayload::stripThirdPartyPii()` removes `reviews` only; its
docblock states `rating`/`reviewCount` are left untouched, and `reviewSummary`
is untouched too. So:

- `rating_avg`, `rating_count` — no redaction. Aggregates are business facts.
- `summary_text` — no pre-claim redaction, matching the legacy path. It stays
  withheld from DSAR.

That asymmetry (not reviewer PII enough to strip pre-claim, third-party enough
to withhold from the subject's export) already exists. It is mirrored, not
resolved. Resolving an equivalent asymmetry by making lanes agree is the
mistake `ThirdPartyPii`'s corrected docblock records.

### 5.4 What retires, and what does not

- `PublicIntegrationConnectionResource:185` drops `reviews`, `reviewSummary`,
  `rating`, `reviewCount` from the `google-business` allowlist. The public wire
  serves them from the pool and `source_stats`.
- The **fetch is unchanged**. `GoogleBusinessService` keeps populating the
  payload, so the dashboard resource keeps working and nothing about billing
  moves. This retires a read, not a fetch.
- `DsarPayloadFilter::THIRD_PARTY_KEYS` **keeps** all four keys — the
  2026-08-05 precedent is that allowlists retain legacy keys so already-stored
  payloads stay disclosable.
- `BackfillClaimedGoogleBusinessReviewsCommand` becomes vestigial. Noted for
  slice 7; not deleted here.

### 5.5 DSAR

`source_stats` joins the export with `summary_text` omitted, matching how
`f_review` omits author fields. `WITHHELD_DISCLOSURE` names the aggregate
summary beside the existing keys.

---

## 6. Testing, cache, legal

### 6.1 Tests

- Redaction both directions; claim transition against the §2.1 contract.
- Prune command against real rows, and that it now leaves nothing behind.
- §2.3 cleanup asserted to actually empty `f_text` and `headline_cache`.
- Pool: exclusion allowed, pin refused, display-toggle gate honoured, manual
  `review` creation refused, `PoolRegistryTest` for the new entries.
- DSAR: no reviewer name reachable through any exported section.

`composer test:pg` runs because this changes `ProjectionWriter`. CLAUDE.md
requires it, and a green SQLite run said nothing when 5a turned that lane red.

### 6.2 Cache

Three lanes, no CI check enforces them: `BuildState::bump($siteId)`, touch
`site.sites.updated_at`, dispatch `CloudflareCachePurgeJob`. Applies to the
§2.3 cleanup and to any raw-SQL write path added here.

### 6.3 Legal — LEGAL-2

This slice does **not** discharge LEGAL-2. It inherits it and adds to it:

1. `docs/legal/reviewer-data-disclosure.md` §1 must say reviewer data reaches
   the public wire via the pool lane, not `GoogleBusinessService.php:304-311`.
2. §3's draft clause must disclose that the professional can suppress
   individual reviews (§4.3). The clause's "genuine, attributable feedback"
   justification is weakened by selective removal; that is for the adviser.
3. §2's "not published pre-claim" mitigation remains true — verified in §2.1.

---

## 7. Propagation, before merge

| Change | Update |
|---|---|
| Five corrected premises; vestigial profile stream; new source-level seam | Parent spec §1, §5, revision note |
| Profile stream is redundant with the `IdentitySync` fold — retire or justify | `slice-7-teardown` |
| `content.source_stats`, prune dependency, DSAR sections | `slice-7-teardown` |
| `PoolRegistry`, `PoolResolver` | `slice-5b`, `slice-4-menus` |
| Public wire + owner exclusion | `docs/legal/reviewer-data-disclosure.md`, wire manifest |
| Stale entry gate | This slice's own kickoff prompt, in place |

Migration prefixes from the assigned block: `20260813110000` (`author_uri`),
`20260813110001` (`source_stats`).

**5b collision is certain**, on both `PoolRegistry` and `PoolResolver` (5b adds
`shop` to the same four const arrays and 309 lines to `itemPayloads()`).
Whoever merges second re-runs `PoolRegistryTest` **after** resolving — a merge
that drops half a const array still passes every test written by the branch
that added the other half.

---

## 8. Out of scope

- Rebuilding the field-binding lane, or retiring the now-redundant `profile`
  stream. Both recorded as parent-spec findings for slice 7 (§7).
- Generalising orphan pruning beyond `f_review` — the prune command's docblock
  already files this as a correctness concern, not a PII one.
- A reviewer takedown path. `docs/legal/reviewer-data-disclosure.md` §4 point 3
  records that none exists. Owner suppression (§4.3 above) is not the same
  thing: it lets the professional hide a review, not the reviewer.
- Production. Dev only, per `e4d15870a`.
