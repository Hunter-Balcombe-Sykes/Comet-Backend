#!/bin/bash
# Public wire read for one handle. Usage: wire.sh <handle>
curl -s "https://dev-api.partna.au/api/public/profiles/$1" | python3 -c '
import json, sys
d = json.load(sys.stdin)["data"]
p = d["profile"]
acts = (d.get("actions") or {}).get("entries") or []
print("actions:", [(a.get("position"), a.get("label"), a.get("id")) for a in acts[:5]])
print("pageOrder:", d.get("pageOrder"))
pools = p.get("pools") or {}
print("pools:", {k: len((v or {}).get("items") or []) for k, v in pools.items()})
links = (pools.get("custom_links") or {}).get("items") or []
for l in links[:12]:
    print("  link:", (l.get("headline") or "?")[:45], "| platform:", l.get("platform"), "| thumb:", "yes" if l.get("thumbnail") else "NO", "|", (l.get("url") or "")[:60])
for key in ("watch", "services", "media", "shop"):
    items = (pools.get(key) or {}).get("items") or []
    if items:
        noimg = sum(1 for i in items if not i.get("thumbnail"))
        print(f"  {key}: {len(items)} items, {noimg} without thumbnail; first:", (items[0].get("headline") or "")[:40], "| platform:", items[0].get("platform"))
print("publicContact:", p.get("publicContact"))
wp = p.get("workplace") or {}
print("workplace:", {k: wp.get(k) for k in ("name", "phone", "contactEmail")})
'
