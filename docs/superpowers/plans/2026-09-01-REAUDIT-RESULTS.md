# Re-audit results — 2026-09-01

The overnight golden-standard pass, measured. A fresh 23-account cold batch was dispatched on the
fixed code (2026-08-31 21:59 UTC, backend `d4eecb42b`), then seven sweeps read the logs, the wire,
the live pages, the database and Nightwatch, and every finding from the original 2026-08-31 audit
got a verdict. Rule enforced throughout: **a claim of FIXED without live evidence is NOT MEASURED.**

Full untruncated evidence: the Wave 6 task output (`wusdiwedb`) and its journal
(`subagents/workflows/wf_d29c7398-51c/journal.jsonl`). Running ledger: `2026-08-31-OVERNIGHT-BACKLOG.md`.

---

## The batch

- 23 specs (12 partna/Instagram chosen to stress name derivation; 11 business incl. 4 with ordering
  platforms and 4 with unusual Google categories). **23/23 ready, zero failures, zero rejected specs.**
- Instagram builds 14–58s each; Google Business 2–6s. Last ready at +6m43s; scraping queue drained
  23 minutes later. Peak queues: scraping 69, media-mirror 800, cloudflare 79.
- Failures during the window: CloudflareCachePurgeJob ×12, ProcessLogoVariantsJob ×2 (SVG 422,
  fell back), media_mirror.failed ×32 (all TikTok CDN).

---

## Verdicts — the original 22 findings

| id | finding | verdict | evidence (abridged) |
|---|---|---|---|
| F1 | Platform tiles beacon a rejected item type (422/view) | **FIXED** | Zero `data-item-type="platform"` on live renders; tiles stamp the action contract |
| F2 | Pending/failed build publishes a named empty page | **PARTIAL** | Failed builds 404 at the edge; wire carries `buildState`; the pending window itself remains |
| F3 | Name derivation wrong (37/84) | **STILL OPEN** | **The fix does not exist at HEAD** — `NameShapeGate` 0 hits; 8/12 fresh IG accounts wrong ("The Edit", "Tension Music" as person names) |
| F4 | Phone reaches JSON-LD only, no tel: link | **FIXED** | tel: links render on live pages for both account types |
| F5 | 68/209 accounts sector-null | **STILL OPEN** | Worse: 79/238. All four unusual-category builds got NULL or wrong sector |
| F6 | YouTube empty-handle refresh loop | **PARTIAL** | No new failures in the 61-min window; two legacy rows remain |
| F7 | facebook profile.php dead ingest source | **STILL OPEN** | Username `'p'` stored from a `/p/` path on a fresh build |
| F8 | Uber Eats brand URL provisions a dead menu source | **STILL OPEN** | **A `/brand-city/` URL evaded the `/brand/` guard** (schnitz, fresh tonight) |
| F9 | Malformed JSON-LD address, national phone | **FIXED** | Complete PostalAddress on 6/6 businesses read; international phone |
| F10 | Boilerplate meta description | **FIXED** | Real bio serves as description on both account types |
| F11 | TikTok thumbnails 403 → blank tiles | **PARTIAL** | Mirrored on most accounts; TikTok CDN still failing on some (32 mirror failures in-window) |
| F12 | Two tests fail only under --parallel | **NOT MEASURED** | Suite-side; no production sweep can see it |
| F13 | CloudflareCachePurgeJob failures | **STILL OPEN** | 12 terminal failures 22:30–22:42, all MaxAttemptsExceeded |
| F14 | Logo variants inconsistent | **STILL OPEN** | SVG-undefined-size 422 is now the terminal mode (×8 in-window) |
| F15 | No services connector (Booksy/Cliniko/NowBookIt/Timely) | **PARTIAL** | Booksy + Treatwell connectors mapped; Cliniko/NowBookIt not |
| F16 | fleet:new half-runs a batch | **FIXED** | 23/23 dispatched whole; all-or-nothing held |
| F17 | Instagram-only business cannot be built | **STILL OPEN** | Pairing map unchanged (109/109 instagram→partna) — owner decision, never scheduled |
| F18 | Sector front pushes `home` out of first place | **STILL OPEN** | 17/30 sampled profiles do not lead with home — wider than the sector-front case |
| F19 | No business build gets a contact email | **STILL OPEN** | `profile.contact` null on 8/8 fresh business wires |
| F20 | Thin build indistinguishable from good | **STILL OPEN** | `buildState` separates ready/failed and nothing else (sixpenny: "ready", near-empty) |
| F21 | Placeholder accounts business/business1 | **PARTIAL** | Dark at the edge by design; rows still exist |
| F22 | Null sector silently revokes menu capability; OCR bails unlogged | **STILL OPEN** | `capability_denied` log does not exist at HEAD; the class reproduced on Pret-alikes |

## Verdicts — the findings from later in the night

