# Webhook idempotency & delivery semantics

Hunt every webhook ingestion path in the codebase and measure it against the **Partna gold-standard webhook pattern** established by the Shopify orders pipeline post-rebuild (see `commerce.order_events` keyed by `shopify_event_id`, `docs/analytics-rebuild-plan.md` v3.1, deployed 2026-05-06).

Vendor webhooks are an adversarial input channel disguised as an integration: they retry aggressively on 5xx, stop retrying on 2xx (even if the work failed), arrive out of order, and replay events. A webhook that is "correct" in the happy path can still be wrong — a missing idempotency key replays a payout; a `dispatch()` swallowed in a `try/catch` 200s on Redis failure; an HMAC verified *after* the body is parsed leaks attacker-controlled JSON into logs and into the job constructor. This lens measures the diff between every webhook surface (Shopify, Stripe, Square, Fresha, Supabase Auth, internal) and the gold standard.

## The gold standard (what "correct" looks like here)

Every webhook endpoint must satisfy **all** of:

1. **HMAC / signature verification BEFORE body parse.** Use the raw request body for HMAC. `$request->json()` / `$request->all()` after verification, never before. A failed signature returns 401 immediately with no work performed.
2. **Idempotency key persisted, not just checked.** The vendor's unique event id (Shopify `X-Shopify-Webhook-Id`, Stripe `event.id`, Square `event_id`) is recorded in an append-only events table (`commerce.order_events.shopify_event_id` is the canonical reference). Reprocessing the same id is a no-op — `INSERT … ON CONFLICT DO NOTHING` or a unique-constraint catch.
3. **200 only after dispatch succeeds.** The controller dispatches the processing job and returns 200 only when `dispatch()` itself returned successfully. If Redis is down, return 500 so the vendor retries. Never `try { dispatch() } catch { return 200; }`.
4. **Processing job is idempotent end-to-end.** The job re-checks the idempotency key before mutating state, uses upserts not blind inserts, and is safe to run N times with the same input. No "first run = success, second run = duplicate row" failure modes.
5. **No DB writes in the controller.** The controller verifies HMAC, persists the raw event (idempotency anchor), dispatches the job, returns 200. All domain mutations happen in the job. This separates "we received it" from "we processed it" — vendor retry semantics work correctly even mid-incident.
6. **Out-of-order tolerant.** Webhooks arrive out of order. The processing job must check `updated_at` / `version` / `sequence` on the payload against current state and skip stale events. `orders/updated` arriving before `orders/create` is a normal occurrence, not a bug.
7. **Payload archived raw.** The full vendor payload is stored verbatim (JSONB column) on the events table for replay and forensic use. Don't normalise on ingest — the projection is mutable, the event log is not.
8. **Replay tooling exists.** A command or job can re-process the events table for a brand / date range without hitting the vendor. If the only way to recover from a bug is to ask Shopify to re-send, the pattern is broken.
9. **Vendor retry budget respected.** Shopify retries 19 times over 48h; Stripe retries for 3 days; Square ~3 days. Jobs with `$tries = 3` and no backoff race the vendor's retry — flag mismatches.
10. **No catch-all `200 OK` on validation failure.** Returning 200 on a payload that fails schema validation tells the vendor "processed" and the event is lost forever. Return 422 (or 400) so the vendor's dashboard shows the failure and an operator can re-send.

## Use the lens prefix `WHK` for findings

Number them `WHK-1`, `WHK-2`, … sequentially across the whole audit, regardless of category.

## Findings categories

### (1) HMAC verification ordering and correctness

- HMAC computed against `$request->all()` / `$request->json()->all()` / `json_encode($request->json())` rather than the raw body — JSON round-trip can re-order keys and break the signature.
- `$request->validate(...)` / Form Request validation that runs BEFORE the HMAC check — body is parsed (and potentially logged) before verification.
- `hash_equals` missing — string `===` comparison on HMAC is a timing oracle.
- Webhook secret read from a non-config source (`env()` outside config files cached out under `config:cache`).
- Per-vendor verification helpers that drift: confirm Shopify uses `X-Shopify-Hmac-Sha256`, Stripe uses `Stripe-Signature` with timestamp tolerance window (`stripe-signature` library default = 5 minutes), Square uses `x-square-hmacsha256-signature` against URL+body.
- Stripe specifically: missing timestamp tolerance check leaves replay window open indefinitely.
- Canonical evidence to quote: the lines from receiving the request through to the `hash_equals` / library verifier call.

