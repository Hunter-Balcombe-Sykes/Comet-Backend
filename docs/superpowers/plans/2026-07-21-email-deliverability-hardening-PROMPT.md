# PROMPT — Email deliverability hardening (report inbox + monitoring + Resend suppression)

**Type:** Mixed — DNS/monitoring **ops runbook** (Part A, human-executed, agent-verified) + backend **implementation** (Part B, agent-implemented).
**Effort:** Part A: S (console + DNS, ~1h + propagation wait). Part B: M (one migration + webhook controller + reused signature middleware + a send-time suppression gate + tests).
**Branch (Part B only):** `feature/resend-suppression-webhook` off `development` (isolate in a git worktree — this repo has concurrent sessions; do NOT `git stash`).
**Blocked-on / decoupled:** ALL of this is at the `partna.au` DNS + Resend layer and is **fully decoupled from the prod DB cutover** — do it NOW, before the first real claim-invite batch. The cutover only needs the Send Email Hook re-registered on the prod Supabase project (already added to `docs/deploy/production-cutover.md` Phase 1 + Phase 4).

---

## Why this exists (the "silently dead funnel" risk)

The entire claim funnel is email-gated: **OTP** (Supabase Send Email Hook → backend → Resend) and **claim-invite outreach** (`ClaimNotifier` → `ClaimInviteMail` → Resend). Both send via **Resend** from `hello@partna.au`. If those land in spam, the funnel is silently dead and **no test or k6 run will show it** — deliverability lives outside the app.

**Live DNS state measured 2026-07-21** (`dig` against `partna.au`) — the foundation is ~80% there:

| Record | State | Verdict |
|---|---|---|
| DKIM (`resend._domainkey.partna.au`) | present, valid key | ✅ Resend domain verified |
| SPF on envelope domain (`send.partna.au`) | `v=spf1 include:amazonses.com ~all` | ✅ aligns (Resend is SES-backed) |
| Return-Path / bounce MX (`send.partna.au`) | → `feedback-smtp.*.amazonses.com` | ✅ bounce path wired |
| DMARC (`_dmarc.partna.au`) | `v=DMARC1; p=quarantine; rua=mailto:hello@partna.au;` | ⚠️ policy set, but `rua` inbox unreachable |
| **Root MX (`partna.au`)** | **absent** | 🔴 `hello@partna.au` can't receive mail |

**The two gaps this prompt closes:**

1. **No root MX** → `hello@partna.au` (the From **and** Reply-To on every transactional + claim-invite email) can't receive replies (claim-invite replies bounce), and the DMARC `rua=mailto:hello@partna.au` aggregate reports — the one signal that would reveal spam-foldering — have nowhere to land. You're running a `p=quarantine` policy **blind**. (Parts A1–A2.)
2. **No feedback loop on the send side** → a hard bounce or spam complaint from cold claim-invite outreach is invisible, and re-sending to dead/complaining addresses drags the **shared** `partna.au` reputation down, silently degrading the high-value OTP path too. (Part A4 + Part B.)

Part A3 (Google Postmaster Tools) gives the Gmail-specific inbox-placement + spam-rate dashboard, since most recipients are Gmail.

> **Not in scope here (separate, larger decision):** moving cold claim-invite outreach onto its own verified subdomain (e.g. `outreach.partna.au`) to firewall its reputation from OTP. Recommended before high-volume outreach, but it's a Resend-domain + sender-config change, not part of this prompt. Note it and move on.

---

# PART A — DNS & monitoring ops (human-executed, agent-verified)

These are console/DNS actions the agent **cannot click** — the agent's job is to (a) produce the exact records/values below, (b) walk Josh through each console step, and (c) **verify each with `dig` / a test send** and tick the box only when verification passes. Do **not** claim a step done without the verification output.

You're already all-in on **Cloudflare** (Worker, DNS, KV). The pragmatic, free path for A1–A2 is **Cloudflare Email Routing** — it sets the MX + SPF automatically and forwards `hello@partna.au` to a real inbox, fixing *both* the reply-bounce and the `rua` inbox in one move.

### A1 — Give `partna.au` a reachable inbox (fixes bounced replies + unblocks `rua`)

