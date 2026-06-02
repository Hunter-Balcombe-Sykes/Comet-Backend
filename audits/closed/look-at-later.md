# Look at later — outstanding audit findings

**Created:** 2026-05-17
**Source plans:**
- `audit-2026-05-15-full.md` (Stripe Connect full audit)
- `audits/embedded-rework-2026-05-15/remediation-plan.md` (embedded rework consolidated)

These are the two remaining unaddressed findings across the three 2026-05-15 audit plans. Everything else in those plans is fixed and shipped (37 of 39 unique code findings closed, plus Pattern A Step 5 scheduled command from commit `80daa439`).

---

## #SCALE-4 · P2 — One Stripe API call per payout in `forBrand`/`forAffiliate`; limit=500 on export path

**Source:** `audit-2026-05-15-full.md`

**Where:** `app/Services/Stripe/StripeTransactionFetcher.php:42–44` (`forBrand`), `84–86` (`forAffiliate`); `app/Services/Stripe/ExportService.php:38–39` (`exportTransactions`)
**Affects:** The `/stripe/exports/transactions.{csv|xlsx}` endpoint — up to 500 sequential Stripe API calls per export request, risking request timeouts (~100–250s total) and degraded Stripe API throughput for all platform tenants.
**Effort:** L (~1–2d)
**What to do:**
- For the export path: push the transaction fetch into a queued job (`ExecuteExportJob` — already noted in `ExportService` comments) and deliver the file via a signed Supabase Storage URL. This is the right long-term fix; the in-process path should be used only for small page-level requests.
- For the interactive transactions endpoint (already cached by `CacheLockService`): the default limit=25 is acceptable at pilot scale — keep as-is but document the throughput ceiling.
- Short-term before the job is built: add a hard cap of 100 on the export path (`'limit' => 100`) and document it clearly in the API error response when truncated.

**Technical:** `StripeTransactionFetcher::scopedPayouts()` returns at most `$filters['limit']` payouts, then `forBrand()`/`forAffiliate()` make one `paymentIntents->retrieve()` or `charges->retrieve()` call per payout — sequential, synchronous, in the request thread. `ExportService::exportTransactions` passes `'limit' => 500`, meaning up to 500 consecutive Stripe API calls, each ~200–500ms, for a ceiling of ~250s. PHP's default `max_execution_time` is typically 30–60s; the response will timeout before completing. Stripe's dashboard rate limit is 100 req/s per secret key; 500 calls in rapid succession from a single request doesn't hit per-second limits but will spike the key's rolling window.

**Plain English:** The transactions export currently works by calling Stripe's servers once for every single payout in the date range, one after another. If a brand has 500 payouts in the period, that's 500 separate phone calls to Stripe — taking minutes — before sending any response to the user. The right fix is to move the export to a background job that runs when no one's waiting, then emails or notifies the user with a download link when it's done.

**Evidence:**
```php
// ExportService.php:38–39 — limit=500 passed to fetcher:
$rows = $role === 'brand'
    ? $this->transactionFetcher->forBrand($pro, array_merge($filters, ['limit' => 500]))
    : $this->transactionFetcher->forAffiliate($pro, array_merge($filters, ['limit' => 500]));

// StripeTransactionFetcher::forBrand — one Stripe call per payout in foreach:
foreach ($payouts as $payout) {
    if (! $payout->payment_intent_id) { continue; }
    $pi = $this->stripe->paymentIntents->retrieve($payout->payment_intent_id, [
        'expand' => ['latest_charge.refunds'],
    ]);
}
```

---

## #SEC-4 · P2 — Exception renderer sets `Access-Control-Allow-Origin: *` on all API error responses

**Source:** `audits/embedded-rework-2026-05-15/remediation-plan.md` (standalone cluster)

**Where:** `bootstrap/app.php:188–192`
**Affects:** All API error responses across every controller (Shopify embedded, Professional, PublicSite, Staff, Webhooks). Today's auth model is keycard-based (Supabase JWT + Shopify session token, no cookies), so the `*` wildcard is low-risk. If a cookie-bearing path is ever added (staff SSO, OAuth callback, magic-link verifier) the wildcard would let any origin read error bodies — including exception messages, file paths, and SQL fragments in debug mode.
**Effort:** S (~0.5–1h)
**What to do:**
- Replace the `'*'` fallback with a check against the request's `Origin` header gated by `config('cors.allowed_origins')` — if the origin is absent or not in the allow-list, omit the header entirely.
- Cleaner alternative: re-run the configured CORS middleware on the error response — `app(\Illuminate\Http\Middleware\HandleCors::class)->handle($request, fn () => $response)` — so the same allow-list logic that runs on the success path also runs after the exception render. This honours the existing comment's reason ("proxy strips CORS headers on some error responses") without resorting to `*`.
- Either way, preserve the `! $response->headers->has('Access-Control-Allow-Origin')` guard so the success-path header is never clobbered.

**Technical:** Laravel's `HandleCors` middleware reads `config/cors.php` and sets `Access-Control-Allow-Origin` to the request's `Origin` header iff it matches the allow-list, otherwise omits the header. When an exception propagates past `HandleCors` to the global render handler in `bootstrap/app.php`, that allow-list logic is lost — and Laravel Cloud's proxy further strips CORS headers from some error responses, prompting this fallback. The current code unconditionally writes `*`, which is the broadest possible setting. A wildcard is incompatible with `Access-Control-Allow-Credentials: true`, so any future cookie-auth path would force a choice between security and the wildcard fallback.

**Plain English:** When the server hits an exception, the error response says "any website on the internet is allowed to read this." Today nothing sensitive sits behind cookies, so attackers' websites can't actually do anything malicious with that permission. But it's a tripwire — the first time someone wires up cookie-based authentication anywhere in the app, this wildcard becomes a real cross-origin data leak. The fix is to honour the same allow-list on errors that the success path already uses.

**Evidence:**
```php
// bootstrap/app.php:182–192 — current code
// Ensure CORS headers are present on all API error responses.
// HandleCors middleware adds these during normal flow, but when
// an exception propagates past it the rendered response skips
// the CORS header injection. Laravel Cloud's proxy also strips
// CORS headers on some error responses. This guard ensures the
// browser can always read the error body.
if ($response !== null
    && ! $response->headers->has('Access-Control-Allow-Origin')
) {
    $response->headers->set('Access-Control-Allow-Origin', '*');
}
```

---

## Suggested order

1. **SEC-4 first** — S (~0.5–1h). Lowest-cost, highest tier-per-hour. Pure `bootstrap/app.php` edit, no tests touched, hardens against a future cookie-auth path.
2. **SCALE-4 second** — L (~1–2d). The export-to-job extraction is real scope: new `ExecuteExportJob`, Supabase Storage signed URL flow, frontend notification when ready, retry handling. Worth doing properly, not in a rushed PR. The short-term `'limit' => 100` cap could ship first as a one-line stopgap if export timeouts start hitting before the job lands.
