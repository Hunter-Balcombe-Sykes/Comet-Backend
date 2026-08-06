#!/bin/bash
# Mint a real ES256 Supabase JWT from the LOCAL GoTrue for the drill user.
# Prints the access token on stdout; diagnostics on stderr.
# Re-run before each drill phase — jwt_exp is 900s as of 2026-08-05.
set -euo pipefail

EMAIL="${DRILL_EMAIL:-drill-rd-20260806@drill.local}"
SECRET=$(supabase status -o env 2>/dev/null | grep '^SERVICE_ROLE_KEY=' | cut -d= -f2- | tr -d '"')

# Local GoTrue is captcha-gated, so the password grant returns captcha_failed.
# The admin API is not captcha-gated: generate a magiclink, then follow it WITHOUT
# redirect — the session comes back in the Location fragment.
RESP=$(curl -s -X POST "http://127.0.0.1:54321/auth/v1/admin/generate_link" \
  -H "apikey: $SECRET" -H "Authorization: Bearer $SECRET" -H 'Content-Type: application/json' \
  -d "{\"type\":\"magiclink\",\"email\":\"$EMAIL\"}")

LINK=$(printf '%s' "$RESP" | python3 -c "import json,sys; print(json.load(sys.stdin).get('action_link',''))")
if [ -z "$LINK" ]; then
  echo "GENERATE_LINK FAILED: $RESP" >&2
  exit 1
fi

TOKEN=$(curl -s -o /dev/null -D - "$LINK" \
  | grep -i '^location:' \
  | sed -n 's/.*access_token=\([^&]*\).*/\1/p' \
  | tr -d '\r')

if [ -z "$TOKEN" ]; then
  echo "MINT FAILED: $RESP" >&2
  exit 1
fi

# Decode and report the claims that matter, so no probe runs on an unverified token.
printf '%s' "$TOKEN" | python3 -c "
import base64, json, sys, time
t = sys.stdin.read().split('.')[1]
c = json.loads(base64.urlsafe_b64decode(t + '=' * (-len(t) % 4)))
print('  sub=%s' % c.get('sub'), file=sys.stderr)
print('  session_id=%s' % c.get('session_id'), file=sys.stderr)
print('  aal=%s' % c.get('aal'), file=sys.stderr)
print('  email_verified=%s' % c.get('user_metadata', {}).get('email_verified', c.get('email_verified')), file=sys.stderr)
print('  exp-iat=%ss' % (c['exp'] - c['iat']), file=sys.stderr)
print('  minted_at=%s  expires_at=%s' % (
    time.strftime('%H:%M:%S', time.localtime(c['iat'])),
    time.strftime('%H:%M:%S', time.localtime(c['exp']))), file=sys.stderr)
"
printf '%s' "$TOKEN"
