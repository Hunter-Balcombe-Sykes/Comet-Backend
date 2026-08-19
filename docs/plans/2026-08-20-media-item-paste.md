# Media-item paste: watch/listen get the events treatment (paste lane only)

Owner ask (2026-08-20): individual video/track links pasted into the Watch
and Listen pools become REAL items (title, thumbnail, right kind), account
URLs get the connect hint — the same item/account discipline events got on
2026-08-19. Scan lanes explicitly deferred to a follow-up run (owner:
"then we will go to scan lanes next").

## Grounding facts (read, not assumed)

- Pool kinds: watch=[video], listen=[track, release, episode]
  (PoolRegistry::POOLS).
- Identity folding is canonical-URL equality, lowercased and nothing else
  (KeyClass::canonicalise → strtolower; IdentityKeyDeriver::joining reads
  f_link.url). So the pasted item folds into its synced twin ONLY when the
  reader emits the connector's exact URL form:
  - youtube: `https://www.youtube.com/watch?v={id}` (YoutubeFeed.php:100)
  - youtube-music: `https://music.youtube.com/watch?v={id}`
  - vimeo: `https://vimeo.com/{id}` (VimeoConnector.php:137)
  - spotify/soundcloud: the open.spotify.com/soundcloud.com URL minus
    tracking query (connectors store the doc url; spotify already reads
    its own oEmbed — precedent).
- writeManualItem() derives identity keys and may return an EXISTING item
  (PoolItemCreateController's own docs) — the fold needs no new machinery,
  only the right f_link.url.

## Build

1. `app/Services/Platforms/MediaPageReader.php` (extends PlatformScraper):
   - Pure grammar per platform: item shapes → {kind, canonical}, account
     shapes → label. youtube watch/shorts/live/youtu.be/embed → video @
     canonical watch?v=; /@x,/channel/,/c/,/user/ → account. vimeo digits →
     video; name → account. twitch /videos/{id} + clips → video; bare
     /{name} → account. spotify /track|/album|/episode → track|release|
     episode; /artist|/show|/user → account. soundcloud two segments →
     track; one → account. music.youtube.com watch → track. mixcloud
     /{user}/{slug} → episode-ish… mixcloud shows are 'episode'? → track
     (audio item; roster says mixcloud in listen; kind 'track').
   - `read(url)`: oEmbed first (youtube, vimeo, spotify, soundcloud,
     mixcloud endpoints), OG-tag fallback (twitch, apple-music, bandcamp,
     tidal) → {canonical, kind, title, thumbnail}.
   - `accountPlatformLabel(url)`: pure, for the 422 hint.
2. PoolItemCreateController ITEM-FIRST arm for watch/listen, implemented as
   input enrichment over the EXISTING card write (not a parallel writer):
   account URL → 422 connect hint; wrong-pool item (spotify track into
   Watch) → 422 pointing at the right pool; recognised item → coord/url
   switch to canonical, kind from grammar, title/cover default to the
   page's own (owner's checked words still win). No markup/oEmbed → card
   path byte-identical.
3. Tests (transport-mocked): rich add per platform, fold-into-synced-twin
   (paste youtu.be/X beside synced watch?v=X → ONE item), account 422,
   wrong-pool 422, unknown-host card fallback.
4. Full suite → deploy → live verification with real URLs (local + remote
   tinker), same as events.

## Out of scope (next run)

Scan lanes (LinkRouter/LinkInBioImporter/auto-syncs seeding media items),
classify() content-item category, MediaSeeder, per-run caps, Instagram
media items.

## Ledger

- [ ] Reader + grammar
- [ ] Controller arm
- [ ] Tests green (targeted + full suite)
- [ ] Deployed + live-verified