### (2) Idempotency key handling

- Controller / job that processes a webhook **without** consulting an events table or unique constraint keyed on the vendor's event id.
- `Order::updateOrCreate(['shopify_order_id' => $id], ...)` used as the only idempotency anchor — this dedupes ORDERS but not EVENTS. The same `orders/updated` replayed re-applies the same mutation; for non-idempotent side effects (commission accrual, notification dispatch) this is a bug.
- Idempotency check exists but is a `SELECT … WHERE event_id = ?` followed by `INSERT` outside a transaction (TOCTOU under concurrent vendor retries).
- Idempotency table lacks a unique index on the vendor event id column — race-condition double-process.
- Stripe webhooks keyed on `event.data.object.id` rather than `event.id` — these are NOT the same; `event.id` is the dedupe anchor.
- Canonical fix: `INSERT INTO <events_table> (event_id, payload, ...) VALUES (?, ?, ...) ON CONFLICT (event_id) DO NOTHING RETURNING id;` — if no row returned, the event is a replay; skip processing.

### (3) Dispatch failure swallowed → silent 200

- `try { ... dispatch(...) ... } catch { return response('OK', 200); }` — Redis failure becomes invisible.
- `dispatch()` called inside a controller that catches `\Throwable` broadly and returns 200 on any error.
- Webhook controllers that return 200 unconditionally at the end of the method regardless of intermediate failure (no early-return on validation/auth/etc).
- Webhook controllers that dispatch via `dispatch_sync()` — request thread does the work AND swallows exceptions on success-path return.
- Canonical evidence to quote: the `catch` block + the `return` statement.

### (4) DB writes in the controller (split-brain on retry)

- Controllers that `Order::create(...)`, `Professional::update(...)`, etc. before dispatching the job. On vendor retry after a Redis failure, the controller writes the DB row, fails to dispatch, returns 500. Next retry: row already exists (depending on constraint), job dispatches, processing logic sees pre-existing state and behaves incorrectly.
- Controllers that write to the events table AND mutate domain tables in the same request — the events table is fine, the domain tables aren't.
- Acceptable: the controller writes a single idempotency-anchor row to the events table and dispatches. Everything else belongs in the job.

### (5) Job idempotency

- Job `handle()` methods that `Model::create(...)` / blind-insert rather than upsert, when the job can run twice for the same event.
- Job side-effects with no idempotency guard: dispatching a notification, calling Stripe to issue a refund, posting to Slack, incrementing a counter — flag every external side-effect and confirm it's keyed on the event id.
- Jobs that mutate ledger rows / commission movements without re-checking whether the movement already exists for this event id.
- `commission_movements` writes specifically: every insert must be keyed on (event_id, type) or equivalent — flag any path that lacks this.
- Jobs that dispatch downstream jobs without passing the originating event id through, so the downstream job has no idempotency anchor either.

### (6) Out-of-order handling

- `orders/updated` handler that overwrites `commerce.orders` fields without comparing `updated_at` from the payload against the row's current `updated_at` — late-arriving older event wipes newer state.
- `customers/update` / `products/update` paths missing version/timestamp guards.
- Stripe `payment_intent.succeeded` handlers that don't check the current PI status before mutating — a later `payment_intent.canceled` may have already arrived.
- Canonical fix: `WHERE source_updated_at <= EXCLUDED.source_updated_at` in upserts, or explicit guard in the job.

### (7) Schema validation returning 200 on bad payloads

- Form Request validation in webhook controllers that returns 422 on failure but the route swallows it back to 200.
- `try { $request->validate(...) } catch (ValidationException $e) { return response('OK'); }` — event is lost.
- Webhook handlers without ANY schema validation — relying on optional-array-access patterns. Flag and recommend Form Request validation AFTER HMAC.
- Canonical fix: 401 on HMAC failure, 422 on schema failure (vendor dashboard shows the bad event), 500 on dispatch failure, 200 ONLY on success.