- [ ] **Enable Cloudflare Email Routing** for the `partna.au` zone (Dashboard → the zone → **Email** → Email Routing → Enable). Cloudflare auto-adds the MX records (`route1/2/3.mx.cloudflare.net`) and an SPF `include:_spf.mx.cloudflare.net`.
- [ ] **Create a destination + route** `hello@partna.au` → Josh's real inbox (e.g. `jhunter7333@gmail.com`), and confirm the destination address via Cloudflare's verification email.
  - Reply-To on all mail is `hello@partna.au` (`BaseTransactionalMail::buildEnvelope`) — this makes replies to claim invites actually reach a human.
- [ ] **Do NOT clobber the sending SPF.** The envelope SPF that authenticates Resend lives on `send.partna.au` and is untouched by Email Routing (which adds SPF at the root only). Confirm `send.partna.au` SPF is unchanged after enabling routing.
- [ ] **VERIFY:**
  ```bash
  dig +short MX partna.au                 # expect route1/2/3.mx.cloudflare.net
  dig +short TXT send.partna.au           # expect UNCHANGED: v=spf1 include:amazonses.com ~all
  ```
  Then send a test email to `hello@partna.au` and confirm it lands in Josh's inbox (not bounced).

> **Alternative if Josh wants a first-class mailbox instead of forwarding:** Google Workspace / Fastmail / Migadu on `partna.au`. More setup + cost; only pick this if `hello@` should be a full sending/receiving mailbox. Forwarding is sufficient for this prompt's goal.

### A2 — Point DMARC `rua` at a report parser you actually read

Raw DMARC aggregate XML is unreadable by hand. Use a **free** parser and keep `p=quarantine`.

- [ ] **Sign up for a free DMARC aggregate digest** — Postmark DMARC Digests (`dmarc.postmarkapp.com`, free) or dmarcian / Valimail free tier. It issues an ingest address (e.g. `re+xxxxxxxx@dmarc.postmarkapp.com`).
- [ ] **Update the `_dmarc.partna.au` TXT record** to send aggregate reports to BOTH the parser and the (now-reachable) human inbox, keeping the current policy:
  ```
  v=DMARC1; p=quarantine; rua=mailto:re+xxxxxxxx@dmarc.postmarkapp.com,mailto:hello@partna.au; fo=1; adkim=r; aspf=r
  ```
  - Keep `p=quarantine` — SPF + DKIM already align, so legit Resend mail passes; the gap was visibility, not policy strength.
  - `fo=1` requests failure reports for any auth failure; `adkim=r`/`aspf=r` (relaxed) is the default and matches the `send.partna.au` subdomain alignment.
  - Some parsers want a DNS TXT authorization on their domain to accept reports for yours — follow the parser's exact instructions if prompted.
- [ ] **VERIFY:**
  ```bash
  dig +short TXT _dmarc.partna.au         # expect the new rua with the parser address
  ```
  Then confirm the parser dashboard shows the domain as "pending data" (reports arrive within ~24–72h once mail flows).

### A3 — Enrol `partna.au` in Google Postmaster Tools

Most recipients are Gmail; this is the real inbox-placement + spam-complaint-rate dashboard.

- [ ] Add the domain at `postmaster.google.com` → **Add domain** → it issues a TXT verification token.
- [ ] Add the TXT record to `partna.au` DNS (Cloudflare), then click **Verify** in Postmaster Tools.
- [ ] **VERIFY:**
  ```bash
  dig +short TXT partna.au                # expect the google-site-verification token present
  ```
  Note: dashboards populate only after sufficient volume to Gmail — check back after the first real claim-invite batch. This is the canary for the spam-rate threshold (keep complaint rate < 0.3%, ideally < 0.1%).

### A4 — Register the Resend bounce/complaint webhook (paired with Part B)

Part B builds the receiver. Once B is deployed:

- [ ] In the **Resend dashboard → Webhooks**, add an endpoint pointing at the deployed URL (dev first: `https://dev-api.partna.au/api/internal/webhooks/resend`; prod at cutover: `https://api.partna.au/...`), subscribed to at least `email.bounced` and `email.complained` (optionally `email.delivered`, `email.delivery_delayed` for forensics).
- [ ] Copy the endpoint's **signing secret** (`whsec_…`) into `RESEND_WEBHOOK_SECRET` on the corresponding Laravel Cloud env (dev now, prod at cutover — already listed in the cutover Phase 2 secret set).
- [ ] Confirm the RESEND_API_KEY is a **Full access** key (`.env.example` already notes bounce/complaint events need it).
- [ ] **VERIFY:** send Resend's test event from the dashboard and confirm a 2xx + a log line / suppression-list write (see Part B acceptance criteria).

