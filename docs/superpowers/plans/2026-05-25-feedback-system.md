# Feedback System — Foundational Plan

**Date:** 2026-05-25
**Status:** Planned
**Owner:** Josh
**Estimated effort:** ~1 day implementation + ~2h testing

## Goals

Build a durable in-app feedback channel that:

1. Lets authenticated dashboard users submit bug reports, ideas, praise, questions.
2. Notifies staff via email (configurable recipient list).
3. Persists every submission to a queryable table with full debugging context.
4. Cross-references submissions to server logs via `request_id`.
5. Is structurally extensible to (a) anonymous submissions, (b) public site embeds, (c) attachments, (d) public roadmap + voting, (e) Slack/other channels — **without schema migrations or controller rewrites**.

## Non-goals (deferred — schema reserves space for them)

- Anonymous / unauthenticated submission
- Embedding on `<handle>.partna.au` public site pages
- File / screenshot attachments
- Public roadmap, status updates, voting
- Slack notification channel (email-only for now)
- Admin triage UI (staff endpoints deferred; model + policy ready for them)
- Auto-classification of kind/severity via LLM

## Architecture overview

```
Dashboard frontend
    │
    │  POST /api/user/feedback   { kind, message, page_url, request_id, ... }
    ▼
VerifySupabaseJwt → RateLimiter('feedback-submit') → FeedbackController
    │
    ▼
FeedbackService::submit()
    ├─ AccountCapabilities::for($user)->canSubmitFeedback()    [gate]
    ├─ duplicate-window check (60s)
    ├─ hash IP with pepper
    ├─ Feedback::create(...)
    └─ SendFeedbackEmailJob::dispatch()->afterCommit()
                │
                ▼
        FeedbackSubmittedMail → config('partna.feedback.notify_emails')
```

## Data model

### Schema: `core.feedback`

```sql
CREATE TABLE core.feedback (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),

  -- Who
  user_id           UUID NULL REFERENCES core.users(id) ON DELETE SET NULL,
  reply_email       TEXT NULL,

  -- What
  kind              TEXT NOT NULL
                      CHECK (kind IN ('bug','idea','praise','question','other')),
  severity          TEXT NULL
                      CHECK (severity IS NULL
                             OR severity IN ('low','medium','high','critical')),
  message           TEXT NOT NULL
                      CHECK (char_length(message) BETWEEN 1 AND 5000),

  -- Debugging context
  page_url          TEXT NULL,
  user_agent        TEXT NULL,
  viewport          TEXT NULL,          -- "1920x1080"
  app_version       TEXT NULL,          -- frontend build hash / version
  request_id        TEXT NULL,          -- correlate to Cloud logs

  -- Triage (future admin UI; schema ready now)
  status            TEXT NOT NULL DEFAULT 'new'
                      CHECK (status IN ('new','triaged','in_progress',
                                        'shipped','wontfix','duplicate')),
  internal_notes    JSONB NOT NULL DEFAULT '[]'::jsonb,
  tags              JSONB NOT NULL DEFAULT '[]'::jsonb,

  -- Source (future-proof for anonymous/mobile/email-in)
  source            TEXT NOT NULL DEFAULT 'dashboard'
                      CHECK (source IN ('dashboard','public_site',
                                        'mobile','email','api')),
  ip_hash           TEXT NULL,          -- SHA256(ip || pepper); never raw IP

  -- Bookkeeping
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at        TIMESTAMPTZ NULL
);

CREATE INDEX feedback_user_id_idx
  ON core.feedback(user_id) WHERE deleted_at IS NULL;
CREATE INDEX feedback_status_created_idx
  ON core.feedback(status, created_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX feedback_kind_idx
  ON core.feedback(kind) WHERE deleted_at IS NULL;
CREATE INDEX feedback_created_at_idx
  ON core.feedback(created_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX feedback_ip_hash_recent_idx
  ON core.feedback(ip_hash, created_at DESC)
  WHERE deleted_at IS NULL AND ip_hash IS NOT NULL;

-- Standard updated_at trigger (follow existing pattern from sibling core tables)
CREATE TRIGGER feedback_set_updated_at
  BEFORE UPDATE ON core.feedback
  FOR EACH ROW EXECUTE FUNCTION core.set_updated_at();

-- RLS: enable, mirror policy pattern from sibling core tables.
-- app_backend bypasses via grants; service_role full access; anon/auth no direct access.
ALTER TABLE core.feedback ENABLE ROW LEVEL SECURITY;
GRANT SELECT, INSERT, UPDATE ON core.feedback TO app_backend;
```

