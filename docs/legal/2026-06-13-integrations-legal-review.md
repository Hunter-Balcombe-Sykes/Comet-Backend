# Integrations & Platforms — Legal Review (current code)

**Date:** 2026-06-13 · **Supersedes:** `2026-06-01-scraping-legal-review.md`
**Reviewer:** Claude (Opus 4.8), reading the *current* `Platforms/` + `SmartLinks/` source (post multi-account / socials / YouTube-Music / Vimeo-highlights / events / custom-links merges, HEAD `1917ca9e`).
**Method:** Fresh code read of every scraper, API client, controller, the shared fetcher, the connection model, and the refresh cron — not a re-summary of the prior doc. Five extraction passes (Instagram, Fresha, shops, events/community, music/API/socials/Google) cross-checked against retrieved platform T&Cs.
**Status:** ⚠️ Decision-support, not legal advice. **Two components remain CRITICAL and must not ship as built.** Get Australian tech-counsel sign-off before launch.

---

## 0. What changed since the 2026-06-01 review

- **Both CRITICALs are still live in current code** (verified line-by-line, not assumed):
  - **Instagram** still scrapes via Apify (`apify~instagram-profile-scraper`) and still **re-hosts** chosen photos + profile pic to R2. It is the *only* integration that copies upstream media into our storage.
  - **Fresha** still impersonates Fresha's first-party web client: POSTs `www.fresha.com/graphql` with a pinned Apollo persisted-query SHA-256 hash, `origin: https://www.fresha.com`, `x-client-version: <pinned build hash>`, and a Chrome/120 UA. Commit `8be6c0cb` (2026-06-08) only moved the hash/version from class constants into `config/services.php` — the call was not removed.
- **~13 new integrations not covered by the prior doc** were added and are analysed here: Pinterest, Vimeo, Deezer, Bandcamp, Twitch, Strava, Skool, Humanitix, WooCommerce, BigCartel, Squarespace, Generic-shop, Google Business, YouTube Music, plus link-only X / LinkedIn / Threads / Reddit.
- **"Stan" was removed** (no `StanController` in the tree) — drop it from the risk register.
- **Google Business is new and mixed:** the canonical "picker" path uses the **official Google Places API (New)** with a server key (good access) — but it **stores up to 5 review bodies + author names + author photo URIs + up to 10 place photos** in our DB and re-serves them, which raises a *Places-API storage/caching* and *third-party-PII* issue distinct from the scraping ones.
- **New aggravator — systematic, ongoing collection:** a **daily cron** (`RefreshIntegrationConnectionsCommand` → `PlatformRefresher::REFRESHABLE`) re-fetches the auto-content platforms every day into `site.platform_connections.payload`. This is no longer "fetch once on connect" — it is a continuously-updated **compiled database** of scraped content, which is the exact target of the "systematic/automated data collection" and "do not create a database of our content" ToS clauses.
- **No consent / rights-warranty exists anywhere** in the integration flow today (grep-confirmed). See §4 for what the planned "do you agree" layer can and cannot fix.

---

## 1. Executive verdict (unchanged in shape, refined in detail)

Two implementations of the same idea, opposite legal postures — and the split is now starker because the new integrations almost all sit on the same `Platforms/` scraper base.

- **Green path** — official APIs (Deezer open API, Apple iTunes Search, Google Places picker), official oEmbed (Spotify, SoundCloud), link-only socials (X/LinkedIn/Threads/Reddit/Facebook/TikTok), and the SmartLinks honest fetcher. Defensible.
- **Red/amber path** — Instagram (Apify + re-host) and Fresha (private-API impersonation) are CRITICAL; the rest of the `Platforms/` scrapers are ToS-breaching-but-mild because they **hot-link images** (no copyright reproduction) and read **facts** — but every one of them presents a **spoofed Chrome/120 User-Agent**, and the daily refresh turns the breach from one-shot into systematic.

| Tier | Components |
|---|---|
| 🔴 **CRITICAL** | **Instagram** (Apify scrape + photo re-host to R2), **Fresha** (first-party GraphQL impersonation) |
| 🟡 **MEDIUM** | Shopify, Squarespace, Generic-shop, Eventbrite, Humanitix, Pinterest, **Google Business (Places storage of reviews/photos)** |
| 🟢→🟡 **LOW–MEDIUM** | YouTube + YouTube Music (HTML/RSS scrape), Vimeo (undocumented legacy API + UA-spoof), Bandcamp, WooCommerce (public Store API), BigCartel, Strava, Skool, Deezer/Apple/Spotify/SoundCloud (official, but gratuitous UA-spoof) |
| 🟢 **LOW** | X / LinkedIn / Threads / Reddit / Facebook / TikTok (link-only), Google Business link-path, custom links / standalone events (metadata reads) |

