# Supabase Bot Protection — Operator Runbook

This doc covers Path B (Supabase-mediated) bot protection per
[bot-protection foundation spec §13](../superpowers/specs/2026-05-26-bot-protection-foundation-design.md).

The Laravel backend uses its own `bot.token` middleware for `/public/*`
mutation endpoints (Path A). Path B is everything that lives at Supabase:
signup, signin, password recovery, magic link.

## When to enable

After the backend bot-protection PR has shipped and `TURNSTILE_SITE_KEY` /
`TURNSTILE_SECRET` are set in Laravel Cloud, you can enable Bot Protection
in Supabase Dashboard for the matching environment.

## Steps (per environment)

1. **Open Supabase Dashboard** for the target project:
   - Dev:  `https://supabase.com/dashboard/project/glncumufgaqcmqhzwrxm`
   - Prod: `https://supabase.com/dashboard/project/edplucmvkcnokyygxqsb`
2. Navigate to **Authentication → Settings → Bot and Abuse Protection**.
3. **Enable** the toggle.
4. Provider: **Turnstile**.
5. Paste the **same secret** that Laravel uses for `TURNSTILE_SECRET` in
   this environment (Cloudflare Turnstile site — one secret powers both Laravel
   and Supabase).
6. Scope: enable for signup, signin, password recovery, magic link.
7. Save.

## Verification (REQUIRED before enabling in production)

Confirm Supabase enforces the CAPTCHA token server-side — not just at the
frontend. Otherwise an attacker with the public anon key can call
`/auth/v1/signup` directly via the JS SDK and bypass the Turnstile widget.

Run this curl from your terminal **after** enabling Bot Protection on the
target environment:

```bash
# Replace <project-ref> and <anon-key> with the target project's values.
curl -i -X POST \
  -H 'Content-Type: application/json' \
  -H 'apikey: <anon-key>' \
  -d '{"email":"bot-test+'$(date +%s)'@example.com","password":"test-password-123"}' \
  'https://<project-ref>.supabase.co/auth/v1/signup'
```

**Expected:** HTTP `400` with a body mentioning CAPTCHA (e.g. `"captcha protection: verification process failed"`).

**If you get HTTP `200`:** Bot Protection is NOT enforcing server-side.
DO NOT enable in production. Escalate to Supabase support.

## Frontend coordination

The Astro frontend must pass the Turnstile token through the Supabase JS SDK:

```js
const { error } = await supabase.auth.signUp({
  email,
  password,
  options: { captchaToken },  // token from <Turnstile> widget
})
```

This is frontend work, tracked in the frontend repo. Once Bot Protection is
on, the SDK call WILL fail without `captchaToken` — make sure the frontend
ships the change before enabling, or do this in this order:
1. Frontend deploys with widget + captchaToken pass-through.
2. Run the verification curl above (it should still 200 before enabling
   — confirming pre-state).
3. Enable Bot Protection in the dashboard.
4. Re-run the verification curl — should now 400.

## Rollback

If something breaks, **disable Bot Protection** in the Dashboard.
Effect is immediate (no deploy needed). Investigate, fix, re-enable.