### Why these design choices

| Choice | Why |
|---|---|
| `TEXT + CHECK` over native enum | CHECK extends via `ALTER TABLE`; native enums need a multi-step migration dance. |
| `user_id` nullable + `ON DELETE SET NULL` | GDPR: deleting a user preserves the report content for triage stats but severs PII linkage. Also unblocks anonymous submissions later. |
| `ip_hash` not raw IP | Abuse correlation possible without storing PII; satisfies privacy-by-default. |
| `request_id` column | One-click pivot from a feedback row to Cloud logs. Load-bearing for debugging. |
| `status` + `internal_notes` + `tags` reserved now | Adding admin triage later requires zero migration. |
| `source` enum starts at `'dashboard'` | Adding `'public_site'` later is a CHECK extension, not a schema change. |
| Partial indexes filtering `deleted_at IS NULL` | Soft-delete-aware indexes stay small. |

## Models

### `app/Models/Core/Feedback.php`

- Extends `BaseModel`
- Connection: `pgsql` (inherited)
- Table: `core.feedback`
- `protected $fillable` — explicit allowlist; **no `$guarded = []`**
- Casts: `internal_notes` → array, `tags` → array, `deleted_at` → datetime
- Uses `SoftDeletes` trait
- Relationship: `user()` → `belongsTo(User::class)`
- Scopes: `forUser($id)`, `recent()`, `byStatus($status)`

## Authorization

### `app/Policies/FeedbackPolicy.php`

| Ability | Rule |
|---|---|
| `create` | Authenticated + `AccountCapabilities::for($user)->can_submit_feedback` |
| `view` | Owner (`$user->id === $feedback->user_id`) **or** staff (AAL2 via existing pattern) |
| `viewAny` | Staff only |
| `update` | Staff only (triage) |
| `delete` | Owner within 5 min of creation, **or** staff |

- Extends `BasePolicy`
- Registered in `AppServiceProvider::boot()`:
  ```php
  Gate::policy(Feedback::class, FeedbackPolicy::class);
  ```
- `PolicyCoverageTest` will auto-include and pass.

### Controller authorization calls

Every action uses `authorizeForUser($user, 'ability', $modelOrSkeleton)` per CLAUDE.md rule (Supabase JWT → `Auth::user()` is null).

**Resolving the authenticated user in the controller** (codebase convention — confirmed via `ProfessionalController.php`):
```php
$userId = $request->attributes->get('supabase_uid');
$user = User::findOrFail($userId);
```
Never `Auth::user()` — it is always null with Supabase JWT.

For `create`, pre-instantiate a skeleton:
```php
$skeleton = new Feedback(['user_id' => $user->id]);
$this->authorizeForUser($user, 'create', $skeleton);
```

For missing/not-owned resources: return **404, not 403** (per CLAUDE.md).

## API surface

All routes under `routes/api/user.php` (NEW file — register in `routes/api.php` alongside `professional.php`/`publicSite.php`/`staff.php`), prefix `/api/user`, middleware `['supabase.jwt']` only (no email-verified or professional-loading — feedback should work even for users in unusual states).

Controllers go under a NEW namespace `app/Http/Controllers/Api/User/` (sibling to `Api/Professional/`, `Api/PublicSite/`, `Api/Staff/`). This namespace will host future user-self-service endpoints (data export, account deletion confirmations, etc.) — creating it now is correct rather than forcing feedback under `Api/Professional/`.

| Method | Path | Action | Returns |
|---|---|---|---|
| `POST` | `/feedback` | submit | 201 + `FeedbackResource` |
| `GET` | `/feedback` | list own (paginated, 20/page, `?cursor=`) | 200 + paginated collection |
| `GET` | `/feedback/{id}` | view own | 200 + `FeedbackResource` (404 if not owner) |

Staff routes are **not** added now. The model + policy support them; add when triage UI is built.

### Request shape (`POST /feedback`)