> **Access posture (code-level, all scrapers):** the shared `SafeUrlFetcher` defaults to an honest `Mozilla/5.0 (compatible; PartnaBot/1.0; +https://partna.au)` UA — but **every `Platforms/` scraper overrides it** with `['User-Agent' => 'Mozilla/5.0 … Chrome/120.0.0.0 Safari/537.36']` via the `array_merge($defaults, $headers)` merge (caller headers win). Disguising a bot as a human browser to defeat bot-detection is a bad-faith / evasion signal (cf. *Ryanair v. Booking Holdings*) and tightens every ToS-breach finding. Fresha goes further and forges `origin` + `x-client-version` to impersonate Fresha's own software.

---

## 2. The mechanism taxonomy (current code, with evidence)

Risk attaches to **how** each integration reaches the data. Five buckets:

### A. Official API / OAuth-style sanctioned access
| Integration | Endpoint | Notes |
|---|---|---|
| Deezer | `api.deezer.com/artist/{id}` (`DeezerApi.php:32`) | Open, keyless. Hot-linked art. **Sends Chrome/120 UA — gratuitous, drop it.** |
| Apple Music / Podcasts | `itunes.apple.com/search`,`/lookup` (`AppleSearch.php:80`) | Apple-provided public Search API. Hot-linked art (URL size-bumped). Affiliate display rules apply. |
| Spotify / SoundCloud | `open.spotify.com/oembed`, `soundcloud.com/oembed` (`OEmbedService`) | Official oEmbed. Sanctioned. **Spotify Dev Terms bar compiling content into a DB — and we now persist + daily-refresh the oEmbed payload.** |
| Google Business (picker) | `places.googleapis.com/v1/places/{id}` + `/media` (`GoogleBusinessService.php:137,316`) | Official Places API (New), server key. **But stores reviews/author-PII/photos — Places storage terms + 3rd-party PII (§3.4).** |

### B. Undocumented / "keyless legacy" first-party endpoints (ToS breach, brittle)
| Integration | Endpoint | Evidence |
|---|---|---|
| Shopify | `/meta.json`, `/products.json?limit=250` | `ShopifyScraper.php:22,71` — undocumented storefront JSON |
| Squarespace | `<page>?format=json` | `SquarespaceScraper.php:120` — internal page-model param |
| Vimeo | `vimeo.com/api/v2/{path}/videos.json` | `VimeoApi.php:90` — legacy Simple API (deprecated), **not** `api.vimeo.com` OAuth; Chrome UA (`:14`) |
| BigCartel | `api.bigcartel.com/{acct}/products.json` | `BigCartelScraper.php:52` — legacy keyless API |
| YouTube | `youtube.com/@{handle}` (channel-id) + `feeds/videos.xml` RSS | `YoutubeScraper.php:148,86` — no Data API key |
| WooCommerce | `/wp-json/wc/store/v1/products` | `WooCommerceScraper.php:18` — **documented & intentionally public** (mildest of this bucket) |

### C. Public-HTML scrape (og: tags / JSON-LD / regex) — ToS breach, hot-linked, factual
Eventbrite (`/o/<slug>` + per-event JSON-LD, `EventbriteScraper.php:63,81`), Humanitix (`events.humanitix.com/host/<slug>`, JSON-LD), Skool (`/about` og: tags — "public even for private communities", `SkoolScraper.php:47`), Strava (`/clubs/<slug>` og: + member-count regex; athlete profiles are walled and *excluded*), Twitch (channel og: tags), Bandcamp (`{artist}.bandcamp.com/music` HTML), Pinterest (profile HTML + `feed.rss`), Generic-shop (schema.org `Product` JSON-LD off any pasted URL). **All hot-link images; none re-host.**

### D. Third-party scraping-as-a-service (liability transfer) — CRITICAL
Instagram via Apify actor `apify~instagram-profile-scraper` (`InstagramScraper.php:17,40`). Apify's terms make **you** responsible and require **you** to indemnify Apify for any third-party-rights / ToS violation.

### E. First-party client impersonation (unauthorised access) — CRITICAL
Fresha private GraphQL replay with pinned persisted-query hash + spoofed `origin`/`x-client-version` (`FreshaController.php:286–300`).

### F. Link-only (correct, no fetch)
X, LinkedIn, Threads, Reddit, Facebook, TikTok — store a normalised `{username, url}`, **no HTTP call** (`XController.php:87`, `FacebookController` "We do NOT scrape", `TiktokController` same). This is the model the rest should converge toward where no sanctioned API exists.

---

## 3. The components that actually carry risk

### 3.1 Instagram — 🔴 CRITICAL (unchanged)
**Mechanism (verified):** Apify actor scrape by **arbitrary username** (format-validated only — no ownership proof, no OAuth: `InstagramController.php:263–269`); chosen post images + profile pic **downloaded and written to R2** (`InstagramConnectJob.php:176–177,209–211`; `InstagramController.php:346`). `DeleteMirroredMediaJob` exists *specifically* because IG is "the only platform that mirrors upstream images into our own object storage."
**Exposure:** copyright **reproduction** (AU has no fair use; re-hosting has no defence absent an owner licence) · Meta ToS §3.2 + Automated Data Collection Terms bar automated collection "regardless of whether … logged in" · AU privacy — **non-consenting third parties** in photos (*Clearview* [2021] AICmr 54: "publicly available" ≠ free to collect) · Apify indemnity flows to us · this is Meta's exact litigation pattern (*BrandTotal*, *Bright Data*).
**Fix:** Instagram Graph API w/ Instagram Login (OAuth, Pro accounts) → **embed, don't re-host** → creator grants a display licence + warrants rights in onboarding. Same UX, defence actually wired up.

