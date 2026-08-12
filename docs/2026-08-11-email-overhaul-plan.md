# Partna outbound email — full audit & improvement plan (2026-08-11, v2 verified)

Read-only audit of every outbound email in Comet-Backend: 24 mailables + 9 Notification
classes, 31 Blade templates, the shared layout, the branding subsystem, and the Resend
pipeline. v2 status: every claim was adversarially re-verified against the code by an
independent pass — one claim from v1 was refuted (the preheader "injection", see
Hygiene), one was scoped down (white-label palette), and ten new avenues were added.

## Inventory (33 emails)

| Group | Emails |
|---|---|
| Auth (Supabase hook) | email-confirm (6-digit OTP), password-reset, magic-link, email-change, invite |
| Account lifecycle | deletion-requested/-scheduled/-cancelled, early-access invite + thank-you, claim-invite, handle-alias-expiring, GDPR data export (user + staff variants) |
| Site forms (white-labelled) | enquiry-confirmation (visitor), enquiry-notification (owner), subscription-confirmation |
| Category notifications | critical, incident, policy-update, feature-announcement, profile-tasks |
| Moderation (user/reporter) | account-suspended, account-banned, content-hidden, report-outcome |
| Staff-internal | feedback-submitted, staff-broadcast, T&S case-created, T&S case-escalated, T&S CSAM auto-action, T&S edge-purge-failed |

Already **good** (keep as-is): all sends queued on a dedicated `notifications` lane;
`BaseTransactionalMail`'s stable Message-IDs + CR/LF subject stripping; Resend
complaint/hard-bounce suppression checked pre-send; RFC 8058 one-click unsubscribe on
subscription-confirmation and staff-broadcast; per-site brand resolver with safe
fallback; the OTP code presentation (36px mono, selectable text); MSO bulletproof
button; SPF/DKIM/DMARC documented in the cutover checklist; **the two white-labelled
templates (enquiry-confirmation, subscription-confirmation) already read the full
`$brand->palette` including button colours and radius** — v1 wrongly claimed
otherwise.

---

## P0 — Broken or dangerous in production

### 1. Every email header logo 404s in production ✅ verified
`EmailBrand::partna()` (`app/Mail/Branding/EmailBrand.php:35-45`) builds logo URLs
from `config('app.frontend_url')` → `https://app.partna.au/branding/partna-{wordmark,icon}-{light,dark}.png`.
The monorepo dashboard now serving that domain has **no `public/branding/` directory**
(only `public/brand/*.svg` — different path, SVG). The icon pair
(`partna-icon-light/dark.png`) doesn't exist under that name in *any* repo, yet the
code sets those URLs unconditionally, so the layout's truthy check
(`partna.blade.php:84`) always picks the icon+wordmark branch — the fallback can never
fire. Partna-Frontend has the wordmark PNGs (6813×1796, ~90× oversized for the 76×20
slot) but is no longer served there.
*Caveat from verification: production `FRONTEND_URL` and the live DNS/Vercel routing
of app.partna.au couldn't be proven from the filesystem — before doing anything, curl
the four URLs from prod. If they 404 (expected), proceed.*

**Fix:** export a proper email asset set from the current brand SVGs — icon + wordmark,
light and dark, at 2× retina of display size (icon 40×40 @20×20, wordmark ~152×40
@76×20). Ship in the dashboard's `public/branding/` (or a stable asset host). Verify
with curl post-deploy. Deploy assets BEFORE any template change.

### 2. Dark-mode logo glitch ✅ verified — the bug you've seen
`partna.blade.php:51-57`: the `prefers-color-scheme: dark` block forces the body back
to **white** while simultaneously showing `.logo-dark` — the light-coloured logo made
for dark backgrounds. Apple Mail / iOS Mail (the one major client that honours the
query) gets a light logo on forced-white: invisible. Gmail ignores the query and
applies its own auto-inversion; classic Outlook ignores `<style>` and always shows the
light branch. Correct in Outlook, broken in Apple Mail, roulette in Gmail.

