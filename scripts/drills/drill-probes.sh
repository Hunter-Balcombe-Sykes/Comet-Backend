#!/bin/bash
# Drill 03 probe set. Usage: ./drill-probes.sh <token> [par|seq]
# Default is PARALLEL — sequential probes let later ones measure a recovered Redis.
TOKEN="$1"
MODE="${2:-par}"
BASE="${BASE:-http://127.0.0.1:8000}"
HANDLE="${HANDLE:-drill-rd-20260806}"
ORIGIN="http://${HANDLE}.localhost"   # <subdomain>.<config('partna.public_domain')>

p_profile()  { curl -s -o /dev/null -m 60 -w "profile   %{http_code}  %{time_total}s\n" "$BASE/api/public/profiles/$HANDLE"; }
p_health()   { curl -s -o /dev/null -m 60 -w "health    %{http_code}  %{time_total}s\n" "$BASE/api/health"; }
p_pageview() { curl -s -o /dev/null -m 60 -w "pageview  %{http_code}  %{time_total}s\n" -X POST "$BASE/api/public/analytics/pageviews" \
                 -H 'Content-Type: application/json' -H "Origin: $ORIGIN" -d "{\"subdomain\": \"$HANDLE\"}"; }
# form_started_at_ms is required by the bot-detection rule; back-date it so the
# submission looks human rather than scripted.
p_enquiry()  { local started=$(( $(date +%s) * 1000 - 8000 ));
               curl -s -o /dev/null -m 60 -w "enquiry   %{http_code}  %{time_total}s\n" -X POST "$BASE/api/public/enquiry" \
                 -H 'Content-Type: application/json' -H "Origin: $ORIGIN" \
                 -d "{\"name\":\"Drill\",\"email\":\"drill@example.com\",\"subject\":\"General enquiry\",\"message\":\"drill drill drill\",\"form_started_at_ms\":$started}"; }
p_authed()   { curl -s -o /dev/null -m 60 -w "authed    %{http_code}  %{time_total}s\n" -H "Authorization: Bearer $TOKEN" "$BASE/api/site"; }
# The strict probe. NOTE: /api/me/data-export is deliberately NOT in the repeated set —
# its inline `throttle:1,1440` allows one call per day, so it cannot be re-probed per phase.
p_strict()   { curl -s -o /dev/null -m 60 -w "STRICT-lo %{http_code}  %{time_total}s\n" -X POST -H "Authorization: Bearer $TOKEN" \
                 -H 'Content-Type: application/json' "$BASE/api/sessions/logout-others"; }

if [ "$MODE" = "seq" ]; then
  p_profile; p_health; p_pageview; p_enquiry; p_authed; p_strict
else
  p_profile & p_health & p_pageview & p_enquiry & p_authed & p_strict &
  wait
fi
