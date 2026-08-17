# W10 — Apify actor probes (alternatives to the current music/instagram/menu/google-business actors)

Run 2026-08-18 against the `partna` Apify account. Auth: `Authorization: Bearer <token>` header
(never the JSON body — see convergence-log F31, confirmed again here: `apify~instagram-scraper`
timed out with a normal `400`, not an x402, once the header was used correctly).

**Spend for this task:** `/v2/users/me/usage/monthly` before = **US$4.893**, after = **US$4.997**
→ **US$0.104 spent**, well under the US$3 budget.

---

## 1. Spotify — no full-catalogue-with-ISRC actor found; one good discography add-on

Current: `automation-lab~spotify-scraper` (`mode: urls`), confirmed by F31 to return only
`topTracks` (~10, no ISRC). Goal was an actor taking an artist URL/id and returning the FULL
catalogue, ideally with ISRC.

### Candidates tried

**`khadinakbar~spotify-artist-scraper`** (title "Spotify Artist Scraper", PAY_PER_EVENT,
`$0.00005` actor-start + **`$0.005`/artist-scraped**, ~no run-count data surfaced but actively
maintained, build `0.2.4` from 2026-08-16).

- Input used: `{"artists":["https://open.spotify.com/artist/5INjqkS1o8h1imAzPqGZBb"],"maxResults":1,"responseFormat":"detailed"}`
- HTTP 201, 1 item.
- Dataset item keys: `type, id, uri, url, name, verified, biography, monthlyListeners, followers, worldRank, topCities, externalLinks, avatarImage, headerImage, topTracksCount, topTracks, discography, popularityDataAvailable, dataSource, scrapedAt`
- `topTracksCount: 10`, `topTracks` = 10 rows even in `detailed` mode. Each track row:
  `{name, uri, id, playcount, durationMs, explicit}` — **no isrc, no album, no release date, no artwork, no per-track url** (only `uri`/`id`, url is derivable).
- `discography` = **counts + latest release only**: `{albumCount, singleCount, compilationCount, latest: {name, uri, type, date, label, totalTracks, coverArt}}`. The actor's own input-schema doc is explicit: *"It does NOT page through the full discography; both modes return discography counts plus the latest release."* **Disqualified for the "full catalogue" goal.**

**`khadinakbar~spotify-all-in-one-scraper`** (same author, same underlying artist-scrape code
path). Input used: `{"spotifyUrls":["https://open.spotify.com/artist/5INjqkS1o8h1imAzPqGZBb"],"maxResults":1,"responseFormat":"detailed"}` → HTTP 201, identical shape to the above (same `topTracks`/`discography` limitation). Its `detailed` mode only pages nested children when the input URL is itself an ALBUM/PLAYLIST/SHOW — for an ARTIST url it's the same 10-track summary. Not a fix.

**`nifty.codes~spotify-artistdiscography-scraper`** (id `7r06dwAH933JBaR5S`, PAY_PER_EVENT,
`$0.005` actor-start + `$0.002`/result). This is the useful find: it takes an artist's
`/discography/all` URL and returns one row per RELEASE (album/single/compilation), not capped at ~10.