---

# PART B — Resend bounce/complaint webhook + send-time suppression (agent-implemented)

**Goal:** receive Resend `email.bounced` / `email.complained` events, add the affected address to a durable **suppression list**, and **gate every outgoing email** (OTP, claim-invite, transactional alike) against that list so a dead/complaining address is never re-hit. Mirror the existing `SupabaseEmailEvent` (WHK-3) pattern.

**Architecture:** Signed webhook (reuse `StandardWebhookVerifier`) → controller classifies the event → writes a redacted forensic row + upserts a `core.email_suppressions` entry keyed on a **hashed** email → a `MessageSending` event listener hashes each outbound recipient with the **same** hasher and cancels the send if suppressed. PII posture matches WHK-3: **email is stored only as a SHA256 HMAC** (app.key pepper); never plaintext.

## Investigate FIRST (do not assume — these are the real unknowns)

1. **Resend's webhook signature headers + secret format.** `StandardWebhookVerifier` (`app/Services/Webhooks/StandardWebhookVerifier.php`) reads `webhook-id` / `webhook-timestamp` / `webhook-signature` and the Supabase secret is `v1,whsec_<base64>`. **Resend/Svix sends `svix-id` / `svix-timestamp` / `svix-signature` and a `whsec_<base64>` secret (no `v1,` prefix).** Read the verifier's `verify()` implementation and confirm: (a) does it need the `v1,` prefix, or does it accept a bare `whsec_…`? (b) does it read only `webhook-*` headers, or fall back to `svix-*`? Standard Webhooks receivers SHOULD accept both. Decide between:
   - **Preferred:** add a thin `resend.webhook` middleware alias that reuses `VerifySupabaseHookSignature`'s exact logic but reads `svix-*` headers (and normalizes the secret), OR extend `StandardWebhookVerifier` to accept `svix-*` as a fallback + a bare `whsec_` secret. Keep the change generic and covered by a test with a **real** Resend signature fixture.
   - Confirm against Resend's current webhook docs / a real captured event — **do not guess the header names or secret format**; a wrong guess fails closed silently.
2. **Resend bounce payload shape — hard vs soft.** Only **permanent/hard** bounces should suppress; transient/soft bounces (full mailbox, temporary defer) must NOT. Inspect a real `email.bounced` payload (Resend dashboard test event or docs) and find the field that classifies it (Resend surfaces a bounce `type`/subtype, SES-derived: `Permanent` vs `Transient`). Suppress on permanent only. `email.complained` always suppresses. **Document the exact field/values you branch on** in a code comment — no placeholder "handle bounce type appropriately".
3. **Where does email hashing live today?** `SupabaseEmailEventService` has a private `hashEmail()` (SHA256 HMAC, `app.key` pepper). The suppression writer and the send-time gate MUST hash identically or suppression never matches. **Extract the hashing into one shared helper** (e.g. `app/Support/EmailHasher.php` or a static on a service) and use it in BOTH `SupabaseEmailEventService` (refactor to call it) and the new Resend path + the send-time listener. This shared-hasher requirement is load-bearing.
4. **Controller/route placement.** Webhooks live in the `Route::middleware('throttle:webhooks')->group()` block in `routes/api.php`. Newer webhook controllers sit under `app/Http/Controllers/Api/Webhooks/` (`SupabaseAuthHookController`); the older email hook is under `Api/Internal/`. Put the Resend controller under `Api/Webhooks/` to match the newer convention. Middleware aliases are registered in `bootstrap/app.php` (find where `supabase.email-hook` / `supabase.auth-hook` are aliased and add `resend.webhook` beside them).
5. **Config + env-check wiring.** `config/services.php` has `resend.key`. Add `resend.webhook_secret`. `app/Services/Diagnostics/EnvCheckService.php` maps `services.resend.key => RESEND_API_KEY` — add the webhook secret there too so a missing secret is surfaced.

## Implementation shape (adapt to what you find)

