# Scraping Legal Review — Platform Integrations (`Platforms/` + `SmartLinks/`)

**Date:** 2026-06-01 · **Supersedes:** `2026-05-31-platform-integrations-legal-review.md`
**Reviewer:** Claude (Opus 4.8), adjudicating DeepSeek V4 Pro + 3 live web-research agents (two rounds)
**Subject:** PRs #167–#186 (Tobias) — per-platform content fetchers + the generic smart-link resolver
**Status:** ⚠️ Decision-support, not legal advice. Three components are **CRITICAL** and should not ship as built. Get Australian tech-counsel sign-off before launch.

### What changed since v1
- **Added Eventbrite** (#186, "organiser events scrape (no auth)") — new component analysis.
- **Added verbatim T&C quotes** per platform (with per-quote confidence flags), so the breach analysis rests on retrieved clause text, not paraphrase.
- **Added a "risk once we scale" section** with a stage-by-stage probability table and verified penalty figures.
- **Added a founder-readable "why not this direction" argument** and an expanded "how easy is the API path" table.
- **Damages correction:** Australia has **no statutory copyright damages** (v1's "~$585k per infringement" was the *criminal* penalty, not civil) — see §6.
- **Added §2A "Defences considered"** — whether "it's public", "it's the user's own account / we have consent", and "it's not copyright" actually hold. They cover the green path; they do **not** reach the four red components.
- **Correction (code re-check):** the **Shopify** integration **hot-links** product/brand images (stores the `cdn.shopify.com` URL) — it does **not** re-host them. Only `InstagramController` re-hosts in the `Platforms/` layer. Shopify's copyright-reproduction risk was therefore overstated; **Shopify is re-rated HIGH → MEDIUM** (ToS breach, not copyright). Commerce re-hosting lives only in the separate SmartLinks paste-a-link flow.
- **Note:** the unauthenticated SSRF in `InstagramController::mirror()` flagged separately has been **fixed** (host-allowlist + `SafeUrlFetcher` + image-only content-type). It does not change the copyright/ToS analysis below.

---

## 0. Methodology (why this is verified, not hallucinated)

Three independent layers, run in parallel across two rounds, then reconciled here:
1. **DeepSeek V4 Pro** (thinking-max) — independent reasoning over the actual code; instructed not to fabricate quotes/cases and to flag uncertainty.
2. **Live web-research agents** (WebFetch/WebSearch) — (a) Eventbrite ToS + API; (b) **verbatim ToS quote retrieval** for every platform; (c) scaling-risk + verified penalty figures; plus a round-1 **citation-verification pass** against primary sources.
3. **Adjudication (this doc)** — every quote carries the agent's confidence flag; every case was cross-checked; DeepSeek's citation errors and one damages misconception are corrected in §6.

The two engines **converged on identical risk rankings**; they **diverged on specific citations/quotes**, all resolved toward the verified live sources. Quotes flagged **MED** below are substance-verified but the exact wording should be re-pulled from the live document before any formal/legal use.

---

## 1. Executive verdict

Two implementations of the same idea, opposite legal postures:
- **`SmartLinks/` = green path** — official oEmbed (Spotify/YouTube/Vimeo/Bandcamp), Apple's iTunes Lookup API, public OG tags, an SSRF-guarded fetcher, and a deliberate rule to **hot-link copyrighted content art** while only re-hosting commerce images. Largely defensible.
- **`Platforms/` = red path** — Apify-scrapes Instagram **and re-hosts photos to R2**; Fresha's **private GraphQL impersonated**; Shopify `/products.json`; Stan + Eventbrite **undocumented internal endpoints**.

| Component | Verdict | Core problem |
|---|---|---|
| **Instagram** — Apify + **re-host photos to R2** | 🔴 **CRITICAL** | Copyright reproduction (AU+US) + Meta's exact litigation pattern (*BrandTotal*) + AU privacy (third parties in photos) |
| **Fresha** — private GraphQL impersonation | 🔴 **CRITICAL** | Unauthorised access to a non-public API by spoofing the first-party client → CFAA + Criminal Code s478.1 (AU) + impersonation ToS clause |
| **Shopify** — `/products.json` scrape (images **hot-linked**, not copied) | 🟡 **MEDIUM** | Undocumented-endpoint ToS breach (API ToU §2.3(14)); data is factual + images hot-linked → **no copyright reproduction**; creator is an affiliate so can't authorize a real API |
| **Eventbrite** — public org-page + per-event JSON-LD scrape (UA-spoofed) | 🟡 **MEDIUM** | ToS §13 breach (automated extraction) + brittle; but facts + hot-linked images = low copyright. **Free official API v3 exists.** |
| **Stan** — internal API + store creator email | 🟡 **MEDIUM** | Undocumented internal-API access (ToS); email low-risk if disclosed |
| **YouTube** — channel-HTML scrape + RSS, hot-linked thumbs | 🟢→🟡 **LOW–MEDIUM** | Only the HTML channel-ID scrape is a real breach — **trivially replaced by the Data API** |
| **Apple** — public iTunes Search API + hot-linked art | 🟢→🟡 **LOW–MEDIUM** | A real Apple-provided API; tighten to affiliate badge + link |
| **TikTok / Facebook** — link-only | 🟢 **LOW** | Done correctly |
| **SmartLinks — oEmbed/iTunes path** | 🟢 **LOW** | The sanctioned path |
| **SmartLinks — commerce image re-host** | 🟠 **HIGH** | Re-hosting product/brand images = reproduction; extend "hot-link, don't copy" to commerce |

> **Access posture (code-level):** every `Platforms/` scraper presents a **fake `Chrome/120` User-Agent** to evade bot-detection; **Fresha additionally spoofs `origin` + `x-client-version`** to impersonate Fresha's first-party web client. The `SmartLinks` fetcher, by contrast, identifies honestly as `PartnaBot/1.0 (+https://partna.au)`. Disguising a scraper as a human browser (rather than an honestly-identified, robots.txt-respecting bot) is a bad-faith / evasion signal (cf. *Ryanair v. Booking Holdings*) and tightens the ToS-breach finding for the scrapers.

---

## 2. The four-act framework

Risk attaches per *act*, not per platform:

| Act | Exposure | "We just redirect" defence |
|---|---|---|
| (a) Access / fetch | CFAA / contract / Criminal Code s478.1 (AU) | partial — only for genuinely public, no-login, no-circumvention |
| (b) Copy / store (re-host to R2) | **Copyright reproduction** | ❌ none |
| (c) Re-display | Copyright + ToS + ACL s18 | ❌ none |
| (d) Deep-link / redirect to a *legitimate* destination | minimal | ✅ yes |

---

## 2A. Defences considered — where they hold vs. where they fail

Three defences are commonly raised for this model: **(1) the data is public; (2) it's the user's own account, so we have their consent; (3) it's not copyright.** Each is *partly correct* — and together they are exactly why the `SmartLinks` green path is defensible. None of them, however, reaches the four red components, for reasons that do not turn on "public" or "consent."

**What each defence actually cures:**

| Defence | CFAA "unauthorized access" | Platform ToS / contract | Copyright | AU privacy (APPs) |
|---|---|---|---|---|
| 1. "It's public" | ✅ (hiQ; *Meta v Bright Data*) | ❌ | ❌ | ❌ **expressly rejected** — *Clearview* [2021] AICmr 54 |
| 2. "User's own account / consent" | n/a | ❌ (user can't waive the *platform's* contract) | ✅ **only for content the user owns** | ➖ only the user's *own* PII |
| 3. "It's not copyright" | n/a | ❌ | ✅ **facts** (Feist/IceTV) + **hot-linked/embedded** | n/a |

The decisive point: **"public" only ever defeats the CFAA.** It does nothing for the contract being breached or for copyright, and the OAIC in *Clearview* held that "publicly available" does **not** mean free to collect.

**Where the defences are correct (the green path):** facts (names, prices, follower counts, track/service titles, event dates) are uncopyrightable; hot-linked/embedded art is never reproduced; and a creator can license their **own** photos/videos. The oEmbed + iTunes-API + YouTube-Data-API + hot-linking design relies on exactly these and is defensible — the instinct behind all three defences is *right there*.

**The three hard limits on user consent** (the user cannot cross any of these):
1. **A user can't waive the platform's contract.** Instagram's ToS bars automated collection "regardless of whether … logged in to a Facebook account" — a rule between the user (or Apify) and Meta. "It's my account, I allow it" grants Partna no right the platform withheld; the user themselves agreed not to let bots harvest the account.
2. **A user can't consent for third parties** — other people in their photos, other salon staff.
3. **A user can't license content they don't own.**

**Applied to the red components:**
- **Instagram** — consent *could* cure copyright in the creator's **own** photos, but the code **re-hosts to R2** (reproduction), the photos contain **non-consenting third parties** (*Clearview* / APP 3.5), and the **Apify collection method** breaches Meta's ToS *whoever* owns the data. "Public + my account" saves neither the method nor the third parties.
- **Shopify** — the creator is an **affiliate, not the store owner**: the products and product photos belong to the **brand**, so the consent defence **fails outright** (not their content to give). *But* the current code **hot-links** the brand's `cdn.shopify.com` image rather than copying it → **no reproduction by Partna** (server test / no copy made). So the live issue here is the **ToS breach** (scraping `/products.json`), **not** copyright. (Copyright reproduction only arises if a product is added via the SmartLinks paste-a-link flow, which re-hosts — see §3.9.)
- **Fresha** — *partly right, but it misses the core problem.* The user **does** enter their **own** Fresha profile URL and pick which team member is them, so for **their own service list** the consent + "it's factual" defences genuinely apply (service names/prices are uncopyrightable facts — Feist/IceTV). That removes the **copyright/ownership** sting, exactly like the other reds. **But the CRITICAL rating was never about copyright — it's about the access *method*.** The per-employee menu is fetched by **impersonating Fresha's private first-party GraphQL client** (pinned persisted-query hash + spoofed `x-client-version`/`origin`). A user can consent to sharing *their* services; they **cannot** authorize Partna to forge Fresha's own software to reach a private endpoint — that is a wrong against *Fresha*, not the user (CFAA "without authorization" / Criminal Code s478.1 / Fresha's anti-impersonation ToS clause), and **consent can't waive the platform's contract** (limit #1). The two fetches differ: the public business-page scrape *(fetch 1: `__NEXT_DATA__` → team + location menu)* is far milder (public page, user's own profile, factual data); the **private-GraphQL call *(fetch 2)* is the CRITICAL part**, and it also pulls **other staff's** data in passing. **The fix is your own framing:** since the user already picks "which person am I" and knows their own services, drop the GraphQL impersonation — use the public-page menu (the code *already falls back to it*) filtered to their pick, or let them confirm/edit the list (manual) + deep-link to booking. That drops Fresha from CRITICAL to the same **MEDIUM** tier as the other public-page reads.
- **Eventbrite / Stan** — undocumented internal surfaces (not "public" in the hiQ sense) → the ToS breach stands; but data is factual + hot-linked, so copyright is low — these are the *mildest* reds and the closest to the defences working.

**The structural catch:** even where consent *would* work, the code does not implement its preconditions — there is **no OAuth**, no "creator grants Partna a licence to display their content **and warrants they hold the rights**" in onboarding, and it **re-hosts instead of embeds**. The system claims the data without satisfying a single condition the defence requires.

**The constructive flip:** the consent defence becomes *fully valid* for Instagram when done as — official **OAuth** (not Apify), **embed** (not re-host), creator **grants a copyright licence** in ToS, and no third-party content surfaced. Same user-facing outcome, defence actually wired up. **Fresha is similar:** the services *are* the user's own factual data, so consent applies — the fix is just to **drop the private-API impersonation** and read the user's own services from the public page (the code already falls back to it) or by manual confirmation, + deep-link (see §3.2). **Shopify-affiliate is the genuine exception** — there is **no** version where "their account / consent" applies, because the products aren't the creator's content; that one needs the merchant's API or manual entry + affiliate deep-link.

---

## 3. Breach-of-T&C analysis, per component (with verbatim quotes)

> Quotes retrieved 2026-06-01. Confidence: **HIGH** = exact text retrieved from the live page; **MED** = substance verified, exact wording/section to re-confirm.

### 3.1 Instagram — 🔴 CRITICAL
**Code:** `Platforms/InstagramScraper.php` (Apify `instagram-profile-scraper`); `InstagramController.php` re-hosts chosen post images + profile pic to R2 via `mirror()`.

**T&C breached** — verified verbatim 2026-06-01:
> "You may not access or collect data from our Products using automated means (without our prior permission) or attempt to access data that you do not have permission to access, **regardless of whether such automated access or collection is undertaken while logged in to a Facebook account**."
> — Meta Terms of Service §3.2, facebook.com/terms **(HIGH — exact text retrieved)**

> "You will not engage in Automated Data Collection without first obtaining Meta's express written permission or in any manner that is not explicitly authorized by Meta." (definition of *Automated Data Collection* expressly includes "web scrapers, bots, robots, spiders, crawlers, user-agents")
> — Meta Automated Data Collection Terms, facebook.com/legal/automated_data_collection_terms **(HIGH — exact text retrieved)**

> The Instagram-specific Terms of Use page (help.instagram.com/581066165581870) **could not be retrieved verbatim** (the Help Center renderer truncated it); Instagram is a Meta product governed by the §3.2 prohibition quoted above. *(IG-specific wording: re-pull before formal use.)*

**Acts:** fetch (Apify scrape — prohibited) · **copy (re-host photos = reproduction)** · display · link. Australia has **no fair use** (closed fair-dealing list; aggregation fits none), so re-hosting has no defence but an owner licence — and **third parties in the photos can't be licensed by the creator**. *Clearview* [2021] AICmr 54: "publicly available" ≠ free to collect. Apify is a liability *transfer to you* (see §5).
**Sanctioned path:** Instagram Graph API w/ Instagram Login (OAuth) — Professional accounts only, Meta App Review + Business Verification; or oEmbed embeds. **Difficulty: HARD.**

### 3.2 Fresha — 🔴 CRITICAL (the most exposed)
**Code:** `FreshaController.php` — scrapes `__NEXT_DATA__`, **and** replays `fresha.com/graphql` with a pinned Apollo persisted-query SHA-256 hash + spoofed `x-client-version` + `origin: https://www.fresha.com`, impersonating Fresha's first-party web client.

**T&C breached** — Fresha Terms of Use, verified verbatim 2026-06-01 at terms.fresha.com/terms-use **(HIGH — exact text retrieved)**, hitting this on *four* counts:
> "'scrape' content or store content of the Site on a server or other storage device connected to a network or create an electronic database by systematically downloading and storing all of the content"
> "launch an automated program or script, including, but not limited to, web spiders, web crawlers, web robots, web ants, web indexers, bots, viruses or worms, or any program which may make multiple server requests per second"
> "impersonate any person or entity or otherwise misrepresent your relationship with any person or entity"
> "attempt to gain unauthorized access to the Site … or its related systems or networks"
> "reverse engineer or access the Site in order to: design or build a competitive product or service…"

**Why this is categorically worse than HTML scraping:** it accesses a **private API behind a first-party client gate** by **forging that client**. *Van Buren* (593 U.S. 374, 2021) "gates-up-or-down": the gate is down, Partna forges the key. *Power Ventures* (844 F.3d 1058, 9th Cir. 2016) + *Craigslist v. 3Taps* (N.D. Cal. 2013): circumvention/evasion = "without authorization." Spoofed headers feed an "intent to defraud" theory (*Ryanair v. Booking Holdings*, D. Del. 2022). **Australia — Criminal Code s478.1** "unauthorised access to restricted data" (max 2 yrs): elements met by deliberate impersonation. DMCA §1201 is a *live but unsettled* theory (*Google v. SerpApi*, *Reddit v. Perplexity*, 2025 filings — **pending, not precedent**). Operationally brittle: the hash/version rotate on every Fresha redeploy.
**Defence considered (user's own profile):** the flow *is* "user enters their own Fresha URL → picks which team member is them → grab their services," so for the user's **own, factual** service list the consent + facts defences apply (see §2A) — this removes the copyright/ownership concern. It does **not** cure the access *method*: consent cannot authorize impersonating Fresha's private first-party client. Crucially, **dropping the GraphQL call downgrades this component from CRITICAL to ~MEDIUM** — and the code already falls back to the public-page location menu when that call fails, so the per-employee filter can be done client-side or by manual confirmation without forging Fresha's client at all.

**Sanctioned path:** Fresha **partner API** (the code comment admits this is the intended path) or — simpler, given the user already self-identifies — **the public-page menu filtered to their pick, or manual service confirmation + booking deep-link.** **Difficulty: MEDIUM (partner) / trivial (manual). Stop the GraphQL impersonation now; it's the only thing keeping Fresha at CRITICAL.**

### 3.3 Shopify — 🟡 MEDIUM  *(downgraded from HIGH after code re-check — images are hot-linked, not re-hosted)*
**Code:** `ShopifyScraper.php` + `ShopifyController.php` — scrapes `/products.json?limit=250` + `/meta.json` + homepage HTML; product images, logo and favicon are **hot-linked** (the `cdn.shopify.com` URL is stored, *not* downloaded). There is no `Storage::put`/`mirror()` anywhere in the Shopify path — `InstagramController` is the only `Platforms/` controller that re-hosts.

**T&C breached** — verified verbatim 2026-06-01 **(HIGH — exact text retrieved)**:
> "not use the Shopify API to conduct any systematic or automated data collection activities (including scraping, data mining, data extraction and data harvesting)"
> — Shopify API License & Terms of Use §2.3(14), shopify.com/legal/api-terms
> "You agree not to access the Services or monitor any material or information from the Services using any robot, spider, scraper, or other automated means."
> — Shopify Terms of Service §1.9, shopify.com/legal/terms

`/products.json` is undocumented (not in Shopify's dev docs) → the breach is **ToS/contract** (API ToU §2.3(14)), **not** copyright: images are **hot-linked** so the brand's photos are never copied (no reproduction), and the data (title/price) is uncopyrightable facts. **Crux:** the creator is usually an *affiliate*, not the merchant, so only the merchant can grant Storefront/Admin API access — no clean automated path for arbitrary affiliate products. Mitigating factors: the endpoint is unauthenticated/public and images are hot-linked, so this is a contract/brittleness issue rather than a copyright or privacy one — hence **MEDIUM**, not HIGH.
**Sanctioned path:** merchant-installed Shopify App (doesn't scale to affiliates) / Collabs (closed to new creators) / **manual entry + affiliate deep-links + hot-linked (not copied) images**. **Difficulty: HIGH automated / trivial manual.**

### 3.4 Eventbrite — 🟡 MEDIUM (new)
**Code (re-verified against current source):** `EventbriteScraper.php` — fetches the **public** organiser page (`eventbrite.com/o/<slug>`), regexes out the `/e/<slug>` event links, then fetches **each public event page** and parses its **JSON-LD** (`startDate`, `offers`, `image`); spoofed browser UA. Events are **hot-linked + deep-linked** (no re-host). *(Correction: this is public-page + JSON-LD scraping — like the YouTube approach — NOT the `/org/{id}/showmore/` internal endpoint an earlier draft described.)*

**T&C breached** — verified verbatim 2026-06-01 **(HIGH — exact text retrieved)**:
> "You have no right to use, and you agree not to use, any Site Content for your own commercial purposes. You have no right to, and you agree not to, scrape, crawl, or employ any automated means to extract data from the Sites."
> — Eventbrite Terms of Service §13 ("Scraping or Commercial Use of Site Content is Prohibited")
§13 is the on-point breach — automated extraction of data from the public pages is exactly what the JSON-LD scrape does. (The API Terms of Use also bar use "inconsistent with Eventbrite API documentation" (§3.6(8)) and require "prior, clear, express consent from each User whose Content you access" (§4.2) — i.e. the sanctioned route is the documented v3 API, not page-scraping.)

Net risk is **MEDIUM**: the data is factual and images are hot-linked (no copyright reproduction), but scraping the public pages + JSON-LD with a spoofed UA is a clear §13 ToS breach and brittle — for **zero benefit** given the free official API.
**Sanctioned path:** **Eventbrite API v3 (free, OAuth)** → `GET /organizations/{id}/events/`. Caveat: Eventbrite **removed public event search in 2019**, so the *organiser connects their own account via OAuth* — which fits the "creator connects their accounts" model perfectly. Official **Embedded Checkout** widget also exists. **Difficulty: EASY (OAuth).**

### 3.5 Stan — 🟡 MEDIUM
**Code:** `StanController.php` — unauthenticated `api.stanwith.me` user + store endpoints; stores `socials.mail_to` (creator email); product images hot-linked (good).

**T&C breached:** Stan's ToS **could NOT be retrieved verbatim** — it's published as a PDF (assets.stanwith.me/legal/terms-of-service.pdf) the research tooling couldn't text-extract, and no HTML version was reachable. In substance, Stan's ToS prohibits scraping / automated data collection (standard SaaS acceptable-use), but **the exact clause must be pulled manually from the PDF before any formal/legal use. (UNVERIFIED — re-pull required.)**

**Email is low-risk** — it's the creator's own, the creator consents; the **Spam Act 2003 harvesting provisions (ss20–22) do NOT apply** (they need address-harvesting *software* + bulk collection; one consensual address isn't harvesting). Privacy Act APP 3/5 satisfied if collection is necessary, disclosed in the privacy policy, not repurposed. The real (modest) issue is hitting an undocumented internal API (ToS).
**Sanctioned path:** no public API → **manual entry + deep-link**. **Difficulty: trivial.**

### 3.6 YouTube — 🟢→🟡 LOW–MEDIUM (cheapest fix)
**Code:** `YoutubeScraper.php` — scrapes the channel **HTML page** for the channel ID, then the **public RSS feed** (`feeds/videos.xml`); thumbnails hot-linked from `i.ytimg.com`.

**T&C breached (the HTML-scrape step):**
> "access the Service using any automated means (such as robots, botnets or scrapers) except (a) in the case of public search engines, in accordance with YouTube's robots.txt file; or (b) with YouTube's prior written permission" — YouTube ToS **(HIGH)**

Hot-linked thumbnails + deep-links = low risk. RSS is a grey zone (tolerated, but the automated-access clause has no RSS carve-out); the **HTML channel-page scrape is the clear breach**.
**Sanctioned path — free + easy:** YouTube **Data API v3** (API key, no OAuth): `channels.list` → `playlistItems.list` ≈ **3 quota units/refresh** (never `search.list` at 100); keep hot-linking thumbs / IFrame embed. **Difficulty: TRIVIAL.**

### 3.7 Apple — 🟢→🟡 LOW–MEDIUM
**Code:** `AppleSearch.php` + `ITunesExtractor.php` — **public iTunes Search/Lookup API** (no key); cover art hot-linked from `mzstatic` (upscaled by URL rewrite).

Apple's Media Services T&Cs §F say (verified verbatim **HIGH**): "You may not use any software, device, automated process … to scrape, copy, or perform measurement, analysis, or monitoring of, any portion of the Content or Services," and "You may use the Services and Content only for personal, noncommercial purposes." — **but the iTunes Search API is itself an Apple-provided, publicly-documented interface intended for third-party use**, so this is the *least-bad* integration (the anti-scrape clause targets scraping the storefront, not calling the Search API). Residual: the Search API's terms are affiliate-oriented (display near a store badge/link; ~20 req/min) and the art-URL upscaling is a minor "no modification" nit (don't cache the upscaled file).
**Sanctioned path:** keep the API; add a "Listen on Apple Music" badge + direct link; optionally join the free affiliate program. **Difficulty: TRIVIAL.**

### 3.8 TikTok / Facebook — 🟢 LOW
Link-only (store username/URL). Correct. To *display* TikTok later, the Display API (Login Kit OAuth) is the sanctioned path.

### 3.9 SmartLinks — 🟢 mostly green, 🟠 one gap
oEmbed (Spotify/YouTube/Vimeo/Bandcamp) + iTunes Lookup + OG reads + SSRF-guarded fetcher + hot-linking content art = sanctioned. **Spotify caveat** (verified verbatim **HIGH**): User Guidelines bar "'crawling' or 'scraping', whether manually or by automated means, or otherwise using any automated means (including bots, scrapers, and spiders), to view, access or collect information," and Developer Terms §IV.3.1(a) bars "store, aggregate or create compilations or databases of Spotify Content, other than as strictly necessary to operate your SDA" — so the oEmbed path is fine, the OG-tag page-read is low-risk-but-technically-covered, and **don't compile Spotify content into a DB**. **Gap:** `SmartLinkImageService::ingestCommerce()` — invoked by the **paste-a-custom-link flow** (`UserSmartLinkController` / `SmartLinkRefresher`), *not* the Platforms Shopify scraper — **re-hosts** commerce images (product/brand/collection) to R2. This is the one place a brand's product photo is actually *copied*, so the copyright-reproduction concern lives **here**, not in the Shopify brand-products integration (which hot-links). Extend the "hot-link, don't copy" rule to commerce, or rely on a brand/affiliate licence.

---

## 4. Risk once we SCALE — the core argument

Scraping liability is **inversely correlated with your ability to absorb it**: invisible while tiny, scrutinised when valuable. Enforcement follows a ladder — **technical block → account/app termination → cease-and-desist (in the US a C&D converts further access to "without authorization" under the CFAA) → litigation** — and platforms **litigate commercially meaningful targets, not pre-revenue startups** (Meta's defendants were all funded/commercial: BrandTotal ~$12M+ raised, Bright Data large-scale, Octopus a commercial vendor).

**Detonation points, by likelihood:**
1. **Technical block / C&D** (rising with scale) — the feature breaks overnight; you rebuild on the API you should have used.
2. **Copyright claim on re-hosted images** (accrues silently with every IG/Shopify mirror).
3. **Privacy complaint** — a non-consenting third party in a re-hosted IG photo, or a creator, complains to the OAIC ("publicly available" is not a defence — *Clearview*); the **statutory privacy tort (from 10 June 2025)** lets individuals sue directly.
4. **Due-diligence failure** — *the big one*. Data provenance is a standard diligence item; ToS-violating sourcing forces a false IP warranty or a disclosed exception → repricing, escrow holdbacks, indemnities, **R&W insurance carve-outs**, or a dead deal. The bill comes due exactly when you crystallise value.

**Probability by stage (honest, evidence-based):**

| Stage | Platform lawsuit | C&D / block | Privacy complaint | Copyright claim | **Diligence problem** |
|---|---|---|---|---|---|
| Pre-launch / tiny | Low | Low–Med | Low | Low | n/a |
| Growing / visible | Low–Med | **Med–High** | Med | Med | Low |
| Fundraise / acquisition | Med | Med–High | Med | Med–High | **HIGH** |

**Verified exposure figures:**
- **OAIC penalties** (serious/repeated interference, since Dec 2022): the **greater of A$50M / 3× the benefit / 30% of adjusted turnover** — and the OAIC can order you to **cease collection and destroy the data** (as it did to Clearview), which is existential for a scraped-photo feature. *(A$50M is a statutory maximum, not a prediction.)*
- **Copyright damages — corrected:** **the US "$150,000 per work" is a *ceiling*** (17 U.S.C. §504(c)) requiring US jurisdiction + timely registration + proof of willfulness. **Australia has NO statutory damages** — exposure is compensatory damages (s115(2), actual loss / reasonable licence fee) **plus additional damages for flagrancy** (s115(4), court discretion), or an account of profits. Separate **criminal** penalties exist for commercial-scale infringement (this is what v1's "~$585k" figure actually was). Bottom line: deliberate, commercial re-hosting is exactly the "flagrant" conduct s115(4) targets.
- **Apify is not a shield — it bills you:** "You are responsible for your use of the Apify Platform and any Actors you use, and for ensuring that such use complies with all applicable laws … as well as any third-party rights, including the terms of service of any website … you access" + an indemnity requiring you to "indemnify, defend and hold harmless Apify … from … any … claims … arising out of … your violation of any third-party right." (docs.apify.com/legal/general-terms-and-conditions, **MED-HIGH**).

**The dangerous misconception:** "we haven't been sued, so we're fine." The lawsuit risk is back-loaded; the **architecture fragility, the silently-accruing copyright/privacy liability, and the diligence reckoning** are what actually determine the outcome.

---

## 5. The convincing argument (why not this direction)

1. **You don't own what you scrape.** Re-hosted photos are someone else's copyright; "we just display it" changes nothing, and Australia has no fair-use safety valve.
2. **Apify doesn't shield you — it bills you.** Its terms make *you* responsible and require *you* to indemnify *it*. The vendor is a liability pass-through.
3. **Fresha is a different category.** Forging Fresha's own first-party client to hit a private API edges from "aggressive scraping" toward **unauthorised access** (CFAA + Criminal Code s478.1) — not a civil-ToS-only risk.
4. **It's brittle by construction.** Pinned query hashes, undocumented endpoints, client-version headers — your product breaks on the platform's release schedule, not yours.
5. **The data comes due in diligence.** "Where did this data come from?" is asked under oath, in writing, with indemnities attached — at the worst possible moment.
6. **The alternative is cheap.** Most outcomes are achievable via official API / oEmbed / manual-entry + deep-link — often trivially (next section).

---

## 6. How easy is the API path? (sanctioned outcomes per platform)

| Platform | Sanctioned path achieving the same outcome | Difficulty |
|---|---|---|
| **YouTube** | Data API v3 (API key) for channel id + uploads; IFrame embed; hot-link thumbs | 🟢 Trivial (same day) |
| **Apple** | Already on iTunes Search API; add "Listen on Apple Music" badge + link | 🟢 ~Already compliant |
| **Spotify** | oEmbed / embed widget from creator-supplied URL | 🟢 Trivial |
| **Eventbrite** | API v3 — organiser connects via OAuth → `/organizations/{id}/events/`; or Embedded Checkout | 🟢 Easy (OAuth) |
| **Stan** | No API → manual entry + deep-link | 🟢 Trivial (manual) |
| **TikTok** | Display API + Login Kit OAuth (already link-only) | 🟡 App review (weeks) |
| **Facebook** | Graph API (Pages) / `user_posts` (OAuth) | 🟠 App review (sensitive) |
| **Square** *(if added)* | Bookings + Catalog API — pro is the seller, self-authorizes | 🟡 Moderate |
| **Fresha** | Partner API, or manual entry + booking deep-link | 🟠 Partner / 🟢 manual |
| **Instagram** | Graph API w/ Instagram Login (Pro accounts only) | 🔴 Hard (App Review + Business Verification) |
| **Shopify (affiliate)** | Merchant-installed app (doesn't scale) / manual entry + affiliate deep-link | 🔴 automated / 🟢 manual |

**Net:** YouTube, Apple, Spotify, Eventbrite, Stan are all easy/cheap to do properly **today**. TikTok/Facebook/Instagram need app review but are doable. Only Shopify-affiliate and Fresha lack a clean automated API — and those are exactly where manual-entry + deep-link is the honest, safe design. Wherever the **creator can authorize their own data**, a sanctioned path exists.

---

## 7. Citation-integrity / anti-hallucination log

**Round-1 DeepSeek errors corrected (carried forward):**
- s478.1 vs s477.1: DeepSeek rated Fresha's AU-criminal exposure "Low" by confusing s478.1 (standalone unauthorised access, 2 yrs — *applies*) with s477.1 (needs intent to commit a further serious offence). **Corrected.**
- Privacy-tort statute misnamed → it's the **Privacy and Other Legislation Amendment Act 2024**; tort commenced **10 June 2025**.
- hiQ procedural history garbled → the **April 2022** 9th Cir. decision **reaffirmed** public-data scraping isn't CFAA "without authorization."
- *Cooper v Universal Music* is an **authorisation-liability** case (Cooper *lost*) — do not cite as "linking is safe"; linking is safe here because destinations are **legitimate**.

**Round-2 correction:**
- **Copyright damages:** Australia has **no statutory damages**; v1's "~$585k per infringement" was the *criminal* penalty. AU civil = compensatory + additional damages for flagrancy (s115(4)). US $150k/work is a registration/willfulness-gated ceiling. **Corrected in §4.**

**Quote confidence (corrected after live retrieval 2026-06-01):**
- **HIGH — exact text retrieved:** Meta ToS §3.2, Meta Automated Data Collection Terms, **Fresha** Terms of Use (4 clauses), **Shopify** API ToU §2.3(14) + Merchant ToS §1.9, YouTube ToS, Apple Media Services §F, Spotify User Guidelines + Developer Terms §IV.3.1(a), **Eventbrite** ToS §13 + API ToU §3.6(8)/§3.1/§4.2. *(Fresha/Shopify upgraded from MED→HIGH after the live pull.)*
- **COULD NOT verbatim-verify (do not present as quotes):** the **Instagram-specific** ToU page (Help Center truncated it — but Instagram is governed by the verified Meta ToS §3.2) and **Stan** ToS (published as a non-extractable PDF; re-pull from assets.stanwith.me/legal/terms-of-service.pdf).
- **Pending-not-precedent:** *Google v. SerpApi*, *Reddit v. Perplexity* (2025 DMCA §1201 filings).

**Verified-accurate authorities:** *Van Buren* 593 U.S. 374 (2021); *Meta v. Bright Data* (N.D. Cal. 2024, Chen J.); *Facebook v. Power Ventures* 844 F.3d 1058 (9th Cir. 2016); *Craigslist v. 3Taps* (N.D. Cal. 2013); *Ryanair v. Booking Holdings* (D. Del. 2022); *Perfect 10 v. Amazon* 508 F.3d 1146 (9th Cir. 2007); *Goldman v. Breitbart* 302 F. Supp. 3d 585 (S.D.N.Y. 2018); *Feist* 499 U.S. 340 (1991); *Clearview AI* [2021] AICmr 54; *IceTV* [2009] HCA 14; *Telstra v. Phone Directories* [2010] FCAFC 149; OAIC penalty limbs (A$50M / 3× / 30% turnover); 17 U.S.C. §504(c); Copyright Act 1968 (Cth) ss 115(2)/(4); Apify General T&Cs.

---

## 8. Remediation order

1. **Kill the Fresha GraphQL impersonation now** (highest criminal/CFAA exposure + brittle).
2. **Stop Instagram Apify scraping + photo re-hosting**; delete re-hosted third-party photos.
3. **Stop Shopify `/products.json` + image re-hosting**; manual entry + affiliate deep-links, hot-link images.
4. **YouTube → Data API v3**; **Eventbrite → API v3 (organiser OAuth)**; **Stan → manual entry** — all cheap, kill the remaining ToS breaches.
5. Extend the SmartLinks "hot-link, don't copy" rule to commerce images.
6. Cross-cutting: never re-host copyrighted media without a licence; get a creator copyright-licence + rights warranty in onboarding ToS; don't imply platform affiliation (ACL s18); Australian counsel sign-off before launch.

## 9. Overall viability verdict

The aggregation model is viable — **on the `SmartLinks` philosophy, not the `Platforms` scrapers.** Built as official APIs / OAuth / oEmbed + hot-linking + manual entry + deep-links, it works and is defensible. Built as Apify scraping + photo re-hosting + private-API impersonation + undocumented-endpoint scraping, it is brittle, transfers liability to you, accrues non-waivable copyright/privacy exposure, and **detonates in diligence** — concentrated exactly where you're most visible at success. Partna already wrote the green path; the job is to make `Platforms` follow it. Switch now, while the cost is a few weeks of engineering rather than a repriced round.