**Fix (recommend A):**
- **A. Always-light:** delete the `.logo-dark` swap + the dark-mode logo rules; change
  `<meta name="color-scheme">` to `light` only. Inversion-proof the logo for Gmail
  (small white plate or off-white matte + transparent border).
- **B. Real dark variant:** keep the swap and genuinely theme dark (bg + text tokens).
  More QA surface; only if email/app dark-mode parity matters.

### 3. Ban/suspension email can be silently suppressed ✅ verified, stronger than v1
`NotifyReportedUserJob.php:71` gates all user-facing moderation notifications on
`receive_moderation_notifications`, which is true only for `status === 'active'`
(`AccountCapabilities.php:58`). `ModerationActionDispatcher.php:105-119` dispatches
the suspend job and the notify job **independently in the same afterCommit callback**
— its own comment (line 64) says notify actions are "order-free", i.e. there is **no
ordering guarantee**: if the status flips first, the email telling the user their
account was closed silently never sends and the job marks itself completed.

**Fix:** exempt the outcome notice (ban/suspension/content-hidden) from the capability
gate, or snapshot the capability before the status write. Product/legal call —
right-to-notice on account closure.

---

## P1 — Brand & design correctness

### 4. Stale accent `#3a6efc` hardcoded in 11 places ✅ verified
Brand default moved to `#1367fb` (`EmailBrandDefaults.php:35`, 2026-08-09). Raw
`#3a6efc` remains at: `partna.blade.php:7,35` (comment + global `a{}` rule),
`button.blade.php:19` (default `color` prop — no Partna-branded call site overrides
it, so **every CTA button in Partna-branded mail is the wrong blue**),
`enquiry-notification.blade.php:25,50`, `email-change.blade.php:26`,
`password-reset.blade.php:26`, `invite.blade.php:26`, `magic-link.blade.php:26`,
`deletion-requested.blade.php:27`, `early-access-invite.blade.php:21-22`,
`claim-invite.blade.php:21`.

**Fix:** wire links + button defaults to `$brand->palette->accent/buttonBg/buttonText`
(already resolved and in scope); delete every hardcoded accent hex. Future accent
changes then propagate automatically.

### 5. Two moderation emails render in stock Laravel styling ✅ verified
`content-hidden.blade.php` uses `@component('mail::message')` — default Laravel theme,
no Partna branding — and **is live and user-facing**
(`ContentHiddenNotification.php:25`). `case-escalated.blade.php` is the same AND
**dead code** (its Notification builds mail inline via `->line()`, never referencing
the view). The other three T&S staff notifications (case-created, CSAM, edge-purge)
are also inline `->line()` mails on the default theme — staff-only, lower stakes.

**Fix:** rewrite content-hidden on `mail.layouts.partna`; delete the dead
case-escalated view; optionally move staff T&S mail onto the layout for consistency.

### 6. Button radius + Partna-template colours (scoped down from v1)
v1 overstated this: the two genuinely white-labelled templates already use the palette
fully. What remains: `x-mail.button`'s hardcoded `border-radius:980px` + `#3a6efc`
defaults are what all *Partna-branded* mail gets; and Partna-only templates hardcode
Apple greys per-file instead of sharing one source. Acceptable to keep fixed values
for Partna mail — but centralise them (layout/component-level), don't repeat per
template.

---

## P2 — Copy & UX (batch into one pass)

- **email-confirm H1 conflation** (`email-confirm.blade.php:7`): headline is either
  "Hi Sam," or "Verify your email", never both. Fixed action H1 + separate greeting,
  like its four siblings.
- **OTP anti-phishing line**: add "Never share this code — Partna will never ask you
  for it."
- **Preheader expiry consistency**: email-change + password-reset state expiry in the
  preheader; email-confirm, magic-link, invite don't. Align all five.
- **Invite copy** (`invite.blade.php:15`): "join Partna at {email}" reads like a
  company name — use the magic-link pattern ("…your account ({email})"); surface the
  inviter if the payload carries it.
- **Deletion date timezone** ✅ verified: `now()->addDays()->toDayDateTimeString()`
  with app tz UTC, printed with no zone label on a legally consequential date. State
  the zone or render in the user's timezone.