```json
{
  "kind": "bug",
  "severity": "medium",
  "message": "The save button on the gallery edit screen doesn't respond on first click.",
  "page_url": "https://app.partna.au/dashboard/gallery",
  "viewport": "1440x900",
  "app_version": "2026.05.25-a3f9c12",
  "request_id": "01HV...",
  "reply_email": "alt@example.com"
}
```

### `SubmitFeedbackRequest` validation

```php
'kind'         => ['required', Rule::in(['bug','idea','praise','question','other'])],
'severity'     => ['nullable', 'required_if:kind,bug',
                   Rule::in(['low','medium','high','critical'])],
'message'      => ['required','string','min:1','max:5000'],
'page_url'     => ['nullable','url','max:2048'],
'user_agent'   => ['nullable','string','max:1024'],   // server may also read header
'viewport'     => ['nullable','regex:/^\d{1,5}x\d{1,5}$/'],
'app_version'  => ['nullable','string','max:64'],
'request_id'   => ['nullable','string','max:64','regex:/^[A-Za-z0-9_-]+$/'],
'reply_email'  => ['nullable','email:rfc','max:255'],
```

`prepareForValidation()` trims whitespace on `message`.

### `FeedbackResource` shape

Owner view exposes:
```json
{
  "id": "...",
  "kind": "bug",
  "severity": "medium",
  "message": "...",
  "status": "new",
  "page_url": "...",
  "created_at": "2026-05-25T10:23:00Z"
}
```

Internal-only fields (`ip_hash`, `internal_notes`, `tags`, `user_id`, `reply_email`, `request_id`) are **omitted from the user-facing resource**. They're available via a future `StaffFeedbackResource`.

## Service layer

### `app/Services/Feedback/FeedbackService.php`

Single responsibility: validated input → persisted row + dispatched notification.

```php
public function submit(User $user, array $data, Request $request): Feedback
{
    // 1. Capability gate (defense in depth — controller policy already gated)
    if (!AccountCapabilities::for($user)->can_submit_feedback) {
        throw new FeedbackNotAllowedException();
    }

    // 2. Duplicate-window check: identical message from same user within 60s
    $duplicate = Feedback::forUser($user->id)
        ->where('message', $data['message'])
        ->where('created_at', '>=', now()->subSeconds(
            config('partna.feedback.duplicate_window_seconds')
        ))
        ->exists();
    if ($duplicate) {
        throw new DuplicateFeedbackException();   // → 429 in handler
    }

    // 3. Persist
    $feedback = Feedback::create([
        'user_id'      => $user->id,
        'reply_email'  => $data['reply_email'] ?? null,
        'kind'         => $data['kind'],
        'severity'     => $data['severity'] ?? null,
        'message'      => $data['message'],
        'page_url'     => $data['page_url'] ?? null,
        'user_agent'   => $data['user_agent'] ?? $request->userAgent(),
        'viewport'     => $data['viewport'] ?? null,
        'app_version'  => $data['app_version'] ?? null,
        'request_id'   => $data['request_id'] ?? null,   // frontend-supplied only; VerifySupabaseJwt does not expose request_id as a request attribute
        'source'       => 'dashboard',
        'ip_hash'      => $this->hashIp($request->ip()),
    ]);

    // 4. Dispatch notification AFTER commit so misconfig doesn't roll back insert
    SendFeedbackEmailJob::dispatch($feedback->id)->afterCommit();

    return $feedback;
}

private function hashIp(?string $ip): ?string
{
    if (!$ip) return null;
    $pepper = config('partna.feedback.ip_hash_pepper');
    return hash('sha256', $ip . '|' . $pepper);
}
```

`DuplicateFeedbackException` and `FeedbackNotAllowedException` extend a base exception with a `render()` method returning the right HTTP code.

## Notification job

### `app/Jobs/Notifications/SendFeedbackEmailJob.php`

- Implements `ShouldQueue`, queue: `default`
- Constructor: `public string $feedbackId`
- `$tries = 3`, `$backoff = [30, 120, 600]` (seconds)
- Re-loads `Feedback::with('user')->find($id)` — if missing (deleted between dispatch and run), log + return.
- Recipients: `config('partna.feedback.notify_emails')` array. If empty → `Log::warning('Feedback submitted but no notify_emails configured', ['id' => $id])` and return. **Never throw on misconfig** — don't poison the queue.
- Sends `FeedbackSubmittedMail` to each recipient.