### (8) Retry / tries / backoff mismatch with vendor

- Jobs processing Shopify webhooks with `$tries = 3` and 90s default backoff — Shopify gives up after 19 retries over 48h; our worker gives up in minutes.
- Jobs with no `$backoff` array — exponential backoff vs. fixed retry has different recovery characteristics for transient vendor outages.
- Jobs with `$tries = 0` (unlimited) on non-idempotent paths — repeat-forever amplifies a bug.
- Missing `$timeout` on jobs that call external APIs — a hung Shopify call stalls the worker without Nightwatch surfacing slowness.

### (9) Raw payload archival

- Events tables that store normalised columns only (no raw JSONB) — replay impossible without re-requesting from the vendor.
- Payloads stored after redaction / mutation — flag and recommend storing raw, redacting at read-time if needed.
- Missing payload archival entirely (controller dispatches with parsed array, no row written) — no audit trail.

### (10) Replay capability

- No artisan command or job to re-process the events table for a given (brand, date range, event type).
- Replay path exists but bypasses the idempotency check (re-processing emits duplicate side-effects).
- Replay path exists but uses live vendor API calls instead of the archived payload — defeats the purpose.

## Per-finding requirements

For every finding:
- Cite the category number (1–10).
- Default tier: **P0** for HMAC ordering bugs that leak unverified payloads into logs/jobs (cat 1), and for silent 200 on dispatch failure on financial webhooks (cat 3 + Stripe/payout paths). **P1** for missing idempotency on non-idempotent side effects (cat 2 + cat 5), out-of-order vulnerability on commerce mutations (cat 6), and 200-on-validation-failure (cat 7). **P2** for missing payload archival, retry mismatch, replay tooling gaps.
- Quote verbatim evidence: the controller method, the HMAC verifier, the `dispatch()` site, the `catch` block, the job's mutation site.
- Name the canonical replacement: `commerce.order_events`-style events table, `INSERT … ON CONFLICT DO NOTHING`, `hash_equals`, `DB::afterCommit`, vendor-specific verifier (`Webhook::constructEvent` for Stripe, Shopify HMAC helper, Square notification verifier).
- For each webhook surface, identify the events table or unique-constraint anchor used for idempotency — if none, that's a finding by itself.

## Out of scope — do NOT re-flag

- `commerce.order_events` schema itself and its trigger that mirrors to `order_items` (this is the gold standard).
- The `ShopifyOrderWebhookController` post-rebuild path if it's already on the pattern — confirm and skip rather than re-flag.
- Test-only webhook helpers under `tests/`.
- HMAC verification at the framework level (Laravel middleware) — focus on the controller-to-job path.

## Suggested per-domain scope groups

### Group A — Shopify webhooks (largest surface, financial impact)
```
--scope app/Http/Controllers/Api/Webhooks/Shopify
--scope app/Jobs/Shopify
```

### Group B — Stripe webhooks (financial, regulated)
```
--scope app/Http/Controllers/Api/Webhooks/Stripe
--scope app/Jobs/Stripe
--scope app/Services/Stripe
```

### Group C — Other vendor webhooks
```
--scope app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php
--scope app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php
--scope app/Jobs/Fresha
--scope app/Jobs/Square
```

### Group D — Internal / Auth webhooks
```
--scope app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php
--scope app/Http/Controllers/Api/Internal
```

### Group E — Webhook routing & middleware
```
--scope routes/api.php
--scope app/Http/Middleware
```

## Exhaustiveness directive

Walk every controller method that receives a vendor POST. Every `Route::post(...)` in `routes/api.php` that maps to a webhook controller is a candidate path; trace from route → controller → dispatch → job → side-effect, and emit a finding for every gold-standard property the path fails to satisfy. Three vendors each missing idempotency anchors = three findings (`WHK-1`, `WHK-2`, `WHK-3`), not one consolidated finding. A single controller missing both HMAC ordering AND silent-200 is two findings. The adjudicator dedupes and re-tiers — **under-reporting is the failure mode**. Be ruthless: webhook bugs are how money silently moves the wrong way.