- **handle-alias-expiring**: subject "day(s)" unpluralised (body does it right); and
  it's the only actionable email with **no CTA button**. Fix both.
- **Enquiry confirmation false promise** (`enquiry-confirmation.blade.php:14-16`):
  says "reply directly to this email" even when no pro reply-to is configured and
  replies land at Partna's generic inbox. Make conditional.
- **Owner enquiry notification**: set Reply-To to the enquirer so the owner can just
  hit Reply.
- **Double-escaped names** ✅ verified: `e()` inside `{{ }}` in
  `enquiry-confirmation.blade.php:7` + `subscription-confirmation.blade.php:11` —
  O'Brien renders as `&#039;`. Drop the inner `e()`.
- **email-change security nudge** (low): suggest reviewing account security on
  unrequested changes.

## P3 — Deliverability & infrastructure

- **Plain-text parts: none anywhere** ✅ verified (zero `->text()` calls). Add text
  views (or one generic text renderer). Biggest single deliverability lever here.
- **Category notifications lack unsubscribe affordance** ✅ verified: no
  List-Unsubscribe header, no settings link — yet per-user category opt-outs already
  exist backend-side. Feature-announcement is marketing-shaped and most exposed to
  Gmail/Yahoo bulk rules. Add one-click List-Unsubscribe → category opt-out + a
  "Manage notification emails" footer link for non-critical categories.
- **Notification classes bypass `BaseTransactionalMail`**: no CR/LF subject defence,
  no stable Message-ID, no pipeline header for the whole moderation/T&S family. Add a
  shared MailMessage factory/trait.
- **CTA URL scheme allowlist** on `x-mail.button` (staff-authored today; guard before
  the source widens).
- **Resend tags** per mail family for segmentation/analytics.
- **Consolidate** the five byte-identical category wrappers ✅ verified; move
  `handle-alias-expiring.blade.php` into `emails/account/`.
- **Layout naming**: `.logo-dark`/`text-primary` classes imply a dark theme that
  doesn't exist — rename once P0-2's strategy is decided.

## Hygiene (v1's "injection" claim, corrected)

v1 flagged the enquiry-notification preheader as an HTML-injection vector.
**Refuted:** Laravel auto-escapes inline `@section('x', $value)` values
(`ManagesLayouts::startSection()` applies `e()`), so there is **no vulnerability**.
What survives: four templates use bare interpolation while five wrap in `e()` (now
double-escaping risk-free but inconsistent), and `$enquiry->subject` is only `trim()`'d
while sibling fields get `strip_tags` (`PublicEnquiryRequest.php:19`). Normalise the
preheader convention and add `strip_tags` to subject for consistency — hygiene, not
security.

---

## New avenues (from the second-look pass — not in v1)

1. **Supabase `reauthentication` emails fall through to Supabase's unbranded default
   template** — `SupabaseEmailHookController::resolveMailable()` handles only
   signup/recovery/magiclink/invite/email-change; anything else returns null. Add a
   branded `ReauthenticationMail` or document why reauth is never enabled.
2. **No password-changed / new-login security notice exists** — standard SaaS security
   control, cheap to add on the existing base class.
3. **`ProfileTaskMail` is orphaned** — its emit site was removed with the
   account-type strip (documented in `MailableCategoryCoverageTest.php:26-30`); it's
   advertised in config but can never fire. Wire a real emit site or delete it.
4. **No weekly analytics digest email** — `NotifyWeeklySummary` is an in-app stub by
   design (`analytics_weekly => null` in config). A real re-engagement lever when
   per-user numbers are ready.