### `app/Mail/FeedbackSubmittedMail.php`

- Markdown mailable (template at `resources/views/emails/feedback-submitted.blade.php`)
- Subject: `[Partna Feedback] {kind} — {short_user_label}`
  - Subject string built from a fixed allowlist (kind enum + user UUID prefix). **Never** interpolate raw message into subject (CRLF injection risk).
- Body fields: kind, severity, message (escaped via Blade `{{ }}`), user email, user id, page URL, request_id, user agent, app version, link to feedback row (future admin URL).
- ReplyTo: `reply_email ?? user.email` if present and valid, else default from address.

## Rate limiting

### Named limiter in `AppServiceProvider::boot()` (or `RouteServiceProvider`)

```php
RateLimiter::for('feedback-submit', function (Request $request) {
    $userId = $request->attributes->get('supabase_user')?->id ?? $request->ip();
    return [
        Limit::perHour(config('partna.feedback.rate_limit_per_hour'))->by($userId),
        Limit::perDay(config('partna.feedback.rate_limit_per_day'))->by($userId),
    ];
});
```

Applied via `->middleware('throttle:feedback-submit')` on the POST route only.

Returns **429** with standard Laravel rate-limit headers. Frontend should surface a "you've sent a lot of feedback recently, please wait" message.

## Capability gate

### `app/Services/Accounts/AccountCapabilitySet.php`

`AccountCapabilities::for($user)` returns an `AccountCapabilitySet` value object. Add the capability there (alongside existing `can_edit_design`, `notification_categories`, `worker_kv_type`):

```php
public bool $can_submit_feedback;   // always true for now; gated to allow future bans
```

Set it to `true` in `AccountCapabilities::for()` for all users. Future: read from `users.feedback_banned_at` (column not added now — schema reserves nothing for it; YAGNI).

The single comment on the property explains the WHY (intentional always-true, deliberate extension point) — satisfies the CLAUDE.md "endpoints MUST check capabilities" rule without a placeholder method.

## Configuration

### `config/partna.php` — add block

```php
'feedback' => [
    'notify_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FEEDBACK_NOTIFY_EMAILS', ''))
    ))),
    'rate_limit_per_hour'       => (int) env('FEEDBACK_RATE_LIMIT_HOUR', 10),
    'rate_limit_per_day'        => (int) env('FEEDBACK_RATE_LIMIT_DAY', 30),
    'duplicate_window_seconds'  => (int) env('FEEDBACK_DUPLICATE_WINDOW', 60),
    'ip_hash_pepper'            => env('FEEDBACK_IP_HASH_PEPPER'),
    'max_message_length'        => 5000,
],
```

### `.env.example` — add

```
FEEDBACK_NOTIFY_EMAILS=
FEEDBACK_IP_HASH_PEPPER=
FEEDBACK_RATE_LIMIT_HOUR=10
FEEDBACK_RATE_LIMIT_DAY=30
FEEDBACK_DUPLICATE_WINDOW=60
```

Boot-time guard: if `FEEDBACK_IP_HASH_PEPPER` is empty in production, log a warning at boot. Don't crash — soft-degrade by skipping `ip_hash` (acceptable; PII isn't stored either way).

## Security summary

| Concern | Mitigation |
|---|---|
| **Auth bypass** | `supabase.jwt` middleware required on all routes. |
| **Authorization** | Policy gates every action; uses `authorizeForUser` (Supabase JWT pattern). |
| **Resource enumeration** | 404 (not 403) on missing/not-owned. |
| **Mass assignment** | Explicit `$fillable` allowlist on model. |
| **XSS in email** | Blade `{{ }}` escapes; never `{!! !!}`. |
| **Subject-line CRLF injection** | Subject built from enum allowlist + UUID prefix; raw message never interpolated. |
| **Email header injection** | `reply_email` validated as RFC email before use as ReplyTo. |
| **Rate abuse** | Per-user hour + day limits; 60s duplicate-window check. |
| **Volumetric abuse / bots** | Auth-required + per-user rate limit. (Public surface deferred — captcha when needed.) |
| **PII in logs** | Never log full `message`; log `id`, `user_id`, `kind` only. |
| **IP storage** | Hashed with app-level pepper; raw IP never persisted. |
| **GDPR — user deletion** | `ON DELETE SET NULL` severs PII linkage; submission survives for product analytics. |
| **Job poisoning on misconfig** | Empty notify_emails logs a warning and returns; doesn't throw. |
| **Idempotency** | Duplicate-window check at 60s. |
| **CSRF** | API routes are stateless (Bearer JWT); CSRF n/a. |
| **DB injection** | Eloquent + parameterized queries throughout; no raw SQL with user input. |
| **MFA** | Not required for submit. If a future admin endpoint is added, gate with `require.aal2` per project pattern. |