**Migration** — `supabase/migrations/<ts>_create_email_suppressions.sql`, mirroring `20260625000000_create_supabase_email_events.sql` conventions (raw SQL — **never** a Laravel migration; composer guard rejects them):
- Table `core.email_suppressions` (core, not audit — rows are upserted/updateable): `id uuid pk`, `email_hash text NOT NULL UNIQUE` (SHA256 HMAC — the lookup key), `reason text NOT NULL CHECK (reason IN ('hard_bounce','complaint','manual'))`, `source text NULL` (e.g. `'resend'`), `detail text NULL` (non-PII bounce subtype), `first_seen_at timestamptz`, `created_at`/`updated_at` with the shared `set_updated_at()` trigger.
- RLS: `ENABLE` + `FORCE ROW LEVEL SECURITY`; staff-only SELECT policy mirroring `supabase_email_events_staff_read` (the `EXISTS (SELECT 1 FROM core.partna_staff …)` predicate). No user_id / no tenant policy — internal system table.
- Optional lean forensic log `core.resend_email_events` (mirror `supabase_email_events`: `webhook_id UNIQUE`, `event_type`, `recipient_email_hash`, redacted `raw_payload jsonb`, `created_at`) if you want a full trail. **YAGNI check:** the suppression table is the load-bearing part; add the events log only if it's cheap and useful for triage. If you skip it, log structured lines instead.
- Index note: table is empty on first apply, so an inline (non-CONCURRENTLY) index is fine (CONVENTIONS.md §1) — do NOT pair a CONCURRENTLY statement with other statements in the same file.

**Model** — `app/Models/Core/EmailSuppression.php` extending `BaseModel` (+ `HasUuids`), `protected $table = 'core.email_suppressions'`, `$incrementing = false`, `$keyType = 'string'`, `reason` constants kept in sync with the CHECK, `email_hash` in `$hidden`. Register a policy or `POLICY_EXEMPT` justification (CI `PolicyCoverageTest` requires every model to have one — this is an internal system table with no user-facing endpoint, so a justified exemption is appropriate; follow how `SupabaseEmailEvent` handles it).

**Shared hasher** — `app/Support/EmailHasher.php` (or equivalent): `hash(?string $email): ?string` = SHA256 HMAC with `config('app.key')` pepper, normalizing case/trim, returning null for null. Refactor `SupabaseEmailEventService::hashEmail()` to delegate to it (keep its behavior identical — assert with a test that the hash is unchanged).

**Service** — `app/Services/Notifications/EmailSuppressionService.php`: `suppress(string $email, string $reason, ?string $source, ?string $detail): void` (upsert on `email_hash`, fault-tolerant like `SupabaseEmailEventService`), `isSuppressed(string $email): bool` (hash → exists lookup, cache the result briefly if the send path is hot).