5. **No mailable preview/test infrastructure** — no preview route, no Mailpit in
   `.env.example`, no render-snapshot tests. Add a dev-gated mail gallery (mirroring
   the dashboard's `/dev/components` pattern) + HTML snapshot assertions per template.
   This is the prerequisite for safely doing everything above.
6. **No locale hook** — no `locale` column on users, English hardcoded. Fine under AU
   doctrine; recorded as a known scaling limit, not work.
7. **No UTM params on email CTAs** — email-driven traffic is invisible to the
   analytics pipeline. Append `utm_source=email&utm_medium=transactional&utm_campaign={category}`.
8. **OTP and marketing share one sending identity** (`hello@partna.au` for
   everything). If bulk mail trips a spam rule, OTP deliverability degrades with it.
   Consider a subdomain/address split (e.g. `updates@` for bulk vs `hello@` for
   transactional+auth); also cleaner for future BIMI.
9. **No Resend error-rate alerting** — webhook acts per-event (suppression) but
   nothing aggregates bounce/complaint *rate* to alert before a deliverability
   crisis. Rolling Redis counter + the existing Horizon Slack channel.
10. **No per-user email volume throttling/digesting** — multiple category events in a
    day = multiple emails; only per-notification dedupe exists. Decide deliberately:
    send-as-generated vs batch within a window.

## Execution order

1. **Phase 0 — verify prod state:** curl the four branding URLs + confirm
   `FRONTEND_URL` on Laravel Cloud (the one thing the audit couldn't prove from disk).
2. **Phase 1 — visible breakage (same day):** assets exported/resized + shipped in the
   dashboard (deploy first), dark-mode strategy A, accent → palette wiring (11 sites).
3. **Phase 2 — correctness:** moderation gate fix (P0-3), content-hidden onto the
   layout + delete dead view, full P2 copy batch in one pass.
4. **Phase 3 — deliverability:** plain-text parts, category unsubscribe, Notification
   hardening, preheader/`strip_tags` hygiene, tags + UTM, consolidation.
5. **Phase 4 — new surfaces (product decisions):** reauthentication mail,
   password-changed notice, ProfileTask fate, weekly digest, sending-domain split,
   rate alerting, volume batching.
6. **Verification throughout:** build the dev mail preview first (avenue 5), then
   seed-inbox pass in Apple Mail dark mode, Gmail web+iOS, Outlook.

Lanes: backend work in Comet-Backend on a feature branch; the asset drop is a
monorepo-dashboard commit. Assets deploy before any template change ships.

---

# Status (2026-08-12): SHIPPED through Phase 3 + reauthentication

Everything above through P3 is implemented, tested (full suite green) and
deployed to Laravel Cloud development. Highlights of how it landed:

- **P0-1** resolved WITHIN this repo (owner's call — no other repos touched):
  the icon/wordmark PNGs live in `public/branding/` and `EmailBrand::partna()`
  builds URLs off `app.url` (dev-api.partna.au), verified 200 post-deploy and
  verified end-to-end via cloud tinker.
- **P0-2** strategy A (always-light); the four light/dark URL fields collapsed
  to `iconUrl`/`wordmarkUrl`; missing-URL fallback is a text wordmark.
- **P0-3** suspend/ban notices bypass the capability gate (right-to-notice);
  content-hidden keeps it; regression test added.
- **P1/P2** all copy items shipped; content-hidden on the shared layout; dead
  case-escalated view deleted; button defaults centralised.
- **P3** derived text/plain part on every mail (HtmlToText); category mail
  consolidated behind `CategoryNotificationMail` + one view; RFC 8058
  one-click unsubscribe via signed route `public.notification-unsubscribe`
  flipping NotificationEmailPreference; button scheme allowlist + UTM;
  Resend TagHeader per mail family; Notification-channel hardening via
  `BuildsPartnaMailMessage`; handle-alias view moved to emails/account/.
- **Phase 4 new**: branded `ReauthenticationMail` wired into the Supabase hook.

## Deliberately NOT done (product/infra decisions, revisit when wanted)

- Password-changed / new-login security notice — no backend emit site exists
  (password changes happen inside Supabase); needs a hook or product decision.
- ProfileTaskMail fate — still orphaned; kept + documented in its docblock.
- Weekly analytics digest; transactional/bulk sending-identity split;
  bounce/complaint-rate alerting; per-user volume batching.
- Seed-inbox pass in real clients (Apple Mail dark, Gmail web+iOS, Outlook) —
  the local gallery (`/dev/emails`, `?dark=1`) was the verification surface.
- ABN / registered address in the legal footer — owner hasn't supplied them.
