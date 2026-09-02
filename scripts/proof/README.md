# Live-proof harness (dev)

Rebuild unclaimed test sites through the public signup flow and read the
result off the DB and the public wire. Used by giant runs (`giant-run`
skill) for the one-proof-per-phase rule. All against `dev-api.partna.au`.

| Script | What |
|---|---|
| `campaign-build.sh <label> <partna\|business> <instagram\|google_business> <ref> [name]` | POST a build, poll it, log state/subdomain/kv_live/tier timings to `$PROOF_LOG_DIR/run-<label>.jsonl` (default `/tmp`). Run two in parallel (`&`) to prove queue/timing claims. |
| `proof.sh <build_id>` | One tinker round trip: build state, user, connections (with team member/auto-select), ingest sources, stage events, item kinds, link cards (platform, image), services, contact, storefront codes. |
| `wire.sh <handle>` | Public wire read (`/api/public/profiles/{handle}`): actions, pageOrder, pool counts, link cards' platform/thumbnail, first items, publicContact, workplace. |
| `tinker.sh '<php>'` | Run PHP on dev via `cloud tinker` and print only the output. ~30 s a round trip: batch, and prefer Supabase `execute_sql` for reads. |
| `deploy-wait.sh [env] [max_s]` | Block until the latest deployment succeeds or fails; one line per state change. |

Teardown of a proof build: set its `expires_at` in the past via `tinker.sh`,
then `cloud command:run development --cmd="php artisan builds:prune-expired" --no-interaction`.
