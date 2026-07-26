# Frontend handoff — production cutover

**Date:** 2026-07-26 · **From:** Josh (backend) · **For:** frontend / dashboard

Backend production cutover is complete. `api.partna.au` now runs on its own Laravel Cloud
environment backed by its own Supabase project. It is no longer the same database dev talks to.

**Nothing in the API contract changed.** Endpoints, payloads, error envelopes, and auth flow are
identical to what you build against on dev. This is purely an environment/credentials switch.

---

## Status: prod is verified healthy — you're clear to proceed

Verified 2026-07-26 10:07 UTC: database reachable, Horizon running, scheduler firing, no failed
jobs. Everything below is safe to do.

**One habit to pick up, though: don't use `/api/health` to judge whether prod is up.** It's a
liveness probe that never touches the database — it returned `{"ok":true}` throughout an hour-long
outage where every real request was 500ing. Use this instead:

```bash
curl -s -o /dev/null -w "%{http_code}\n" \
  https://api.partna.au/api/public/profiles/does-not-exist
# 404 = healthy (the app queried the DB and correctly found nothing)
# 500 = broken, regardless of what /api/health says
```

A `404` here proves the whole path works: routing, app boot, and a real database round-trip.

---

## 1. What actually changed

| | Production | Development |
|---|---|---|
| API base | `https://api.partna.au` | `https://dev-api.partna.au` |
| Dashboard | `https://app.partna.au` (Vercel) | `https://dev-app.partna.au` |
| Supabase project | `edplucmvkcnokyygxqsb` | `glncumufgaqcmqhzwrxm` |
| Git branch | `production` | `development` |

Before cutover both hostnames were served by one environment against one database. They are now
fully separate and deploy independently — pushing `development` no longer touches prod.

Prod is running async workers (Horizon on Redis); dev's behaviour is the same. Scheduler is live
on prod and firing.

---

## 2. What you need to change (Vercel, `app.partna.au` project)

Update the **Production** environment only. Leave Preview/Development pointing at dev.

| Variable | New production value |
|---|---|
| `NEXT_PUBLIC_API_BASE_URL` | `https://api.partna.au` |
| `NEXT_PUBLIC_SUPABASE_URL` | `https://edplucmvkcnokyygxqsb.supabase.co` |
| `NEXT_PUBLIC_SUPABASE_ANON_KEY` | the anon key from the **prod** Supabase project (see below) |
| `NEXT_PUBLIC_COMET_PUBLIC_DOMAIN` | `partna.au` |

Grab the anon key from Supabase → project `edplucmvkcnokyygxqsb` → Settings → API. It is not in
this doc on purpose; take it from the source so there's no chance of a stale paste.

Redeploy after setting them — `NEXT_PUBLIC_*` values are inlined at build time, so changing the
variable without rebuilding does nothing.

### The one that will bite you

`NEXT_PUBLIC_SUPABASE_URL` and `NEXT_PUBLIC_SUPABASE_ANON_KEY` **must** move together with
`NEXT_PUBLIC_API_BASE_URL`. Partna has no backend login — the frontend forwards a Supabase JWT and
the backend verifies it against that project's JWKS. Prod backend accepts issuer:

```
https://edplucmvkcnokyygxqsb.supabase.co/auth/v1
```

A token minted by the dev Supabase project carries the dev issuer, fails JWKS verification, and
you get **401 on every authenticated call** with a login screen that looks like it's working. If
you see blanket 401s after the switch, this is the cause — check which project issued the token
before checking anything else.

---

## 3. Local development

**Keep local pointed at dev.** Prod's CORS allowlist is exact-origin and deliberately excludes
localhost:

```
prod:  https://partna.au, https://www.partna.au, https://app.partna.au
dev:   ...the above, plus dev-app.partna.au, localhost:5173, localhost:3000 (+127.0.0.1)
```

Verified: a preflight from `http://localhost:5173` to `api.partna.au` comes back with **no**
`Access-Control-Allow-Origin` header. This is intentional — don't ask for localhost to be added to
prod. Develop against `dev-api.partna.au` + the dev Supabase project, exactly as before.

---

## 4. Prod has no users

Prod's `core.users` is empty. Nobody's dev account exists there — you'll need to sign up fresh
through the real flow to test. Sites, media, and handles created on dev do not appear on prod.

---

## 5. Two things to be aware of

**Turnstile is live on prod, in shadow mode.** Bot protection is configured on production
(`BOT_PROTECTION_DRIVER=turnstile`, `BOT_PROTECTION_MODE=shadow`) and absent on dev. Shadow means
it observes and logs but does not block, so nothing breaks today — but if you render the widget,
the prod site key is `0x4AAAAAADYQavnFxIrHISsV`. Before it flips out of shadow mode, the signup
form will need to actually pass a token. Worth designing for now rather than later.

**Prod and dev still share one Cloudflare KV namespace.** This is a known open gap on the backend
side, not something you can fix, but it affects what you'll see: `<handle>.partna.au` subdomain
routing reads a single KV namespace that *both* environments write to. If the same handle is
created on dev and on prod, last writer wins and the public mini-site may serve the wrong
environment's data. Until it's split, treat handle collisions between envs as a real possibility
and don't be surprised by odd public-site results while testing.

---

## 6. Heads-up on the API docs

`docs/api.md` §1 ("Environments and Base URLs") is stale — it still references `sidest.co` and
`SIDEST_PUBLIC_DOMAIN` from before the rename. Ignore that section and use the table in §1 above.
The rest of `docs/api.md` (auth, endpoints, models, error format) is current and accurate.

---

## 7. Verification checklist

Run these after the DB blocker clears and you've redeployed:

```bash
# 1. Prod is healthy (not just alive)
curl -s -o /dev/null -w "%{http_code}\n" https://api.partna.au/api/public/profiles/does-not-exist
# expect 404

# 2. CORS allows the dashboard
curl -sI -X OPTIONS https://api.partna.au/api/public/profiles/x \
  -H "Origin: https://app.partna.au" -H "Access-Control-Request-Method: GET" \
  | grep -i access-control-allow-origin
# expect: access-control-allow-origin: https://app.partna.au
```

Then in the browser on `app.partna.au`:

- [ ] Sign up with a new email — OTP arrives from `hello@partna.au`
- [ ] `POST /api/bootstrap` returns 200 (not 401, not 410)
- [ ] Create a site, publish it, confirm `<handle>.partna.au` renders
- [ ] Upload an image — variants appear (this exercises the async worker path)
- [ ] Network tab shows requests going to `api.partna.au`, and the JWT's `iss` is the
      `edplucmvkcnokyygxqsb` issuer

Anything 500s or 401s across the board → tell Josh, don't work around it.