**Controller** — `app/Http/Controllers/Api/Webhooks/ResendWebhookController.php` (`__invoke`): parse event `type`; on `email.complained` → `suppress(reason: 'complaint')`; on `email.bounced` **permanent only** → `suppress(reason: 'hard_bounce', detail: <subtype>)`; ignore transient bounces and other types (return 2xx so Resend doesn't retry). Idempotent — a repeated event must not error (upsert). Return `200`/`202` on success, `4xx` only for genuinely malformed payloads. Never let a DB error 500 the webhook (Resend will hammer retries).

**Route** — in the `throttle:webhooks` group in `routes/api.php`:
```php
Route::post('/internal/webhooks/resend', ResendWebhookController::class)
    ->middleware('resend.webhook');
```

**Send-time gate** — a listener on `Illuminate\Mail\Events\MessageSending` (register in `app/Providers/AppServiceProvider.php` or `EventServiceProvider`): hash each recipient with `EmailHasher`; if any is suppressed, `return false` to cancel the send + `Log::info('mail.suppressed', …)` with the hash (not the address). Our mailables are single-recipient, so cancelling the message is correct; note this assumption in a comment. This is the chokepoint that makes suppression bite for **all** mail — OTP, claim-invite, transactional.

**Config/env** — add `resend.webhook_secret => env('RESEND_WEBHOOK_SECRET')` to `config/services.php`; `RESEND_WEBHOOK_SECRET=` (with a comment) to `.env.example`; add the mapping to `EnvCheckService`.

## Constraints

- **Raw SQL migration only** (`supabase/migrations/`), not Laravel migrations. Apply to **dev** Supabase (`glncumufgaqcmqhzwrxm`) via `supabase db push` after a `--dry-run` review; prod gets it at cutover.
- **Tests run SQLite, prod is Postgres** — the `reason` CHECK constraint won't be enforced by SQLite. Verify the constraint values against the migration DDL, and if the test schema needs the table, add it to the SQLite test-schema setup (see the `information_schema on SQLite` precedent). Assert the CHECK values in the migration, not just a passing suite.
- **Fail-closed on missing secret** (503, like `VerifySupabaseHookSignature`), **fail-open on the send-time gate** is WRONG here — if suppression lookup throws, log and **allow** the send (a suppression-store outage must not block OTPs). Make that trade-off explicit and tested: gate errors → send proceeds + a warning is logged.
- **PII:** never store or log plaintext recipient emails or bounce payloads containing them — hash + redact, mirroring `SupabaseEmailEventService::redactPayload()`.
- No typed `public bool $afterCommit` on any job (trait conflict); follow repo job/service conventions.
- The dev env runs `QUEUE_CONNECTION=sync` — the webhook path is synchronous request handling, unaffected; just don't rely on a queue worker for the suppression write.

## Acceptance criteria / tests

- [ ] `POST /internal/webhooks/resend` with a **valid** Resend signature + `email.bounced` (permanent) → a `core.email_suppressions` row with `reason='hard_bounce'`. Transient bounce → **no** suppression row.
- [ ] `email.complained` → suppression row with `reason='complaint'`.
- [ ] Bad signature → **401**; missing `RESEND_WEBHOOK_SECRET` → **503** (fail-closed), mirroring the Supabase hook.
- [ ] Idempotency: the same event delivered twice → exactly **one** suppression row (upsert).
- [ ] `MessageSending` gate: a mailable to a **suppressed** address is **not** sent (assert via `array`/`log` transport or a direct listener unit test returning `false`); a non-suppressed address sends normally.
- [ ] Gate fault-tolerance: when `EmailSuppressionService::isSuppressed()` throws, the send **proceeds** and a warning is logged (OTP must never be blocked by a suppression-store outage).
- [ ] Shared-hasher parity: `EmailHasher::hash($x)` equals the pre-refactor `SupabaseEmailEventService` hash for the same input (regression test), so existing WHK-3 rows still correlate.
- [ ] `PolicyCoverageTest` green (policy or justified exemption for `EmailSuppression`); `composer test` green; `composer analyse` — check only your files with a no-baseline temp config (the dev baseline is independently red per the PHPStan-gate note, not your regression).
- [ ] `supabase db push --dry-run` reviewed, then applied to dev; `supabase migration list` aligned.

## Context pointers

- Mirror pattern: `supabase/migrations/20260625000000_create_supabase_email_events.sql`, `app/Models/Core/Notifications/SupabaseEmailEvent.php`, `app/Services/Notifications/SupabaseEmailEventService.php` (WHK-3).
- Reused infra: `app/Services/Webhooks/StandardWebhookVerifier.php`, `app/Http/Middleware/Auth/VerifySupabaseHookSignature.php` (already generic — configKey/logPrefix/label), alias registration in `bootstrap/app.php`.
- Anticipated by: `app/Models/Core/Gdpr/DataExportAudit.php:34` comment ("a future Resend webhook handler advances it…") — align status semantics if you touch export state.
- Existing bulk-sender compliance already shipped: List-Unsubscribe one-click + `/public/unsubscribe/{token}` (RFC 8058) — deliverability basics are partly done; this prompt adds the feedback loop, not the unsubscribe path.
- From/Reply-To chokepoint: `app/Mail/BaseTransactionalMail.php` (`buildEnvelope`), from `config('mail.from.address')` = `hello@partna.au`.
- Cutover coupling: `docs/deploy/production-cutover.md` Phase 1 (re-register Send Email Hook) + Phase 2 (`RESEND_WEBHOOK_SECRET`) + Phase 4 (verify auth mail from Resend + DNS ready).

---

## Suggested order

1. **Part A1 + A2 now** (report inbox + `rua` parser) — highest value, zero code, and warming/report data is calendar-time-bound. Start the clock today.
2. **Part B** (webhook + suppression) on its worktree branch → deploy to dev.
3. **Part A4** (register Resend webhook against dev URL) → send a test event → confirm suppression write.
4. **Part A3** (Postmaster Tools) — enrol now, data arrives after the first real send volume.
5. At cutover: re-point the Resend webhook + DMARC/Postmaster to prod URLs (the cutover runbook now references this file).