| id | finding | verdict | evidence (abridged) |
|---|---|---|---|
| L1 | Strangers' reviews on named people's pages | **FIXED** | **155 wires enumerated card-by-card: 785 cards, zero violations** |
| L2 | Venue aggregate badge (salon 5/174 on a cafe) | **FIXED** | Every person-scoped badge is `scope:'published'`, each recomputed from its own cards and matching |
| L3 | Mirror-folder collision (one account's face on another) | **FIXED** | 209 Instagram connections → 209 distinct uuid-keyed folders; legacy pairs repaired |
| L4 | 24h edge cache vs 30s intent | **STILL OPEN** | Bigger than described — see New defects №1 |
| L5 | Dead section observer | **NOT MEASURED** | No sweep drove a browser (fix deployed; unverified live) |
| L6 | Three dead analytics beacons | **NOT MEASURED** | Same — no browser traffic in the window |
| L7 | RUM trust boundary | **NOT MEASURED** | Same |
| L8 | Privacy policy understating tracking | **NOT MEASURED** | Same; adjacent find — policies serve on pages that should be dark |

**Tally: 8 FIXED · 5 PARTIAL · 10 STILL OPEN · 6 NOT MEASURED** (of 22 original + 8 later).

Note on F3/F5/F22: their build units (Wave 4's name-derivation and sector chain) had not landed
when the batch ran — the re-audit measured their absence, correctly.

---

## New defects the re-audit surfaced (31; the ones that matter first)

### High
1. **141 of 230 live sites link a stylesheet that 404s after a pages deploy** — asset-hash churn ×
   the 24h edge cache. Sites render unstyled for up to a day. The biggest visitor-facing defect on
   the platform; the durable fix is the E11 cache-rewrite root cause + purge-on-deploy.
2. **harper-blohm-cheese-shop publishes another company's identity**, and its Website field resolves
   to gambling spam.
3. **Nightwatch has recorded nothing since 2026-08-30 23:00** — exception/slow-job telemetry dark;
   "no new issues" has meant nothing for two days.
4. **The five deliberately-dark handles serve complete payloads on the wire** (edge 404s, API does not).
5. **OCR menu lane returned zero items on 7/7 fresh restaurants** despite finding menu-dense photos
   every time; **25/78 food-sector accounts have no menu at all.**

### Medium (selected)
- ~12 concurrent sitepage renders exhaust the Supabase session pool → public 500s (matches the
  Wave 5 pooler finding; pooler must move to transaction mode).
- **Seven sitepages publish a byte-identical og:image belonging to another handle** — the share-image
  variant of the folder-collision class; needs the same uuid keying.
- 28 business sitepages hotlink Google's place-photo CDN as og:image (expiring, hotlink-protected —
  same class as the raw-Instagram gallery bug E14).
- Three live sites render the literal word `gallery` as an entire page body (presence gate vs render
  derivation disagreement).
- agnes-restaurant holds six processed gallery images the wire never emits — stale payload, nothing
  knows.
- drsleek withholds its whole review corpus incl. 4 with the venue's own staff attribution naming the
  owner (over-suppression edge of L1's fix — short-form name in prose doesn't clear the scope).
- 'Sports School' → gym (keyword order); Instagram categories naming an existing slug still → NULL.
- The +61 phone transform mangles 13/1300 numbers; grilld's og:image is 88×88.
- `scripts/logs/window.py` can deadlock against the cloud CLI and silently truncate windows it
  reports COMPLETE.

### Low (selected)
- Contact nav opens a blank sheet on 30 current-build sites; 35/123 partna accounts still headshot-less
  (20 backfillable today); lnk.bio blocks the scraper; two mail-webhook misconfigs.

Full list of 31 with untruncated evidence: Wave 6 task output.

---

## Closing assessment (verbatim from the re-audit)

Of the 22 original findings, 5+3 are genuinely fixed with live evidence, and those are the strongest
work of the night — 155 wires read card by card with zero attribution violations, five badges
recomputed and matching exactly, 209 connections in 209 distinct folders. Ten are still open, F3/F5/F22
because their fixes never landed. The edge-cache item is not merely still open — it is bigger than
anyone thought (the 141-site stylesheet 404). The four telemetry items are unmeasured because no sweep
drove a browser. And Nightwatch has been blind since 2026-08-30 23:00, so absence of new issues proves
nothing. For a visitor, the worst remaining failure is a site that renders unstyled or empty for up to
a day after a deploy; for an owner, it is a page published in their name whose content they cannot see,
curate, or switch off.

---

## What was additionally shipped AFTER the batch ran (so not measured above)

- Staff identity split: staff-only `/me` session, provisioning guard, staff-route audit
  (`219020b04`, `187d1391a`, +route-audit commit); ceo@dolustech.net's junk user row + site purged
  with the shared auth identity preserved. Live.
- The storewide-projection review fix (`da958493e`).
- The buildState wire key + fault-degradation (`e1b26d6ba`, `baa91c965`). Live.
- The bydannydixon gallery purge (E14 diagnosed: build-time edge render serving raw Instagram URLs).