### 3.2 Fresha — 🔴 CRITICAL (unchanged; the single most exposed)
**Mechanism (verified):** primary `__NEXT_DATA__` public-page scrape (`FreshaController.php:402`) **plus** per-employee `www.fresha.com/graphql` replay with `operationName: BookingFlow_Initialize_Mutation`, `sha256Hash` + `x-client-version` from config, `origin: https://www.fresha.com`, Chrome UA (`:286–300`). User supplies only their salon URL + self-asserts "which team member am I" (`employeeId`, no verification). Salon-wide data (all staff names/titles/avatars/ratings) is pulled; the selected employee's services are persisted. **Code comment admits "the real version uses Fresha's partner API."**
**Why categorically worse:** forging the first-party client to reach a private API = "without authorization" (CFAA — *Van Buren* gates-down + *Power Ventures*/*3Taps* circumvention; spoofed headers feed *Ryanair*'s intent theory) and **Australia Criminal Code s478.1** (unauthorised access to restricted data, 2 yrs). Fresha ToS independently bars scraping, database-building, **impersonation**, and unauthorised access (4 clauses).
**Fix (your own framing already supports it):** the user self-identifies and knows their own services — **drop the GraphQL impersonation entirely** and read their services from the public-page menu (the code already falls back to it) filtered to their pick, or let them confirm/edit a manual list + booking deep-link. That alone drops Fresha CRITICAL → MEDIUM.

### 3.3 Shops (Shopify / Squarespace / Generic / Woo / BigCartel) — 🟡 MEDIUM / LOW–MEDIUM
**Mechanism (verified):** undocumented or public store endpoints + HTML, Chrome UA, **images hot-linked** (remote CDN URL stored, never `Storage::put` — confirmed across all five). The connecting creator is typically an **affiliate, not the merchant** — `AddShopBrandRequest` only requires a valid URL, no ownership.
**Net:** the live issue is **ToS/contract breach** (Shopify API ToU §2.3(14), Shopify ToS §1.9; Squarespace internal param; etc.) + brittleness, **not copyright** (hot-link = no reproduction) and **not the affiliate's to consent to** (products belong to the brand). WooCommerce's Store API is documented & intentionally public → mildest. **Manual entry + affiliate deep-link + hot-linked images is the honest design.** (Note: `SubmitShopCatalog`/`SetShopProducts` are *not* manual entry — they select from / re-warm the scrape.)

### 3.4 Google Business — 🟡 MEDIUM (new; official access, non-compliant storage)
**Mechanism (verified):** picker path calls the **official Places API (New)** with `X-Goog-Api-Key` (`GoogleBusinessService.php:133–137`) and resolves photos via the official `/media` endpoint (`:316`) — *access is sanctioned*. **But** it **persists up to 5 reviews (author display name, author URI, author photo URI, full review text, timestamps) and up to 10 place photo URIs** into `payload` and re-serves them on the sitepage (`:191–199`), refreshed daily.
**Exposure:** Google Maps/Places API policy **restricts caching/storage** of most Places content (place_id may be stored; review text + author identity + photos generally may **not** be retained long-term and have display/attribution rules), and the reviews are **third-party content + third-party PII** the connecting user can't consent to. The link path (following `maps.app.goo.gl` to extract name+coords from the URL) is **LOW**.
**Fix:** keep the official API; **don't store review bodies/author PII/photos** — either display live via Google's components with attribution, or store only `place_id` + your own rating snapshot per Google's terms; confirm caching limits with current Places policy.

### 3.5 YouTube / YouTube Music — 🟢→🟡 LOW–MEDIUM (cheapest fix)
HTML channel-page scrape for the channel id + undocumented `feeds/videos.xml` RSS, Chrome UA; thumbnails **hot-linked** from `i.ytimg.com` (`YoutubeThumbnailResolver` deliberately bypasses SafeUrlFetcher with a pinned `i.ytimg.com` host). Only the HTML/RSS scrape is the breach — **trivially replaced by the YouTube Data API v3** (`channels.list` → `playlistItems.list`, ~3 quota units), keep hot-linking thumbs / IFrame embed.

### 3.6 Eventbrite / Humanitix — 🟡 MEDIUM
Public org/host page + per-event **JSON-LD** scrape, Chrome UA, hot-linked, factual. Eventbrite ToS §13 expressly bars automated extraction; **a free official API v3 exists** (organiser connects via OAuth — fits the "creator connects their own account" model). Humanitix has no public API → manual confirm + deep-link.

### 3.7 Pinterest / Bandcamp / Strava / Skool / Twitch / Vimeo — 🟢→🟡 LOW–MEDIUM
Public scrapes (og:/JSON-LD/RSS), Chrome UA, hot-linked, mostly factual. Pinterest is the highest of this group (profile-state scrape + RSS, ToS explicitly bars automated collection). Twitch and Vimeo have **official APIs/embeds** (Helix; `api.vimeo.com` OAuth) — the current undocumented/legacy paths + UA-spoof should move onto them. Skool's `/about`-is-public-even-for-private-communities is a mild circumvention smell. Strava is the safest (public *club* pages only; athlete profiles are walled and explicitly avoided).

### 3.8 Official-but-UA-spoofed (Deezer / Apple / Spotify / SoundCloud) — 🟢 LOW, one cleanup
These hit **sanctioned** endpoints, so there's no access breach — but they still send the **Chrome/120 UA** (inherited from `PlatformScraper`). It's gratuitous browser-impersonation on an API that doesn't need it; it muddies the good-faith posture. **Send an honest `PartnaBot` UA on all official-API calls.** Also: Spotify Dev Terms bar compiling Spotify content into a database — we persist + daily-refresh the oEmbed payload, so keep that to the minimum embed fields.

---

## 4. The consent question — what a "do you agree" layer can and cannot do

You're planning an explicit consent / agreement layer ("do you agree…"). Honest assessment: **consent is a necessary ingredient of the green path and worthless against the red path.** It is not a wrapper that legalises scraping.

**What user consent genuinely cures (build this):**
1. **Copyright in the user's *own* content** — *if* they (a) connect via an official path that proves it's theirs (OAuth) and (b) **grant Partna a licence to display** it and **warrant they hold the rights.** This is what makes embedding the creator's own IG/YouTube content defensible.
2. **The user's *own* PII** — APP-compliant collection of their own data.
3. **Disclosure / transparency** (APP 5, privacy policy) and a good-faith record.

**What consent *cannot* cure (the four hard limits):**
1. **A user can't waive the third-party platform's contract.** When we scrape Instagram/Fresha/Shopify, the breach is between **Partna (or Apify)** and **that platform**, under **that platform's** ToS. The user clicking "I agree" grants Partna nothing the platform withheld — and the user *themselves* agreed to Instagram's ToS not to let bots harvest their account. "It's my account, I consent" is legally empty against Meta/Fresha/Shopify.
2. **A user can't consent for third parties** — other people in their IG photos, the **other staff** whose Fresha data is pulled in passing, the **reviewers** whose Google reviews + names + photos we store, the **brand** whose Shopify catalogue an affiliate links.
3. **A user can't licence content they don't own** — Shopify *affiliates* don't own the brand's product photos; the salon's other-staff services aren't the connecting user's; Google reviews belong to the reviewers/Google.
4. **Consent doesn't cure the access *method*.** A user can consent to sharing *their* services but **cannot authorise Partna to forge Fresha's first-party client** to hit a private API — that's a wrong against *Fresha* (CFAA / s478.1), independent of the data. The same logic applies to UA-spoofing to defeat bot-detection across every scraper.

**The wrinkle to avoid:** a clickwrap that makes users **warrant rights they obviously don't have** (e.g. "I have the rights to this brand's Shopify products") is worse than nothing — it evidences that Partna *knew* rights were required and shifted a known-often-false warranty onto users, it doesn't bind the platform at all, and it can itself be misleading conduct (ACL s18). It will not survive acquisition diligence ("where did this data come from, and on what authority?").

**Net:** put the consent/licence/rights-warranty layer in front of the **green path** (official APIs + OAuth + embed/hot-link + the user's own content). In front of a scraper that re-hosts third-party photos or forges Fresha's client, "do you agree" is, at best, irrelevant to the main exposure.

`★ Insight ─────────────────────────────────────`
**Consent runs along the data, not the wire.** It can legalise *what* you show (the user's own content) but never *how* you obtained it (the platform's authorisation). That's why the same "I agree" checkbox is decisive for an OAuth-embedded IG feed and useless for an Apify-scraped, R2-rehosted one — identical end-screen, opposite legal footing.
`─────────────────────────────────────────────────`

---

## 5. The systematic-collection aggravator

`RefreshIntegrationConnectionsCommand` runs daily over `PlatformRefresher::REFRESHABLE`, re-fetching each connection and persisting the snapshot to `site.platform_connections.payload`. Consequence: the breach is **systematic and ongoing**, not one-shot, and the JSONB store is a **compiled, continuously-updated database** of third-party content. This is precisely the conduct the strongest ToS clauses name — Fresha's "create an electronic database by systematically downloading and storing," Spotify's "store, aggregate or create compilations or databases of Spotify Content," Shopify's "systematic or automated data collection." It also compounds Google Places' storage-restriction problem (daily re-store of review PII). Any remediation should treat "stop storing / minimise + embed live" as part of the fix, not just "stop the initial fetch."

---

## 6. Remediation order (current)

1. **Kill the Fresha GraphQL impersonation now** — highest criminal/CFAA exposure, brittle; the public-page fallback already exists.
2. **Stop Instagram Apify scrape + R2 re-host**; move to Graph API OAuth + embed; delete mirrored third-party photos.
3. **Google Business: stop persisting review bodies / author PII / place photos**; store `place_id` (+ own snapshot) and display live with attribution per Places terms.
4. **Cheap ToS-breach removals:** YouTube/YouTube-Music → Data API v3; Eventbrite → API v3 (organiser OAuth); Vimeo → `api.vimeo.com` OAuth or oEmbed; Twitch → Helix/embed. Keep hot-linking thumbs.
5. **Stop the gratuitous Chrome-UA spoof** — send honest `PartnaBot` UA on official-API calls (Deezer/Apple/Spotify/SoundCloud) and reconsider it everywhere; it converts a grey scrape into a bad-faith one.
6. **Shops:** prefer manual entry + affiliate deep-link + hot-linked images; where kept, treat as ToS/brittleness risk and never re-host.
7. **Minimise stored snapshots + the daily re-store**; embed live where the sanctioned path allows.
8. **Build the consent/licence layer for the green path** — official OAuth + "I grant a display licence and warrant I hold the rights" — and **don't** put a rights-warranty in front of content the user can't own.
9. **Cross-cutting:** never re-host copyrighted media without a licence; don't imply platform affiliation (ACL s18); Australian counsel sign-off before launch.

## 7. Viability verdict

Unchanged and reinforced by the new code: the aggregation model is viable **on the SmartLinks philosophy** (official APIs / OAuth / oEmbed + hot-link + manual entry + deep-link + a real consent/licence layer), and brittle-and-exposed on the `Platforms/` scraper philosophy. The link-only socials and the official-API music/Places paths prove the green path is already in the codebase. The job is to (a) remove the two CRITICALs, (b) move the cheap scrapes onto their official APIs, (c) stop storing third-party content, and (d) wire the consent layer to the green path — **before** the daily-refresh systematic-collection footprint and the re-hosted media become a diligence problem at exactly the moment value crystallises.

---

## 8. Per-platform breach register

> Status legend: 🔴 hard-stop (copyright / unauthorised access) · 🟡 ToS-grade breach · 🟢→🟡 mild ToS breach (cheap to fix) · 🟢 no breach (or cleanup only). "Breached?" = is Partna doing something a platform's terms or the law prohibits.

| Platform | Status | How it breaches (mechanism → what's violated) | How to fix (summary — see §10 for detail) |
|---|---|---|---|
| **Instagram** | 🔴 **Yes — CRITICAL** | Apify actor (`apify~instagram-profile-scraper`) scrapes any username (no ownership check); chosen photos + profile pic **downloaded & re-hosted to R2** → **copyright reproduction** (no AU fair use) + Meta ToS §3.2 (automated collection "regardless of logged-in") + **3rd-party PII** in photos + Apify indemnity flows to you | Graph API w/ Instagram Login (OAuth, Pro accounts); **embed, never re-host**; consent + rights warranty; never surface 3rd-party content |
| **Fresha** | 🔴 **Yes — CRITICAL** | Forges Fresha's first-party client: POSTs `fresha.com/graphql` with pinned persisted-query hash + spoofed `origin` + `x-client-version` + Chrome UA → **unauthorised access** (CFAA / Criminal Code s478.1) + Fresha ToS (scrape / database / **impersonation** / unauthorised-access, 4 clauses) | **Delete the GraphQL call** (public-page `__NEXT_DATA__` fallback already exists); user-confirms their own services + booking deep-link; or Fresha Partner API |
| **Shopify** | 🟡 **Yes — ToS** | Scrapes undocumented `/products.json` + `/meta.json` + homepage, Chrome UA; images **hot-linked** (no copyright) → Shopify API ToU §2.3(14) + ToS §1.9; creator is usually an **affiliate, not owner** (can't consent) | Manual entry + affiliate deep-link + hot-linked images; merchant-owner path via official Storefront API token |
| **Squarespace** | 🟡 **Yes — ToS** | Undocumented `?format=json` internal page-model param, Chrome UA, hot-linked | Manual entry + deep-link (no public storefront API for affiliates) |
| **Generic shop** | 🟡 **Yes — ToS** | Scrapes schema.org `Product` JSON-LD off **any** pasted URL, Chrome UA | Manual entry + deep-link; restrict scrape to sanctioned/owner cases |
| **Google Business** | 🟡 **Yes (storage)** | Picker uses **official** Places API (access OK) **but stores 5 review bodies + author names/photos + 10 place photos**, daily → Places caching/storage terms + **3rd-party review PII** | Keep the API; store only `place_id` (+ own rating snapshot); display reviews/photos **live with attribution**, don't persist |
| **Eventbrite** | 🟡 **Yes — ToS** | Public org + per-event **JSON-LD** scrape, Chrome UA, hot-linked, factual → Eventbrite ToS §13 (automated extraction) | **Free official API v3** — organiser connects via OAuth (`/organizations/{id}/events/`) |
| **Humanitix** | 🟡 **Yes — ToS** | Public host/event JSON-LD scrape, Chrome UA, hot-linked | Manual confirm + deep-link (no public API) |
| **Pinterest** | 🟡 **Yes — ToS** | Scrapes profile-state HTML + `feed.rss`, Chrome UA → Pinterest ToS bars automated collection | Pinterest API (OAuth) or link-only + manual highlights |
| **YouTube** | 🟢→🟡 **Yes — ToS** | HTML channel-page scrape for channel-id + undocumented `feeds/videos.xml` RSS, Chrome UA; thumbs hot-linked → YouTube ToS anti-automation | **Data API v3** (`channels.list`→`playlistItems.list`, ~3 quota units); keep hot-linking thumbs / IFrame embed |
| **YouTube Music** | 🟢→🟡 **Yes — ToS** | Same channel/scrape family as YouTube | Same — Data API v3 |
| **Vimeo** | 🟢→🟡 **Yes — ToS** | Undocumented **legacy** Simple API (`vimeo.com/api/v2/...`, not `api.vimeo.com`) + Chrome UA | Official `api.vimeo.com` OAuth, or oEmbed |
| **Bandcamp** | 🟢→🟡 **Yes — ToS** | HTML scrape of `{artist}.bandcamp.com/music`, Chrome UA, hot-linked | No open API → manual + deep-link, or oEmbed where available |
| **WooCommerce** | 🟢→🟡 **Borderline** | Hits the **documented, intentionally-public** Store API (`/wp-json/wc/store/v1/products`) — mildest; but Chrome UA + affiliate-not-owner | Lowest priority; drop UA-spoof; manual/affiliate framing |
| **BigCartel** | 🟢→🟡 **Yes — ToS** | Legacy keyless `api.bigcartel.com/{acct}/products.json`, Chrome UA | Manual + deep-link |
| **Strava** | 🟢→🟡 **Yes (mild)** | Public **club** page og: + member-count regex, Chrome UA (athlete profiles correctly **avoided**) | Strava API (OAuth) for clubs, or manual + deep-link; safest scrape as-is |
| **Skool** | 🟢→🟡 **Yes (mild)** | og: tags off `/about` ("public even for private communities" = mild circumvention smell), Chrome UA | Manual + deep-link; avoid the private-community `/about` trick |
| **Twitch** | 🟢→🟡 **Yes (mild)** | Public channel og: scrape, Chrome UA | **Helix API** + official `player.twitch.tv` embed |
| **Deezer** | 🟢 **No access breach** | **Official** open `api.deezer.com` (keyless) — but sends gratuitous Chrome UA | Drop the Chrome UA → honest `PartnaBot` UA |
| **Apple Music / Podcasts** | 🟢 **No** | **Official** iTunes Search API; hot-linked art | Add "Listen on Apple" badge/link; honest UA; don't cache upscaled art file |
| **Spotify** | 🟢 **No (1 caveat)** | **Official** oEmbed — but Dev Terms bar compiling content into a DB, and we persist + daily-refresh the payload | Keep oEmbed; store minimum embed fields only; honest UA |
| **SoundCloud** | 🟢 **No** | **Official** oEmbed | Honest UA; otherwise fine |
| **Google Business (link path)** | 🟢 **No (mild)** | Follows `maps.app.goo.gl` short-link, extracts name+coords from URL structure (no DOM scrape) | Fine; honest UA |
| **X / LinkedIn / Threads / Reddit / Facebook / TikTok** | 🟢 **No** | **Link-only** — store normalised `{username,url}`, **no HTTP fetch** | Already correct — the model to converge on |
| **Custom links** | 🟢 **No** | Metadata read of a user-pasted URL via the honest SmartLinks fetcher | Fine |
| **Standalone events** | 🟡 **Inherits** | Reuse the Eventbrite/Humanitix scraper posture for a single pasted event URL | Inherits the Eventbrite (API v3) / Humanitix (manual) fixes |

---

## 9. Cross-cutting / systemic problems

These are not tied to one platform — they multiply the per-platform risk above.

| # | Problem | Where (code) | Why it matters | Fix |
|---|---|---|---|---|
| X1 | **Spoofed Chrome/120 UA** | Every `Platforms/` scraper overrides the honest `PartnaBot` default via `array_merge($defaults, $headers)` (`SafeUrlFetcher.php:46`, `PlatformScraper.php:13`) — even on official APIs | Browser-impersonation to defeat bot-detection = bad-faith / evasion signal; converts a grey scrape into a worse one (*Ryanair v. Booking Holdings*) | Send honest identifying UA everywhere; spoof nothing |
| X2 | **Daily refresh = systematic collection** | `RefreshIntegrationConnectionsCommand` → `PlatformRefresher::REFRESHABLE`; snapshots persisted to `site.platform_connections.payload` | Turns one-shot fetch into an ongoing **compiled database** of scraped content — the exact target of "systematic data collection" / "don't build a database of our content" clauses; compounds Places storage limits | Minimise stored snapshots; embed live; stop daily re-store of 3rd-party content |
| X3 | **Media re-hosting** | Instagram only — `Storage::disk('media')->put` (`InstagramConnectJob.php:176`, `InstagramController.php:346`) | The single copyright-**reproduction** act in `Platforms/` | Embed / hot-link, never copy; delete existing mirrored R2 folders |
| X4 | **No consent / rights-warranty / display-licence** | Entire integration flow (grep-confirmed absent; only marketing-email consent exists) | Even the green path needs a licence grant + rights warranty to be defensible for the user's own content | Build consent capture — **only** in front of OAuth/official-API + embed; never a false warranty for content the user can't own |
| X5 | **No ownership verification** | Instagram, Fresha, all shops, events accept arbitrary username/URL (format-validation only) | Undercuts every "it's the user's own account, they consent" defence | Prove ownership via OAuth; for affiliate / 3rd-party data, manual entry + deep-link |
| X6 | **Third-party PII stored** | Google reviews (author names/photos, `GoogleBusinessService.php:191`), IG photos (people in them), Fresha (other staff pulled in passing) | A user can't consent for third parties (*Clearview*: "public" ≠ free to collect); statutory privacy tort live since 10 Jun 2025 | Don't persist 3rd-party PII; display live with attribution or drop; privacy-policy disclosure (APP 5) |

---

## 10. Extended remediation playbook

Effort tags: **S** = hours · **M** = 1–3 days · **L** = weeks (incl. platform review). Order follows risk (§6); details below.

### 10.1 Instagram — 🔴 remove Apify + re-host (effort: **L**)
- **Target:** Instagram Graph API with **Instagram Login** (OAuth) — Business/Creator accounts only — for the user's **own** account, displayed by **embed**, not copied.
- **Steps:**
  1. Create a Meta app; request `instagram_business_basic` (+ media scopes) via **App Review**; complete **Business Verification** (this is the long pole — start now).
  2. Add an OAuth connect flow: the user authorises *their own* IG account; store the token, not a scraped username. This also satisfies **ownership verification (X5)**.
  3. Replace the Apify call in `InstagramScraper.php` with a Graph API media fetch using the user's token.
  4. **Stop re-hosting.** IG media URLs are short-lived/signed, so either (a) display via **oEmbed/official embed** (no storage), or (b) refresh the media URL via the API at render/refresh time. Do **not** download the binary to R2.
  5. Run `DeleteMirroredMediaJob` across all existing IG connections to purge already-mirrored photos from R2.
  6. Add the **consent/licence** clause (X4): "I grant Partna a licence to display my Instagram content and warrant I hold the rights." Surface only the creator's own media — no comments, no other users.
- **If OAuth isn't ready by launch:** **disable the Instagram integration** rather than ship Apify + re-host. This is the one integration that should be dark before it's compliant.

### 10.2 Fresha — 🔴 remove client impersonation (effort: **S** to remove GraphQL, **M** for manual-confirm UX)
- **Target:** the user's own, factual service list — obtained **without forging Fresha's client**.
- **Steps:**
  1. **Delete** `fetchEmployeeServices` (the `fresha.com/graphql` POST), the pinned `booking_init_hash` / `client_version` config, and the GraphQL header spoof. This alone drops CRITICAL → MEDIUM.
  2. Keep the `__NEXT_DATA__` public-page read **but** present the extracted services to the user to **confirm/edit** — so the stored list is user-authored, not silently scraped (gets you toward LOW). Send an **honest UA** on that fetch.
  3. Deep-link to the user's Fresha booking page (don't reproduce the booking flow).
  4. Optional/better: apply to the **Fresha Partner API** (the code comment already names this as the intended path) for a sanctioned per-employee feed.
- **Note:** even the `__NEXT_DATA__` scrape technically touches Fresha's anti-scrape clause; manual-confirm + deep-link is the clean end state. Removing the *impersonation* is the urgent part.

### 10.3 Shops (Shopify / Squarespace / Generic / Woo / BigCartel) — 🟡 (effort: **M**)
- **Target:** split by who the user is.
  - **User IS the merchant:** offer the official route they can self-authorise — Shopify **Storefront API token** (they generate it), Woo **REST API keys**, BigCartel/Squarespace where an API exists.
  - **User is an affiliate (the common case):** **manual product entry** (title, price, image URL or upload, affiliate deep-link) — no scrape, since the products aren't theirs to consent to.
- **Steps:**
  1. Add a manual product-entry path + an "are you the store owner?" branch.
  2. Remove the undocumented-endpoint scrapes for non-owners (Shopify `/products.json` + `/meta.json`, Squarespace `?format=json`, Generic JSON-LD, BigCartel legacy API).
  3. Keep images **hot-linked** (never re-host). Drop the Chrome UA.
  4. WooCommerce's public Store API is the mildest — lowest priority, but still drop the UA spoof and prefer the owner/manual framing.

### 10.4 Google Business — 🟡 fix storage, keep the API (effort: **M**)
- **Target:** official Places API access stays; stop persisting third-party content.
- **Steps:**
  1. Stop writing review bodies + author name/URI/photo + place photo URIs into `payload`.
  2. Store `place_id` (long-term-storable) + your own derived snapshot (rating value, review count). Refresh the snapshot daily; don't store the bodies.
  3. Display reviews/photos **live** via Google's components or fetch-on-render **with the required attribution**, or omit reviews from the sitepage.
  4. Re-confirm current **Places API Policies** on caching before launch (the "only `place_id` may be cached long-term; other content must be refreshed / not retained" rule).

### 10.5 Events (Eventbrite / Humanitix / standalone events) — 🟡 (effort: **M** / **S**)
- **Eventbrite:** register an app; organiser connects via **OAuth**; `GET /organizations/{id}/events/`. Replace `EventbriteScraper`. This fits the "creator connects their own account" model perfectly. (**M**)
- **Humanitix:** no public API → **manual confirm + deep-link**, honest UA. (**S**)
- **Standalone events** inherit whichever path the source platform uses.

### 10.6 Video / music scrapes — 🟢→🟡 move onto official APIs (effort: **S–M** each)
- **YouTube + YouTube Music:** Data API v3 key. `channels.list` (by handle) → `contentDetails.relatedPlaylists.uploads` → `playlistItems.list` (~3 quota units/refresh; never `search.list`). Keep hot-linking `i.ytimg.com` thumbs / IFrame embed. (**S–M**)
- **Vimeo:** `api.vimeo.com` OAuth, or oEmbed for display; drop the legacy `vimeo.com/api/v2` + UA spoof. (**M**)
- **Twitch:** Helix API (app access token) + official `player.twitch.tv` embed. (**M**)
- **Pinterest:** official Pinterest API (OAuth) or downgrade to link-only + manual highlights. (**S–M**)
- **Bandcamp:** no open API → manual + deep-link, or oEmbed where available. (**S**)
- **Strava:** Strava API (OAuth) for clubs, or manual + deep-link. Safest scrape today, but still drop the UA spoof. (**S–M**)
- **Skool:** manual + deep-link; stop using the `/about`-for-private-communities path. (**S**)

### 10.7 Official-API cleanup (Deezer / Apple / Spotify / SoundCloud) — 🟢 (effort: **S**)
- Drop the inherited Chrome UA → honest `PartnaBot` UA on all four (they hit sanctioned endpoints; the spoof only hurts the good-faith posture).
- **Spotify:** store the minimum embed fields only; don't compile/aggregate Spotify content into the DB (Dev Terms §IV.3.1(a)).
- **Apple:** add a "Listen on Apple Music" badge + link; don't cache the URL-upscaled artwork file (minor "no modification" nit).

### 10.8 Cross-cutting fixes
- **X1 — UA:** change `PlatformScraper::USER_AGENT` to an honest identifier (or stop overriding `SafeUrlFetcher`'s `PartnaBot` default). One change, repo-wide effect. (**S**)
- **X2 — storage minimisation:** trim `payload` to embed-essential fields; for third-party content store a *reference* (id/url) not the content; reconsider what the daily cron re-stores. (**M**)
- **X3 — re-hosting:** covered by 10.1; add a guard/test asserting no `Storage::put` in the `Platforms/` path. (**S**)
- **X4 — consent layer:** add onboarding ToS + a per-connect capture (e.g. `accepted_licence_at` / `licence_version` on the connection or user) that records "I have the right to display this and grant Partna a licence." **Put it only in front of the green path** (OAuth / official API / the user's own content). Do **not** ask affiliates to warrant rights to a brand's products — that's a false warranty (ACL s18 risk) and binds no platform. (**M**)
- **X5 — ownership verification:** delivered by the OAuth flows above; for non-OAuth/affiliate data, manual entry is the proof-substitute. (folded in)
- **X6 — third-party PII:** don't persist other people's PII (Google review authors, people in IG photos, other Fresha staff); display live with attribution or drop; disclose collection in the privacy policy (APP 5). (**M**)

### 10.9 Launch-gating summary
- **Must be fixed or dark before launch:** Instagram (10.1), Fresha GraphQL impersonation (10.2). 
- **Should be on official APIs before scale (cheap):** YouTube/YT-Music, Eventbrite, Vimeo, Twitch (10.5–10.6).
- **Fix storage before launch:** Google Business reviews/photos (10.4).
- **Cleanup any time, do it early:** UA spoof + official-API hygiene (X1, 10.7).
- **Keep as-is:** link-only socials, custom links.
