- [ ] **SEC-1** · P2 — Cloudflare Worker 301 open redirect – alias target not validated
    - **Where:** cloudflare-worker/src/index.js ~ the alias redirect block
    - **Affects:** Visitors following a stale subdomain alias — a poisoned KV entry could redirect them to an arbitrary origin.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Validate that `entry.redirect` starts with `https://` and the host ends with `.partna.au` (or exactly match `*.partna.au`), rejecting the redirect otherwise.
        - Ensure `SyncSubdomainToKvJob` writes only validated redirect URLs, making the worker validation a defence-in-depth layer.
    - **Technical:** The Worker is the first point of contact for all sitepage traffic. An alias entry written by the single KV writer (`SyncSubdomainToKvJob`) is expected to be a canonical `https://<handle>.partna.au` origin, but the Worker does not verify this. If an attacker manages to write a KV entry with `{"type":"alias","redirect":"https://evil.com"}`, any request to that subdomain would 301‑redirect the visitor to `https://evil.com/…` (full path + query) without any domain whitelisting. In the browser this is a permanent redirect; it can be cached and used for phishing.
    - **Plain English:** Imagine the help desk re‑routing your call. The worker trusts the note left by the backend to say “send this visitor to desk B.” If someone sneaks in a note that says “send them to an outside line,” the worker blindly does it. We need to check that the destination is always one of our own desks.
    - **Evidence:**
        ```js
        if (entry.type === "alias" && typeof entry.redirect === "string") {
          // Preserve the deep link …
          const target = `${entry.redirect.replace(/\/$/, "")}${url.pathname}${url.search}`;
          const h = new Headers({
            Location: target,
            "Cache-Control": "max-age=0, must-revalidate",
          });
          applySecurityHeaders(h);
          return new Response(null, {status: 301, headers: h});
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **SEC-2** · P3 — SmartLinkImageService logs potentially signed image URLs
    - **Where:** app/Services/SmartLinks/SmartLinkImageService.php ~ the `rehost` method catch block
    - **Affects:** Log retention – an image source URL containing a temporary token or signature could be persisted in Nightwatch / log aggregators.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Redact query strings (or the entire URL) from the log context, or hash the URL like `md5($source)`.
        - Alternatively, log only the host and path without the query portion.
    - **Technical:** When commerce‑family image re‑ingest fails, the catch block writes the raw `$sourceUrl` into a `Log::warning` entry. Some CDN‑signed image URLs (e.g., temporary Shopify `?v=` params, or signed S3 URLs) can carry short‑lived access tokens. Log aggregators like Nightwatch retain these records outside the normal GDPR erasure pipeline, so a token that lives in logs could outlive the token’s intended lifetime. The risk is low because most source URLs are public, but a single leak of a signed URL is irreversible once ingested into log storage.
    - **Plain English:** Our logbook sometimes copies down the full web address of an image it tried to fetch. If that address had a “temporary pass” in it (like a one‑time ticket), the ticket is now written down forever. We should only note the door number, not the ticket.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('SmartLink image rehost failed', [
                'source' => $sourceUrl,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
        ```
    - `[DRAFT, confidence: 0.6]`

- [ ] **SEC-3** · P2 — MetadataParser missing LIBXML_NONET flag allows XXE on user‑fetched HTML
    - **Where:** app/Services/SmartLinks/MetadataParser.php: `$dom->loadHTML(...)` inside `parse()`
    - **Affects:** Any smart‑link or platform scraper that passes user‑pointed HTML through the parser — an attacker hosting a malicious page could attempt XML External Entity injection.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `LIBXML_NONET | LIBXML_NOENT` (or `LIBXML_NONET` with entity substitution disabled) to the `loadHTML` call to block network access during parsing.
        - Ensure the same flag is used wherever `DOMDocument` is created in the codebase (currently only this class is affected; `PinterestScraper` already uses safe flags for `SimpleXML`).
    - **Technical:** `MetadataParser::parse()` loads third‑party HTML with `$dom->loadHTML(...)` and no option flags. Without `LIBXML_NONET`, the parser may resolve external entities defined in the document, allowing a crafted HTML page to trigger outbound HTTP requests (XXE → SSRF) or read local files (in older libxml versions). While PHP ≥ 8.0 disables external entity loading by default at the libxml level, explicitly passing the safe flags is the defence‑in‑depth pattern used elsewhere in the project (e.g., `PinterestScraper` with `LIBXML_NONET`). The parser processes every fetch of an oEmbed/Open‑Graph page and every commerce page scrape, so the attack surface is wide.
    - **Plain English:** We hand a stranger’s letter to an envelope‑opening machine. By default the machine is safe nowadays, but we haven’t locked its “make a phone call” feature. The envelope could contain a hidden instruction to dial a private number inside our building. Locking that feature costs nothing and makes sure a future firmware downgrade doesn’t reopen the risk.
    - **Evidence:**
        ```php
        $dom = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        // Force UTF-8 so multibyte titles/og survive parsing.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        ```
    - `[DRAFT, confidence: 0.7]`
