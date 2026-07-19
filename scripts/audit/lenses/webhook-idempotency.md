# Inbound callbacks & idempotency semantics

Hunt every inbound-callback path (Supabase auth/email hooks, `bot.token`-gated internal endpoints) and the client-supplied idempotency layer (`IdempotencyKey` middleware) against the **Partna gold-standard callback pattern**. The Shopify/Stripe/Square webhook surface is gone as of the 2026-05-22 standalone strip; this lens covers the surviving callback surface and lays down the doctrine that will hold future vendor webhooks to the same standard when they return post-pilot.

Inbound callbacks are an adversarial input channel: they retry on 5xx, stop retrying on 2xx even when work failed, arrive out of order, and replay events. A callback that is "correct" in the happy path can still be wrong — a missing idempotency anchor replays a side effect; a `dispatch()` swallowed in a `try/catch` returns 200 on Redis failure; an HMAC verified *after* the body is parsed leaks attacker-controlled JSON into logs and into job constructors. This lens measures every callback surface against the gold standard and measures the `IdempotencyKey` middleware against the client-idempotency contract.

## The gold standard (what "correct" looks like here)

Every inbound callback endpoint must satisfy **all** of:

1. **HMAC / signature verification BEFORE body parse.** Use `$request->getContent()` (raw bytes) for HMAC. `$request->json()` / `$request->all()` only after verification; never before. A failed signature returns 401 immediately with no work performed.
2. **Idempotency anchor persisted, not just checked.** The sender's unique delivery id (Standard Webhooks `webhook-id`) is recorded — either in a DB table or via `Cache::add(key, true, ttl)` used as an atomic set-if-absent. A replay of the same id is a no-op. Ensure the idempotency check itself is atomic (cache `add` or `INSERT … ON CONFLICT DO NOTHING`) — a SELECT + conditional INSERT is a TOCTOU race under concurrent retries.
3. **200 only after action succeeds.** If `Mail::queue()` or `dispatch()` throws, do not return 200. Return 500 so the sender retries. `try { … Mail::queue() … } catch { return 200; }` is wrong. On the email hook the idempotency marker must be reverted (`Cache::forget`) before the 500 response so the retry can re-acquire it.
4. **Processing is idempotent end-to-end.** The handler or dispatched job re-checks the anchor before writing state. Running twice with the same input produces the same result. No "first run = success, second run = duplicate or error" failure modes.
5. **No domain mutations in the controller beyond the idempotency anchor.** Verify signature → persist anchor → dispatch job or queue mail → return 200. All domain mutations happen in the job. This separates "we received it" from "we processed it."
6. **Out-of-order tolerant.** Deliveries may arrive out of order and at any time. The handler must never assume a particular delivery order and must degrade gracefully on stale replays.
7. **No catch-all 200 on validation failure.** Returning 200 on a payload that fails schema validation tells the sender "processed" and the delivery is lost permanently. Return 422 (or 400) so the sender's dashboard shows the failure.
8. **Timestamp tolerance window enforced.** Standard Webhooks requires rejecting messages whose `webhook-timestamp` differs from now by more than the tolerance window (≤ 300s). Without this, a captured signature packet can be replayed indefinitely.

### When vendor webhooks return post-pilot

Future Shopify / Stripe / Square / Fresha webhook surfaces must be held to this same gold standard. Verify with `hash_equals` (not `===`). Use the vendor's unique event id (Shopify `X-Shopify-Webhook-Id`, Stripe `event.id`, Square `event_id`) as the idempotency anchor, persisted to an append-only events table (`INSERT … ON CONFLICT (event_id) DO NOTHING`). Match `$tries` / `$backoff` to the vendor's retry budget. Store the raw payload (JSONB) for replay without re-requesting from the vendor. A command or job must be able to re-process the events table by date range without hitting the vendor.

## Use the lens prefix `WHK` for findings

Number them `WHK-1`, `WHK-2`, … sequentially across the whole audit, regardless of category.

## Findings categories

### (1) HMAC verification ordering and correctness

- Signature verification called AFTER `$request->json()->all()` / `$request->all()` / `$request->validate()` — body is parsed (and potentially logged) before the signature is confirmed.
- `hash_equals` missing — string `===` comparison on HMAC is a timing oracle.
- Webhook/hook secret read from `env()` directly rather than from a config key (breaks under `config:cache`).
- Timestamp tolerance window missing or too wide — replay window left open.
- `StandardWebhookVerifier::verify()` is the shared implementation; confirm the unified `VerifySupabaseHookSignature` middleware (aliased `supabase.auth-hook` / `supabase.email-hook` for both routes) calls it with the raw body from `$request->getContent()` and that the tolerance constant (`TIMESTAMP_TOLERANCE = 300`) is enforced.
- 503 on missing secret is correct (fail-closed); confirm it does not leak config detail in the response body.
- Canonical evidence to quote: the lines from receiving the request through to the `hash_equals` / verifier call.