## Testing plan

### Feature tests (`tests/Feature/Feedback/`)

**`SubmitFeedbackTest.php`**
- 401 when no JWT
- 422 on missing/invalid fields (parameterized: message too long, bad kind, bad email, bad viewport)
- 422 when `kind=bug` and `severity` missing
- 201 on valid submission → row exists with correct fields
- `user_agent` falls back to request header when not provided
- `request_id` falls back to request attribute when not provided
- `ip_hash` is set and is not the raw IP
- Job dispatched with `afterCommit`
- 429 when 11th submission in an hour
- 429 when duplicate message within 60s
- Capability gate false → 403
- HTML in `message` is stored verbatim (escaped only at render time)

**`ListFeedbackTest.php`**
- Lists only own feedback; not other users'
- Pagination works
- Soft-deleted rows excluded

**`ViewFeedbackTest.php`**
- 200 for own
- 404 for not-owned (not 403)
- 404 for non-existent
- 404 for soft-deleted

**`NotificationJobTest.php`** (`tests/Feature/Jobs/`)
- Sends email to every configured recipient
- Subject contains kind + truncated user id
- Body contains escaped message
- Empty `notify_emails` → logs warning, doesn't throw
- ReplyTo uses `reply_email` when valid, falls back to user email
- Missing feedback (deleted between dispatch and run) → logs + returns cleanly

### Unit tests (`tests/Unit/Feedback/`)

**`FeedbackServiceTest.php`**
- `hashIp` is deterministic with pepper, differs without
- `hashIp(null)` returns null
- Duplicate-window check uses configured seconds

### Sweep tests (already exist, must pass)

- `PolicyCoverageTest` — auto-passes once policy is registered
- `composer test` green
- `pint` clean

## Files

### New

- `supabase/migrations/20260526210001_create_feedback_table.sql` *(must sort AFTER the baseline `20260526000000_baseline_standalone_user.sql`)*
- `app/Models/Core/Feedback.php`
- `app/Policies/FeedbackPolicy.php`
- `app/Http/Controllers/Api/User/FeedbackController.php`  *(creates new `Api/User/` namespace)*
- `app/Http/Requests/Api/User/SubmitFeedbackRequest.php`  *(matches existing `Http/Requests/Api/<Domain>/` nesting)*
- `app/Http/Resources/FeedbackResource.php`
- `app/Services/Feedback/FeedbackService.php`
- `app/Services/Feedback/Exceptions/DuplicateFeedbackException.php`
- `app/Services/Feedback/Exceptions/FeedbackNotAllowedException.php`
- `app/Jobs/Notifications/SendFeedbackEmailJob.php`
- `app/Mail/FeedbackSubmittedMail.php`
- `resources/views/emails/feedback-submitted.blade.php`
- `tests/Feature/Feedback/SubmitFeedbackTest.php`
- `tests/Feature/Feedback/ListFeedbackTest.php`
- `tests/Feature/Feedback/ViewFeedbackTest.php`
- `tests/Feature/Jobs/SendFeedbackEmailJobTest.php`
- `tests/Unit/Feedback/FeedbackServiceTest.php`

### Edited

- `routes/api/user.php` — **NEW FILE**; 3 routes under feedback group
- `routes/api.php` — register the new `user.php` route file alongside the existing domain files
- `app/Providers/AppServiceProvider.php` — register policy + named rate limiter
- `app/Services/Accounts/AccountCapabilities.php` — `canSubmitFeedback()`
- `config/partna.php` — `feedback` block
- `.env.example` — 5 new vars

