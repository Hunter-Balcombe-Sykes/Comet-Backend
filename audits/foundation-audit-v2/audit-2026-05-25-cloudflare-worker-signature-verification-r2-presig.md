The evidence is definitive. Now I can write the final adjudicated audit.

**Summary of adjudication decisions:**

- **CLD-1 (Draft) — DROPPED.** Confidence 0.6 (below 0.7 threshold) and the claim is factually wrong. `MediaVariant::getUrlAttribute()` uses a fast-path string concat from `config("filesystems.disks.{$disk}.url")`, and falls back to `$adapter->url($this->path)` — never `temporaryUrl()`. The draft's hypothesis was speculative and unverified.

- **CLD-1 (New — DeepSeek miss) — P1.** Confirmed via `Read` on both files: `SyncSubdomainToKvJob` writes `['type' => 'alias', 'target' => $current]` (line 93), but the Cloudflare Worker checks `entry.redirect` (lines 80, 84). Field name mismatch + value type mismatch (handle name vs full URL) means handle-alias 301 redirects silently fail at the edge for every professional who renames their handle.

`★ Insight ─────────────────────────────────────`
This is a classic interface contract bug across a language boundary (PHP ↔ JS). Because KV is an opaque blob store, the compiler can't catch field name mismatches — the backend writes `target`, the edge reads `redirect`, and both sides compile/lint clean. The symptom is silent pass-through (not an error), making it easy to miss in testing.
`─────────────────────────────────────────────────`

---

# Cloudflare Worker / R2 Media Audit — 2026-05-25

**Branch:** development
**Lens:** Cloudflare worker signature verification, R2 presigned URL leakage, public bucket scope
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- cloudflare-worker/src/index.js
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Services/Cloudflare/CloudflareKvService.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Models/Core/MediaVariant.php
- app/Services/Media/ImageVariantService.php
- app/Services/Media/VideoVariantService.php
- app/Services/Media/MediaUploadService.php
- app/Services/Media/MediaDiskResolver.php
- app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#CLD-1** · P1 — KV alias field mismatch: handle-rename 301 redirects silently dead at edge
    - **Where:** cloudflare-worker/src/index.js:80–84 / app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:93
    - **Affects:** Every professional who has ever renamed their handle. Old `<handle>.partna.au` URLs do not 301 to the new canonical URL — they fall through the worker to the Laravel origin and return the wrong response (or 404). Visitors and search-engine link equity for old handles are silently dropped.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `SyncSubdomainToKvJob::writeAliasEntries()`, change the KV value from `['type' => 'alias', 'target' => $current]` to `['type' => 'alias', 'redirect' => "https://{$current}.partna.au/"]`. The worker JSDoc and the check on line 80 both require the field to be named `redirect` and to be a full `https://` URL.
        - Add a defensive guard in the worker after `entry.redirect` is validated: confirm the URL starts with `https://` and ends with `.partna.au` before setting the `Location` header. This closes an open-redirect path if KV credentials were ever compromised (`entry.redirect` is currently written raw to `Location` with no domain check).
        - After deploying, verify in KV that a renamed professional's old handle entry contains `{"type":"alias","redirect":"https://newhandle.partna.au/"}` and that a browser following the old URL receives HTTP 301.
    - **Technical:** The worker at line 80 branches on `entry.type === "alias" && typeof entry.redirect === "string"`. The backend writes `{type: "alias", target: "<handle>"}` — `entry.redirect` is `undefined`, the condition is false, and the worker falls through to `return fetch(request)` (the unknown-entry passthrough at the bottom). No 301 is ever issued. The mismatch is two-fold: the field is `target` not `redirect`, and the value is a bare handle name (`"newjohn"`) rather than a full URL (`"https://newjohn.partna.au/"`). The worker's own JSDoc block and CLAUDE.md both specify the alias format as `{type:"alias", redirect:"https://…"}`, confirming the worker is correct and the job is wrong. The fix should land in the job, not the worker.
    - **Plain English:** When someone changes their public profile address from `oldjohn.partna.au` to `newjohn.partna.au`, the system is supposed to leave a forwarding note at the old address — like a postal redirect card — so anyone visiting the old URL automatically lands at the new one. Right now the forwarding card has the wrong label on it. The traffic cop at Cloudflare looks for a card labelled "redirect" but the backend wrote one labelled "target". The cop doesn't recognise it, shrugs, and sends the visitor to the wrong door. Anyone who bookmarked the old URL, linked to it, or finds it via Google gets nothing useful instead of a seamless forward.
    - **Evidence:**
        ```php
        // SyncSubdomainToKvJob.php:93 — backend writes field name "target"
        $kv->put($handle, ['type' => 'alias', 'target' => $current], $ttl);
        ```
        ```javascript
        // cloudflare-worker/src/index.js:80 — worker reads field name "redirect"
        if (entry.type === "alias" && typeof entry.redirect === "string") {
          return new Response(null, {
            status: 301,
            headers: {
              Location: entry.redirect,
              "Cache-Control": "max-age=0, must-revalidate",
            },
          });
        }
        ```
        ```javascript
        // cloudflare-worker/src/index.js — JSDoc contract for alias entries
        // *   - { type: "alias", redirect: "https://…" }    → 301 to canonical subdomain URL
        ```