### (2) Idempotency anchor handling

- Handler processes a hook delivery **without** an atomic uniqueness guard on `webhook-id`.
- Cache-based dedup (e.g. `Cache::add("supabase:auth-hook:{$id}", true, ttl)`) that is absent when `$id` is an empty string — a sender that omits the `webhook-id` header bypasses dedup entirely. The anchor must either be unconditional or the controller must reject deliveries with no id.
- TTL on the cache anchor is too short — a retry that arrives outside the TTL window can re-acquire a "new" slot and re-execute.
- Cache anchor reverted on failure path (correct for email hook), but not for auth hook — check both paths consistently.
- `INSERT … ON CONFLICT DO NOTHING RETURNING id` pattern missing where a DB-persisted anchor is warranted (e.g. once auth-factor events land in the DB the dedup moves there; until then flag any path that relies purely on in-memory/Redis dedup without a DB anchor).
- Concurrent delivery race: two identical `webhook-id` deliveries arrive simultaneously; only one cache `add` wins — confirm both controllers handle the losing branch (not just the fast path).

### (3) Dispatch / action failure swallowed → silent 200

- `try { Mail::queue($mailable) } catch { return response()->json(['ok' => true]) }` — Redis/mail failure becomes invisible to the sender.
- Controllers that call `dispatch()` inside a catch-all that returns 200 on any error.
- Auth hook controller: `$this->repo->record(...)` writes directly in the controller (no dispatched job) — any exception from the repository call must propagate as a 500, not be swallowed.
- Email hook controller: exception path correctly reverts the dedup marker and returns 500 — confirm this behaviour is preserved under any refactor.
- Canonical evidence to quote: the `catch` block + the `return` statement.

### (4) Domain mutations in the controller

- Controllers that write to domain tables (not just the idempotency anchor) before dispatching the job. On sender retry after a Redis failure the controller writes the row, fails to queue, returns 500. Next retry: row already exists; job queues but sees unexpected pre-existing state.
- Acceptable: a single atomic cache `add` as the idempotency anchor + synchronous processing when the processing is light and bounded (auth hook brute-force check is ok; heavier work belongs in a job).
- Flag any controller that both writes to a persistent store AND has non-trivial logic beyond the anchor.

### (5) Job / mailable idempotency

- Mailable queued with `Mail::queue()` without a dedup guard at the mail driver level — if the mail queue processes twice for the same delivery id (Redis crash mid-dispatch) the user receives duplicate auth emails.
- Auth-factor event `repo->record()` called in the controller rather than in a dedicated job — the controller's synchronous path is fine for lightweight work but must be audited for idempotency: two simultaneous valid deliveries for the same `webhook-id` could both pass the `Cache::add` check if the id is empty (`$id !== ''` guard skips the dedup entirely).
- Jobs dispatched from hooks (if any) without passing the originating `webhook-id` as context — downstream job has no idempotency anchor.

### (6) Out-of-order handling

- MFA verification hook: `countRecentFailures()` counts from DB rows; a replay of an older failed delivery could increment the failure counter incorrectly. Confirm the `repo->record()` path is safe when the same event is processed twice with an identical timestamp.
- Email hook: action-type routing via `match` is pure — no stale-state risk. Flag if any mutable state depends on delivery order.

### (7) Schema validation returning 200 on bad payloads

- Payload validation that returns 200 (or a non-422) on invalid shape — event is lost.
- Auth hook controller: UUID regex validation on `userId`/`factorId` returns 400 on malformed payload — confirm this is not silently converted to 200 anywhere in the middleware stack.
- Email hook controller: missing required fields return 422 — confirm no outer try/catch downgrades this to 200.
- Canonical fix: 401 on HMAC failure, 422 on schema failure, 500 on processing failure, 200 ONLY on success.

### (8) Client-supplied idempotency (`IdempotencyKey` middleware, alias `idempotent`)

The `IdempotencyKey` middleware (`app/Http/Middleware/IdempotencyKey.php`) provides replay-safe mutating endpoints for client-supplied keys. Audit it against:

- **Key scoping per user.** Cache key includes `supabase_uid` from request attributes — confirm user A cannot replay user B's response by reusing the same UUID v4 key. Verify: `cacheKey = idempotency:resp:{version}:{userId}:{route}:{key}`.
- **Replay response fidelity.** Replayed responses must carry `Idempotency-Replayed: true` and restore all captured headers (Content-Type, Set-Cookie, Location, ETag, X-RateLimit-*). Missing header restoration silently breaks callers that depend on response headers on retry.
- **TTL.** 24h response cache. Confirm this is appropriate for the mutation types the middleware guards — a payment-adjacent mutation replayed 23h later could be surprising.
- **Race between concurrent identical requests.** The middleware acquires a distributed lock (`cache_locks` connection, 120s TTL). A second concurrent request gets 409 with `Retry-After: 1`. Confirm the 409 behaviour is surfaced correctly and does not bypass rate-limit budget (middleware position: after `VerifySupabaseJwt`, before `ThrottleRequests` — a replayed 409 consumes no rate-limit credit, which is correct).
- **Fail-open on Redis failure.** Cache/lock exceptions are swallowed and the request proceeds. Confirm this is the documented intentional behaviour (fail-open rather than 503). The `Log::warning` on fail-open is breadcrumb-only — does not trigger Nightwatch alert.
- **5xx responses not cached.** `shouldCache()` skips status ≥ 500 — transient infra failure is never replayed as permanent. Confirm this gate is in place.
- **Key validation.** UUID v4 pattern enforced (`/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i`). Non-v4 UUIDs rejected with 400. Confirm the rejection does not expose timing information.
- **Missing Idempotency-Key header.** Requests without the header pass through normally — the middleware is opt-in. Confirm no mandatory-key enforcement is silently bypassed by simply omitting the header on a route that should require it.

### (9) Bot-token-gated internal endpoints

- `VerifyBotToken` (alias `bot.token`) guards public-facing write paths (subscribe, signup, lead, enquiry) — not the Supabase hook paths. Confirm the hook routes do NOT use `bot.token` (they use `supabase.auth-hook` / `supabase.email-hook` instead).
- Internal endpoints without any auth middleware (e.g. `GET /internal/env-check`) — confirm these endpoints are read-only or appropriately scoped.
- `fail_open = true` mode in `VerifyBotToken`: when the captcha provider is unreachable the request proceeds. Confirm this is the intended production posture and that Nightwatch will surface the `Log::warning('bot_protection.fail_open', ...)` correctly (it won't — `Log::warning` is invisible to Nightwatch alerts; flag if a circuit-open state needs to be escalated).

## Per-finding requirements

For every finding:
- Cite the category number (1–9).
- Default tier: **P0** for HMAC ordering bugs that parse unverified bodies (cat 1), and for silent 200 on action failure where a sender's retry mechanism is bypassed (cat 3). **P1** for missing idempotency anchor on a non-idempotent side effect (cat 2, cat 5), 200-on-validation-failure (cat 7), key-scoping gaps in the `IdempotencyKey` middleware (cat 8). **P2** for out-of-order risks, missing anchor-reversal on failure path, bot-protection observability gaps (cat 6, cat 8 TTL/fail-open, cat 9). **P3** for hygiene.
- Quote verbatim evidence: the controller method, the verifier call, the `dispatch()` / `Mail::queue()` site, the `catch` block, the idempotency anchor write.
- Name the canonical replacement: `Cache::add(key, true, ttl)`, `INSERT … ON CONFLICT DO NOTHING`, `hash_equals`, `Cache::forget` on failure, `StandardWebhookVerifier::verify()` with raw body, 500 on dispatch failure.

## Out of scope — do NOT re-flag

- Commerce/financial idempotency anchors (`commerce.order_events`, Shopify event tables) — that surface is gone.
- `ShopifyOrderWebhookController`, `StripeWebhookController`, `SquareCatalogWebhookController` — do not exist.
- Test-only webhook helpers under `tests/`.
- HMAC verification at the framework level in middleware already verified above — focus on the controller-to-job/mail path.
- Pint style findings.

## Suggested per-domain scope groups

### Group A — Auth and email hooks (primary callback surface)
```
--scope app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php
--scope app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php
```

### Group B — Signature verification middleware and services
```
--scope app/Http/Middleware/Auth/VerifySupabaseHookSignature.php
--scope app/Services/Webhooks/StandardWebhookVerifier.php
```

### Group C — Client-supplied idempotency
```
--scope app/Http/Middleware/IdempotencyKey.php
```

### Group D — Internal endpoints and bot protection
```
--scope app/Http/Controllers/Api/Internal
--scope app/Http/Middleware/VerifyBotToken.php
```

### Group E — Routing
```
--scope routes/api.php
```

## Exhaustiveness directive

Walk every controller method that receives a POST from an external or semi-trusted sender. Every `Route::post(...)` in `routes/api.php` that maps to a hook controller or an internal write is a candidate path. Trace from route → middleware stack → controller → action/dispatch → job/mail → side-effect, and emit a finding for every gold-standard property the path fails to satisfy. A single controller missing both idempotency-anchor reversal AND silent-200 risk is two findings. The adjudicator dedupes and re-tiers — **under-reporting is the failure mode**. Be ruthless: hook bugs are how auth events get double-processed or auth emails get permanently dropped.