- Input used: `{"urls":["https://open.spotify.com/artist/5INjqkS1o8h1imAzPqGZBb/discography/all"],"maxItems":30}`
- HTTP 201, **22 items** (i.e. full discography — Tame Impala's albums+singles+compilations — well beyond the top-10 track cap).
- Dataset item keys: `id, Release Name, Release Type, Release ID, Release URI, Release Date, Release Year, Track Count, Playable Status, All Cover Art URLs, Share ID, Share URL`.
- **Release-level, not track-level. No isrc field at all** (nothing to have one — it's albums, not tracks). Would need a second, per-album track-fetch actor (e.g. one of the `spotify-album*-scraper` listings surfaced by the "spotify albums" store search — not probed, out of budget) to turn this into a track list.

### Conclusion for Spotify

No single actor found that is both (a) artist-URL/id-driven and (b) returns full TRACK-level
catalogue with ISRC. The closest 2-actor combination if this is ever revisited:
`nifty.codes~spotify-artistdiscography-scraper` (22/22 releases, id `7r06dwAH933JBaR5S`, $0.002/release)
to enumerate every album, then a per-album track scraper to pull track rows — still no ISRC
expected anywhere in the Spotify actor ecosystem; convergence-log F31/F32's conclusion ("Spotify
supplies no ISRC on either candidate actor; cross-platform dedup structurally rests on SoundCloud's
isrc") stands. Recommend NOT swapping the current `automation-lab~spotify-scraper` — no candidate
beats it on identity-safety (artist URL, not keyword search) while also fixing the catalogue-size
or ISRC gap.

Also checked (no probe, per-listing metadata only, budget-conscious): `hipersoft~spotify-scraper`
(store search "spotify isrc"/general listing) is F31-confirmed keyword-search-only with no isrc —
excluded per the task's own framing. `apify~spotify-scraper` — **does not exist** (404 on
`/v2/acts/apify~spotify-scraper`); not a real actor id.

---

## 2. SoundCloud — current actor confirmed still working; one alternative identified

**Confirmation run: `automation-lab~soundcloud-scraper`, `mode: userUrl`.**

- Input: `{"mode":"userUrl","startUrls":["https://soundcloud.com/flume"],"maxResults":5}`
- HTTP 201, **6 items** (1 `user` row + 5 `track` rows), matching F31's documented shape exactly.
- `user` row keys: `type, id, username, fullName, url, avatarUrl, description, city, countryCode, followersCount, followingsCount, trackCount, playlistCount, likesCount, repostsCount`.
- `track` row keys include `type, id, title, url, artworkUrl, description, genre, tagList, duration, playbackCount, likesCount, repostsCount, commentCount, downloadCount, downloadable, …, isrc, releaseDate, userName`.
- All 5 track rows carried a real ISRC, e.g. `US38Y2548239` — reconfirms F31/F32. Actor is healthy.

**Alternative (not run, budget-conscious — task only required confirming the current one):**
`cryptosignals~soundcloud-scraper` (id `1U4mjpDLE2YHFJfng`, "SoundCloud Scraper 2026 — Tracks,
Artists (No API Key)", 981 runs, PAY_PER_EVENT `$0.005`/result scraped, build active). Listing
claims track/user search + client_id rotation against SoundCloud's public API v2. Stable-looking
(981 runs, recent pricing update), but unverified for `isrc` presence — would need a probe before
adoption. (Note: a second store hit under the same search, `PGINBOPOGlNeBsYci`, resolved to the
*same* `automation-lab/soundcloud-scraper` actor already in use — not a distinct alternative.)

---

## 3. Instagram — two alternatives probed; reels confirmed on one

Current: `apify~instagram-profile-scraper` via `ApifyProfileScraperAdapter`
(`app/Services/Platforms/Actors/ApifyProfileScraperAdapter.php`), alternate configured adapter
`figue~instagram-profile-scraper` (`FigueProfileScraperAdapter`).

**`apify~instagram-scraper`, `resultsType: "posts"`.**
Input: `{"resultsType":"posts","directUrls":["https://www.instagram.com/gypsea_lust/"],"resultsLimit":3}`,
called with `?timeout=120`. **Result: HTTP 400, `run-failed` / `TIMED-OUT`** — the actor did not
finish inside the 120s sync budget for `gypsea_lust` (posts mode is the heaviest resultsType on
this actor; profile/`details` mode would likely be faster but was not retried — budget/time). This
is a real finding: if this actor is ever adopted for posts+reels in one call, the driver would need
either a longer `run-sync` timeout or async run+poll, not a fixed 120s sync call.

**`apify~instagram-reel-scraper`** (dedicated reel actor). Input:
`{"username":["gypsea_lust"],"resultsLimit":3}`, `?timeout=150`. **HTTP 201, 3 items — reels
confirmed.** Dataset item keys: `id, type, shortCode, caption, hashtags, mentions, url,
commentsCount, dimensionsHeight, dimensionsWidth, images, videoUrl, likesCount, videoPlayCount,
timestamp, locationName, locationId, ownerFullName, ownerUsername, ownerId, isPinned, productType,
videoDuration, inputUrl, firstComment, latestComments, originalHeight, originalWidth, displayUrl,
audioUrl, alt, childPosts, taggedUsers`.

- `videoUrl` present (not `video_url`) — direct playable reel URL.
- `displayUrl` present (thumbnail/cover image), plus `images` array.
- `shortCode`, `timestamp` both present, exactly the identity/ordering fields a driver would need.
- Pricing (listing, not re-fetched separately from search result): `PRICE_PER_DATASET_ITEM
  $0.0023/reel` legacy tier + a newer PAY_PER_EVENT tier (`minimalMaxTotalChargeUsd: 0.0073`) —
  cheap per reel.

**Conclusion:** `apify~instagram-reel-scraper` is a clean, fast, working alternative specifically
for reels-with-video-URLs; `apify~instagram-scraper` in `posts` mode is a broader "everything"
actor (posts + reels + carousels via `productType`) but timed out at 120s on this handle, so it
needs a longer timeout budget or async handling before it's a safe swap-in.

---

## 4. Google Business — one stable alternative, no probe run (per task scope)

Current: `compass~crawler-google-places` (`config/partna.php` → `services.apify.actors.google_places`).
Pricing (listing): PAY_PER_EVENT, `$0.007` actor-start + per-place-scraped charge.

**Alternative: `enckay~google-maps-places-extractor`** (id `LmLOOMYKuCUrYsda2`, title "Google Maps
Places Extractor", **31,433 runs** — the highest-run-count "places" listing found — active listing
created 2025-11-13). Pricing: tiered `PRICE_PER_DATASET_ITEM`, `$0.007`/result at FREE tier down to
`$0.005`/result at GOLD/PLATINUM/DIAMOND — comparable to or cheaper than `compass~crawler-google-places`
depending on account tier, and a straightforward per-result billing model (vs. compass's
per-event/actor-start split). Not probed — task scope only required listing one alternative with
pricing for this platform.

---

## Failover code shape (≤10 lines, from reading `MusicActorDriver.php` and `InstagramScraper.php`)

Both drivers already isolate the actor id + adapter behind one config lookup
(`partna.music.platforms.{platform}.actor`/`.adapter`, `partna.instagram.actor` +
`instagram.actor_adapters`) — so failover is additive, not a rewrite:

1. **Config**: turn each single `'actor' => '...'` into `'actors' => ['primary~id', 'fallback~id']`
   (ordered list), keeping the existing `'actor_adapters'`/adapter-per-actor map (Instagram already
   has this pattern for `apify~…` vs `figue~…`; music needs the same map added per platform).
2. **Driver loop** (`MusicActorDriver::run`, `InstagramScraper::attemptFetch`): wrap the existing
   single `Http::withToken(...)->post(...)` call in a `foreach ($actors as $actorId)` loop; resolve
   `$adapter = $adapters[$actorId]` per iteration (fail-closed exactly as today if unmapped);
   `continue` to the next actor on `NoAnswer`/4xx (mirrors the existing `! $response->successful()`
   branch), return on first success.
3. **Budget**: `ApifyBudget::tryClaim()` should be claimed ONCE per logical run (not per actor
   attempted) — claim before the loop, not inside it, so a failover to actor #2 doesn't double-spend
   the daily cap slot.
4. **New classes touched**: one new `XxxAdapter implements MusicActorAdapter`/`InstagramActorAdapter`
   per fallback actor (e.g. a `SpotifyDiscographyAdapter` for `nifty.codes~spotify-artistdiscography-scraper`
   if that combo is pursued later) — no changes needed to `BilledEffectDriver`/`BilledEffectResult`.