## Implementation order

1. **Migration** → push to dev Supabase (`supabase link` dev → `db push --dry-run` → `db push`)
2. Model + factory (for tests)
3. Policy + register in `AppServiceProvider`
4. `AccountCapabilities::canSubmitFeedback()`
5. Config block + `.env.example`
6. FormRequest + Resource
7. Exceptions
8. Service (with `hashIp`, duplicate-window, capability gate)
9. Mailable + Blade template
10. Job
11. Rate limiter registration
12. Controller + routes
13. All tests
14. `composer test` green
15. `php artisan pint`
16. Manual smoke test: tinker dispatch + verify email in `Mail::fake()` log
17. Commit, PR into `development`

## Future extension points (zero migration cost)

| Future feature | What changes |
|---|---|
| Slack notifications | Add `SendFeedbackSlackJob`; dispatch alongside email from service. |
| Anonymous submissions | New public route + Turnstile middleware; `source='public_site'`; `user_id=null` already allowed. |
| Public site embed | Same as anonymous + Astro theme widget (separate concern from this backend plan). |
| Attachments | Reuse `SiteMedia` with `pool='feedback'`; add `feedback_id` FK to media or pivot table. |
| Admin triage UI | Add `Api/Staff/FeedbackController` + `StaffFeedbackResource`; policy `viewAny`/`update` already permit staff. |
| Public roadmap + voting | Add `is_public` boolean + `feedback_votes` table; no changes to this schema. |
| Auto-classification | Add post-create job calling LLM; writes back `kind`/`severity`/`tags`. |
| Feedback bans | Set `users.feedback_banned_at`; `canSubmitFeedback()` returns false; no controller change. |

## Open items — resolved 2026-05-25

- [x] **Notify emails:** dev → `jhunter7333@gmail.com,team@partna.au`; prod → `team@partna.au`. Set in Laravel Cloud env vars per environment (NOT in `.env.example`, which only documents the keys).
- [x] **IP hash peppers:** generated separately per env, set in Laravel Cloud env vars. Never committed.
- [x] **Rate limits:** 10/hour, 30/day per user confirmed as starting values. Env-tunable via `FEEDBACK_RATE_LIMIT_HOUR` / `FEEDBACK_RATE_LIMIT_DAY`.
- [x] **Trigger function:** `core.set_updated_at()` confirmed to exist in baseline at line 55.

## Audit corrections applied 2026-05-25

Codebase audit (run before implementation) flagged seven divergences; all addressed in this plan:

1. **Migration filename** — corrected to `20260526210001_create_feedback_table.sql` (must sort AFTER the deployed baseline `20260526000000`).
2. **Capability check** — `AccountCapabilities::for()` returns an `AccountCapabilitySet` value object (readonly properties). Added `can_submit_feedback: bool` property rather than a method.
3. **Routes file** — `routes/api/user.php` does not exist; plan now explicitly creates it and registers it in `routes/api.php`.
4. **Controller namespace** — `Api/User/` does not exist; plan explicitly creates the namespace.
5. **FormRequest namespace** — corrected from `Http/Requests/User/` to `Http/Requests/Api/User/` to match existing `Api/<Domain>/` nesting.
6. **`request_id` fallback** — `VerifySupabaseJwt` logs but does not expose `request_id` as a request attribute. Removed fallback; frontend must send it in the POST body if available.
7. **User resolution pattern** — documented the `$request->attributes->get('supabase_uid')` + `User::findOrFail()` pattern from `ProfessionalController.php` (never `Auth::user()`).

Items NOT requiring change:
- `core.set_updated_at()` exists ✅
- `core.users.id UUID` exists ✅
- `gen_random_uuid()` is the convention ✅
- `BaseModel`, `BasePolicy`, `AccountCapabilities::for()` all exist ✅
- `supabase.jwt` middleware alias confirmed ✅
- `PolicyCoverageTest` auto-discovers new models ✅
- Markdown mailables + `resources/views/emails/` confirmed ✅
- Named `RateLimiter::for()` pattern in `AppServiceProvider::boot()` confirmed ✅
- `Job::dispatch()->afterCommit()` is standard Laravel 12 syntax ✅
