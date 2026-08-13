# Decisions needed before execution — 2026-08-14 morning

Everything below blocks or reshapes a phase. Ordered by how much it changes.
Evidence for each is in `convergence-log.md` (finding refs in brackets).

---

## D1 — Is `jhunter7333` you, or someone else? **[blocks everything]**

10 commits landed 00:33–01:54 (planning ran to ~03:30), in
`ProjectionWriter` and `SourceProvisioner` — two of the four files Phase 2
touches. Their work is complementary (services hardening + a useful Fresha
`book-now` provisioning fix), not contradictory.

- **If it's you:** stop that session, then this one executes.
- **If it's someone else:** carve lanes — suggested, this run takes ingest
  identity keys + menu/links pools + teardown; they keep services.

Two unattended sessions on the same ingest files is the one thing that would
reliably corrupt this run.

---

## D2 — ISRC/GTIN: extract them, or accept title-matching? **[reshapes Phase 2 + 4]**

`content.f_catalog` has the columns and **zero data**: `isrc` 0, `gtin` 0. No
connector extracts either. `FeedGuid`/`EnclosureUrl` likewise absent. [F10]

So of the Joining tier only `ContentDigest` is emittable (914 fingerprints,
716 distinct — real cross-source duplicates to unify). Music dedup would fall
back to `TitleRelease`/`TitleOnly`, which are corroborating tier: cross-source
only, correct but weaker.

- **(a) Accept title-matching for now.** Phase 2 stays small. Spotify↔SoundCloud
  ↔YouTube-Music dedup works on title+release date.
- **(b) Extract ISRC/GTIN as part of this run.** Connector work on Apple Music,
  Bandcamp, Gumroad, plus the new Spotify/SoundCloud actors. Expands Phase 2
  and Phase 4 meaningfully, but gives genuine joining-tier identity for music.

**My recommendation: (a) now, with a Phase 4 constraint — when choosing the
Spotify/SoundCloud Apify actors, prefer one that returns ISRC.** That gets the
strong key where it matters most without retrofitting five connectors.

---

## D3 — Twitch `vods` is being deleted; confirm that's still right

Decision 12 de-sourced Twitch and said not to re-investigate, so I haven't.
Flagging only because Phase 1 deletes `TwitchVodProjector`, which targets
`video` — a real Watch-pool producer, not just the channel card.

It has **never succeeded** (`unavailable` on all 4 sources), and the connector's
own docblock says missing credentials degrade to exactly that — so it may be
unconfigured rather than broken. Deleting it is still defensible; I just don't
want it deleted on the assumption it was inherently broken when it may only
have been missing `TWITCH_CLIENT_ID`/`SECRET`.

**Default if you don't answer: delete as planned.**

---

## D4 — Legacy event slugs: delete in Phase 7?

Events dual-write slugs. Slice 2 copied them into `content.item_slugs` (16
rows) without removing the legacy ones (11 remain, 9 of which collide by
`(user_id, slug)`). [F11]

Harmless today, but it's exactly the "two stores, one truth" pattern this
programme exists to remove.

**Recommendation: yes — Phase 7 deletes the 11 legacy event slugs.**

---

## D5 — `commerce_probe` fix: confirm the constraint value

Phase 1 adds `'commerce_probe'` to `source_intents_origin_check`. [F5]

Worth one moment of thought: is `commerce_probe` the right *name* to bless
permanently in a DB constraint, or should the job use an existing origin
(`reproject`? `paste`?) instead? I'm adding the new value because the job's
own constant already says `commerce_probe` and renaming it would touch more.

**Default: add `commerce_probe` to the constraint.**

---

## Already decided — recorded, no action needed

- **No documents pool.** `document` kind stays declared and unpooled;
  `site.site_media` pool='documents' stays as-is. Documents have no platform
  source at all — upload-only. [F8]
- **Kind retirement narrows `KindRegistry`, not the DB CHECK domain.** The
  parity guard reads two migration files by name, so a narrowing migration
  would not be seen by it while its hardcoded 14-value list went stale.
  Nothing binds `KindRegistry` to the DB domain. [F9]
- **`channel` kind retires in Phase 4, not Phase 1** — Spotify and SoundCloud
  still produce it until they convert to `track`. `ChannelCardProjector` still
  dies in Phase 1 (its only consumers are the three demoted platforms).
- **`profile_fields` seam deleted, `IdentitySync`/`site.workplaces` kept** —
  they are the identity implementation and store, not legacy.
- **Phase 3 pools need no migration** — `PoolSectionProvisioner` is lazy and
  there is no CHECK on `site.pages.key`/`sections.key`. [F12]
- **Phase 3's 318-slug migration is collision-free** — verified against live
  data. [F11]
- **Phase 7's dump gate works** — 318/44/402 exact. [F3]
- **`LegacyServiceSortOrder` dies in Phase 7** with `site.services`. It fixes a
  real bug today, so it is not wasted — but it must be deleted, not ported.

---

## Where the plan stands

`feat/platform-pool-convergence`, rebased, **no code changed**.

- `2026-08-14-convergence-RUNBOOK.md` — conventions, constraints, traps, baseline
- `2026-08-14-convergence-phases.md` — Phases 1–2 executable, 3–8 at decision level
- `convergence-log.md` — 13 findings, corrections, capability baseline
- `2026-08-14-platform-and-pool-convergence-scope.md` — the verified spec

Answer D1 (and ideally D2), and execution can start.
