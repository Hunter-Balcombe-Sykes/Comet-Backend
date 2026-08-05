#!/usr/bin/env bash
# audit.sh — One-command audit: DeepSeek scan(s) → Claude adjudicate → final audit file.
#
# Targeted mode (single lens):
#   scripts/audit/audit.sh \
#     --lens "auth/policy coverage on the new SitePolicy" \
#     --scope app/Policies/SitePolicy.php \
#     --scope app/Http/Controllers/Api/User/Uploads/
#
# Full mode (8 lens-focused scans, then one adjudication):
#   scripts/audit/audit.sh --full --scope app/Services/Stripe
#
#   Alias for --bundle core. Runs eight focused DeepSeek scans against the same
#   scope (security, lifecycle-correctness, scaling-antipatterns,
#   database-and-queue-scaling, schema-rls, caching-gold-standard,
#   webhook-idempotency, transaction-boundaries) then ONE Claude adjudication
#   over the merged drafts. Use this when you want broad correctness coverage
#   and don't have a specific theme.
#
# Bundle mode (named lens groups):
#   scripts/audit/audit.sh --bundle <name> --scope <path>
#
#   Available bundles:
#     core             — the 8 always-relevant correctness lenses (same as --full)
#     concurrency      — caching-gold-standard + webhook-idempotency + transaction-boundaries
#                        (correctness-under-concurrency trio: silent state drift bugs)
#     pre-merge        — migration-safety + api-contract + configuration-hygiene + test-coverage
#                        + test-prod-parity (run before merging a PR that touches schema or
#                        public API; parity catches SQLite-green/Postgres-500 writes)
#     code-quality     — code-quality-slop + semantic-correctness
#                        (AI slop + plausible-but-wrong logic the compiler can't see;
#                         the qualitative companion to `composer analyse`)
#     pre-pilot        — core + data-integrity + job-queue-correctness + observability
#                        + caching-coverage-gaps (12 lenses; full pre-pilot correctness sweep)
#     security         — security + schema-rls + configuration-hygiene + edge-worker
#                        + privacy-compliance (the pure security & tenant-isolation
#                        sweep: auth, IDOR, injection, SSRF, RLS, secrets, PII).
#                        Use with --codebase for a whole-backend security audit.
#     launch-readiness — security + privacy-compliance + edge-worker + configuration-hygiene
#                        + migration-safety + api-contract (the go-live gate)
#     scale-health     — caching trio + database-and-queue-scaling + job-queue-correctness
#                        + observability, graded against a 10k-user target (run post-launch)
#     full-sweep       — ALL 21 lenses (the stage bundles plus test-coverage,
#                        test-prod-parity, code-quality-slop, semantic-correctness).
#                        Nothing left unturned; use with --codebase for a whole-repo audit.
#     cross-repo       — frontend-backend-contract (XREPO) + cross-repo-dead-code (XDEAD):
#                        backend↔frontend contract drift & frontend/cross-repo dead code.
#                        Targeted mode ONLY — --scope the relevant backend paths + the
#                        $PARTNA_FRONTEND_PATH frontend paths + audits/cross-repo/CONTRACT-INVENTORY.md
#                        (run scripts/audit/contract-inventory.sh first). NOT for --codebase.
#
# Codebase mode (whole-repo audit, per-lens scopes — usually with pre-pilot /
# launch-readiness / scale-health / full-sweep):
#   scripts/audit/audit.sh --codebase --bundle pre-pilot [--name <name>]
#
#   Runs every lens in the bundle against that lens's own built-in scope map
#   (chunked to stay under the scan-recall payload ceiling), then adjudicates
#   PER LENS with repo tools (--no-source), emitting one audit file per lens
#   PLUS a CONSOLIDATED.md, into audits/sweeps/<date>-<bundle>/.
#   --scope / --lens are not allowed with --codebase.
#
# Changed-scope mode (audit a body of work, not the whole repo):
#   scripts/audit/audit.sh --codebase --bundle full-sweep \
#     --changed-since <git-ref> --name <name>
#
#   Same execution as codebase mode, but every lens's scope map is narrowed to
#   the files changed between <git-ref> and HEAD. A lens chunk with no changed
#   file drops out; an oversized one re-packs into <chunk>-1, <chunk>-2, ...
#   Scans see only the diff; adjudication still runs with --no-source, so the
#   adjudicator Reads the surrounding code to verify. Use this to sweep a
#   feature branch or a week of commits with the full lens battery.
#
# Resuming a partial sweep (a lens died mid-run — API 402, session limit, ...):
#   scripts/audit/audit.sh --codebase --bundle full-sweep --changed-since <ref> \
#     --name <same-name> --run-date <the run's date> --only-lenses <csv of missing>
#
#   --run-date pins the output folder to the original run's date so the new lens
#   files land beside the ones that already succeeded. CONSOLIDATED.md is then
#   rebuilt from every audit-*.md in the folder, not just the resumed lenses.
#   Codebase mode exits non-zero if any lens produced nothing.
#
# Parallelism:
#   DeepSeek scans run in parallel waves (--scan-jobs N, default 4) — they are
#   independent stateless API calls. Claude adjudications run SEQUENTIALLY by
#   design: they share the local claude CLI's plan/rate budget, and serial
#   verification keeps failures isolated and the run resumable. Don't run two
#   audit.sh invocations concurrently for the same reason.
#
# Output layout — ONE folder per run, always containing CONSOLIDATED.md:
#   targeted single-topic   → audits/<category>/<date>-<name>/CONSOLIDATED.md
#   bundle / codebase sweep → audits/sweeps/<date>-<name>/CONSOLIDATED.md
#                             (codebase mode also writes one audit-<date>-<lens>.md per lens)
#
#   --category <cat>   top-level folder for targeted runs (default: misc)
#   --name <name>      short run name (default: slug of the lens, or the bundle name)
#
#     scripts/audit/audit.sh --category security --name frontpage \
#       --lens "policy coverage on the frontpage" --scope app/Policies
#     # → audits/security/YYYY-MM-DD-frontpage/CONSOLIDATED.md
#
# CONSOLIDATED.md always opens with a deterministic header: scope + paths,
# a tier-count table, and the Execution policy (which Claude model plans /
# implements / reviews). Run the fixes by saying `execute audit <that file>`
# (runbook: scripts/audit/fix-flow.md).
#
# Auth:
#   DEEPSEEK_API_KEY  loaded from scripts/audit/.env (gitignored) or shell env
#   Claude            uses the local `claude` CLI's existing OAuth login
#
# Pass --keep-drafts to keep the intermediate DeepSeek drafts (in <folder>/.drafts/).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# --- Load .env if present ---
ENV_FILE="$SCRIPT_DIR/.env"
if [[ -f "$ENV_FILE" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    set +a
fi

# --- Args ---
SCOPE_ARGS=()
LENS_ARG=()
FULL=false
BUNDLE=""
CATEGORY=""        # top-level folder for targeted audits (e.g. security, caching)
NAME=""            # short run name (e.g. frontpage, shopify-integration)
KEEP_DRAFTS=false
CODEBASE=false
SCAN_JOBS=4
ONLY_LENSES=""     # csv of lens basenames to keep from a bundle (resume after partial failure)
CHANGED_SINCE=""   # git ref: narrow every lens's codebase scope to files changed since it
CHANGED_LIST=""    # internal: tmp file of "path<TAB>bytes" for the changed set
RUN_DATE_OVERRIDE="" # --run-date: write into an existing run folder (resume a partial sweep)
OUT=""             # internal: computed path to the folder's CONSOLIDATED.md

# Max source bytes per scan chunk. Mirrors the ~350KB ceiling the codebase scope
# map is hand-sized against; scan recall degrades on oversized payloads.
CHUNK_MAX_BYTES=300000

# Print the whole comment header (everything up to `set -euo`) as help text,
# so the header can grow without the range going stale.
usage() { awk '/^set -euo/{exit} NR>1 {sub(/^# ?/,""); print}' "$0"; }

# slugify "Some Text!" -> "some-text" (used to derive a folder name from a lens)
slugify() {
    echo "$1" | tr '[:upper:]' '[:lower:]' \
        | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//' \
        | cut -c1-48
}

# Count finding-header checkbox lines for a given tier (P0/P1/P2/P3) in a file.
# Matches the canonical line:  - [ ] **#ID** · P0 — title
# The `#` is optional: the adjudicator emits `**#SEC-1**` most of the time but
# drops the hash on some runs (`**SLOP-7**`), and a `#`-required matcher silently
# undercounts those. Found independently twice — it swallowed 9 findings incl. a
# P0 on the 2026-07-08 full-sweep, and rendered "Total 0" above nine findings on a
# 2026-07-19 probe run. A wrong number is worse than no number; match both forms.
tier_count() {
    grep -cE "^- \[[ x]\] \*\*#?[A-Za-z].*· $1 " "$2" 2>/dev/null || true
}

# Emit the shared Execution-policy block. Single source of truth for the model
# assignment baked into every CONSOLIDATED.md header — mirrored in
# scripts/audit/fix-flow.md and CLAUDE.md. Keep the three in sync.
exec_policy_block() {
    cat <<'EOF'
## Execution policy  (how `execute audit` runs this file)

- **Plan:**       Opus 4.8
- **Implement:**  Sonnet 4.6
- **Review:**     Sonnet 4.6  — a *separate, independent* instance (never the implementer)
- **Combine plan+impl:** YES for S/XS effort · NO for P0/P1 or L/XL (those plan first, then implement)
- **Per-item override:** escalate to Opus for gnarly logic or risky blast radius; a trivial
  mechanical S item may drop to Haiku. Default to the table above unless an item clearly warrants a change.
- **Trigger:** say `execute audit <path to this file>` to run plan → implement → independent review
  per bundle/item. Blockers (P0 · auth · money · DB/migration · L/XL) pause for sign-off.
  Full runbook: `scripts/audit/fix-flow.md`.
EOF
}

# Build CONSOLIDATED.md: deterministic header (scope + counts + exec policy)
# prepended to the adjudicated body. $1=final file, $2=title, $3=lens/bundle
# text, $4=newline-separated scope paths.
write_consolidated() {
    local final="$1" title="$2" lens="$3" scopes="$4"
    local body p0 p1 p2 p3 total branch
    body="$(mktemp)"
    cp "$final" "$body"
    p0=$(tier_count P0 "$body"); p1=$(tier_count P1 "$body")
    p2=$(tier_count P2 "$body"); p3=$(tier_count P3 "$body")
    total=$((p0 + p1 + p2 + p3))
    branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')"
    {
        echo "# ${title} — CONSOLIDATED — $(date +%F)"
        echo ""
        echo "## Scope"
        echo ""
        echo "- **Lens:** ${lens}"
        echo "- **Branch:** ${branch}"
        echo "- **Generated by:** \`scripts/audit/audit.sh\` (DeepSeek scan + Claude adjudication)"
        echo "- **Paths audited:**"
        while IFS= read -r p; do [[ -n "$p" ]] && echo "    - \`${p}\`"; done <<< "$scopes"
        echo ""
        echo "## Findings at a glance"
        echo ""
        echo "| Tier | Count |"
        echo "|------|-------|"
        echo "| P0 — blockers | ${p0} |"
        echo "| P1 — high     | ${p1} |"
        echo "| P2 — medium   | ${p2} |"
        echo "| P3 — low      | ${p3} |"
        echo "| **Total**     | **${total}** |"
        echo ""
        exec_policy_block
        echo ""
        echo "---"
        echo ""
        cat "$body"
    } > "$final"
    rm -f "$body"
    echo "  ✓ CONSOLIDATED — P0:${p0} P1:${p1} P2:${p2} P3:${p3} (total ${total})" >&2
}

# --- Codebase mode: per-lens scope maps -------------------------------------
# Each line is "chunk-name|space-separated relative paths". Chunks are sized
# so a single scan stays under ~350KB of source (scan recall degrades on
# oversized payloads — see the warning in audit-scan.sh). Paths verified to
# exist as of 2026-07-02; when the tree moves, update here AND in the lens's
# own "Suggested per-domain scope groups" section.
#
# test-coverage covers the FULL tests/ tree — new tests/ subdirs must be added
# to a chunk here or they get zero coverage. Two chunks (feature-platforms,
# unit-suite) are single dirs that exceed the ceiling on their own; they trip
# the soft-size warning in audit-scan.sh by design (can't be split by directory
# — loose top-level files recurse — and partial coverage beats none).
# code-quality-slop's services-platforms chunk exceeds it for the same reason.
#
# BREADTH LENSES — code-quality-slop and semantic-correctness deliberately share
# one identical, whole-product-surface map. Most lenses are targeted (schema-rls
# only wants migrations + models); these two are not. Slop hunts dead code and
# SEM hunts plausible-but-wrong logic, and both can only find what they READ —
# a narrow map doesn't make them cheap, it makes them silently blind. This bit us
# on 2026-07-19: the waitlist subsystem sat unread through a whole-repo dead-code
# sweep because slop's map covered 1 of its 7 files. Widen both together, or the
# per-lens coverage guard below fails.
#
# GUARDED: tests/Feature/Architecture/AuditPipelineIntegrityTest.php fails CI if a
# path here is dead, if a new namespace under app/Services|app/Http/Controllers/Api|
# app/Jobs|tests/Feature isn't covered by ANY lens, if a breadth lens loses coverage
# of a product-surface root, or if lens prose references a dead file path.
codebase_chunks() {
    case "$1" in
        security) cat <<'EOF'
auth-core|app/Http/Middleware app/Policies app/Providers app/Http/Controllers/Concerns app/Http/Controllers/Controller.php app/Http/Controllers/Api/ApiController.php app/Exceptions app/Rules
config-models|config app/Models app/Database
user-surface|app/Http/Controllers/Api/User app/Http/Requests
public-staff-surface|app/Http/Controllers/Api/PublicSite app/Http/Controllers/Api/Staff app/Http/Controllers/Api/Internal app/Http/Controllers/Api/Webhooks app/Http/Controllers/Api/HealthController.php
platforms-surface|app/Http/Controllers/Api/Platforms app/Http/Resources
catalog-routing-surface|app/Http/Controllers/Api/Catalog app/Http/Controllers/Api/Routing app/Http/Controllers/Api/Site app/Http/Controllers/Api/Content
outbound-services|app/Services/BotProtection app/Services/Auth app/Services/Streaming app/Services/Media app/Services/Http app/Services/Profile cloudflare-worker/src
outbound-design|app/Services/Design
signup-claim|app/Services/PreAccount app/Services/User
outbound-platforms|app/Services/Platforms
outbound-routing|app/Routing
outbound-ingest|app/Ingest
content|app/Content app/Site
outbound-content|app/Content app/Site
catalog|app/Catalog
EOF
        ;;
        lifecycle-correctness) cat <<'EOF'
account-core|app/Services/User app/Services/Accounts app/Services/Segments app/Services/EarlyAccess app/Services/Profile app/Services/PreAccount app/Http/Middleware/Context app/Http/Controllers/Api/User/Notifications
site-cache|app/Services/Site app/Services/PublicSite app/Services/Cache app/Services/Cloudflare
media-jobs|app/Services/Media app/Jobs
moderation-policies|app/Services/Moderation app/Services/Streaming app/Services/Notifications app/Notifications app/Observers app/Policies
connectors|app/Services/Platforms app/Routing app/Ingest app/Content app/Site
schema-cron|supabase/migrations routes/console.php
EOF
        ;;
        scaling-antipatterns) cat <<'EOF'
write-paths|app/Services/Analytics app/Jobs/Analytics app/Services/Notifications app/Jobs/Notifications app/Notifications app/Observers app/Jobs/Cache app/Jobs/Cloudflare
read-surface|app/Services/Cache app/Http/Resources app/Http/Controllers/Api/Staff app/Http/Controllers/Api/User/Analytics
ingest-fanout|app/Ingest/Projection app/Ingest/Runtime app/Content
EOF
        ;;
        database-and-queue-scaling) cat <<'EOF'
models-config|app/Models app/Database app/Http/Resources database/factories routes/console.php config/horizon.php config/queue.php
jobs|app/Jobs
vendors|app/Services/Media app/Services/Streaming app/Services/Cloudflare app/Services/Analytics
platforms|app/Services/Platforms
console-controllers-user|app/Console app/Http/Controllers/Api/User
controllers-public-staff|app/Http/Controllers/Api/PublicSite app/Http/Controllers/Api/Staff app/Http/Controllers/Api/Internal app/Http/Controllers/Api/Webhooks
controllers-catalog-routing|app/Http/Controllers/Api/Catalog app/Http/Controllers/Api/Routing app/Http/Controllers/Api/Site app/Http/Controllers/Api/Content
ingest-projection|app/Ingest/Projection app/Ingest/Landing app/Content
routing-site|app/Routing app/Site
migrations|supabase/migrations
EOF
        ;;
        schema-rls) cat <<'EOF'
schema|supabase/migrations
models|app/Models app/Database
EOF
        ;;
        caching-gold-standard) cat <<'EOF'
read-services|app/Services/Cache app/Services/Site app/Services/PublicSite app/Services/Accounts app/Services/FeatureFlags app/Services/FeatureAvailability
read-user-mw|app/Services/User app/Http/Middleware
read-controllers-user|app/Http/Controllers/Api/User app/Http/Resources
read-controllers-public|app/Http/Controllers/Api/PublicSite app/Http/Controllers/Api/Staff app/Http/Controllers/Api/Internal app/Http/Controllers/Api/Webhooks
write-paths|app/Observers app/Listeners app/Jobs/Cache app/Jobs/Cloudflare app/Jobs/Analytics app/Jobs/Notifications app/Jobs/Concerns app/Jobs/Moderation/Concerns app/Services/Analytics app/Services/Notifications app/Services/Streaming
platforms-services|app/Services/Platforms app/Jobs/Platforms
platforms-controllers|app/Http/Controllers/Api/Platforms app/Http/Controllers/Api/HealthController.php
controllers-catalog-routing|app/Http/Controllers/Api/Catalog app/Http/Controllers/Api/Routing app/Http/Controllers/Api/Site app/Http/Controllers/Api/Content
routing-probes|app/Routing/Probes app/Services/Brand
design|app/Services/Design
EOF
        ;;
        caching-coverage-gaps) cat <<'EOF'
hot-reads-app|app/Services/Site app/Services/PublicSite app/Services/Accounts app/Services/Cache app/Services/Streaming app/Services/Cloudflare app/Services/Http
hot-reads-platforms|app/Services/Platforms
hot-reads-controllers-user|app/Http/Controllers/Api/User app/Http/Resources
hot-reads-controllers-public|app/Http/Controllers/Api/PublicSite app/Http/Controllers/Api/Staff app/Http/Controllers/Api/Internal app/Http/Middleware
hot-reads-controllers-catalog-routing|app/Http/Controllers/Api/Catalog app/Http/Controllers/Api/Routing app/Http/Controllers/Api/Site app/Http/Controllers/Api/Content
hot-reads-routing-probes|app/Routing/Probes app/Content app/Site
EOF
        ;;
        webhook-idempotency) cat <<'EOF'
callbacks|app/Http/Controllers/Api/Webhooks app/Http/Controllers/Api/Internal app/Services/Webhooks app/Http/Middleware routes
ingest-replay|app/Ingest/Landing app/Ingest/Runtime app/Ingest/Message
EOF
        ;;
        transaction-boundaries) cat <<'EOF'
domain-services|app/Services/User app/Services/PreAccount app/Services/Site app/Services/Moderation app/Services/Accounts app/Services/Auth app/Services/Feedback app/Services/EarlyAccess app/Services/Profile app/Observers app/Http/Controllers/Api/Internal app/Services/Content
vendor-jobs|app/Services/Cloudflare app/Services/Streaming app/Services/Http app/Jobs app/Listeners
platforms|app/Services/Platforms
controllers-user|app/Http/Controllers/Api/User
controllers-catalog-routing|app/Http/Controllers/Api/Catalog app/Http/Controllers/Api/Routing app/Http/Controllers/Api/Site app/Http/Controllers/Api/Content app/Routing
ingest-content-site|app/Ingest app/Content app/Site
controllers-staff-console|app/Http/Controllers/Api/Staff app/Console
media-design|app/Services/Media app/Services/Design app/Services/WebsiteScan
ingest|app/Ingest
EOF
        ;;
        migration-safety) cat <<'EOF'
migrations|supabase/migrations
EOF
        ;;
        test-prod-parity) cat <<'EOF'
migrations|supabase/migrations
models|app/Models app/Enums database/factories tests/Pest.php tests/TestCase.php tests/PostgresTestCase.php tests/SchemaTestCase.php
writers-jobs|app/Jobs app/Observers
writers-controllers|app/Http/Controllers/Api/User app/Http/Controllers/Api/Internal app/Http/Controllers/Api/Webhooks
writers-platforms-controllers|app/Http/Controllers/Api/Platforms
writers-catalog-routing-controllers|app/Http/Controllers/Api/Catalog app/Http/Controllers/Api/Routing app/Http/Controllers/Api/Site app/Http/Controllers/Api/Content
writers-ingest-landing|app/Ingest/Landing app/Ingest/Projection app/Content
writers-routing-site|app/Routing app/Site
services-platforms|app/Services/Platforms app/Services/Brand
services-design-media|app/Services/Design app/Services/Media app/Services/WebsiteScan
services-core|app/Services/User app/Services/Site app/Services/PublicSite app/Services/Content
services-data|app/Services/Analytics app/Services/Cache app/Services/Segments app/Services/Moderation app/Services/Audit app/Services/Redis
services-rest|app/Services/Accounts app/Services/Auth app/Services/EarlyAccess app/Services/Profile app/Services/Onboarding app/Services/PreAccount app/Services/Notifications app/Services/Http app/Services/Cloudflare app/Services/Streaming app/Services/FeatureFlags app/Services/FeatureAvailability app/Services/BotProtection app/Services/Feedback app/Services/Diagnostics app/Services/Webhooks
EOF
        ;;
        api-contract) cat <<'EOF'
user-api|app/Http/Resources app/Http/Controllers/Api/User app/Http/Controllers/Api/ApiController.php
public-staff-api|app/Http/Controllers/Api/PublicSite app/Http/Controllers/Api/Staff app/Http/Controllers/Api/Internal
platforms-api|app/Http/Controllers/Api/Platforms
catalog-routing-api|app/Http/Controllers/Api/Catalog app/Http/Controllers/Api/Routing app/Http/Controllers/Api/Site app/Http/Controllers/Api/Content
catalog-content-payloads|app/Catalog/Contracts app/Catalog/Enums app/Content app/Site
payload-services|app/Services/PublicSite app/Services/Site app/Services/Analytics
EOF
        ;;
        configuration-hygiene) cat <<'EOF'
config-files|config .env.example routes bootstrap/app.php bootstrap/providers.php .github/workflows deploy
consumers-catalog|app/Catalog/Contracts app/Catalog/Enums bootstrap/catalog
consumers-ingest-routing|app/Ingest app/Routing
consumers-jobs|app/Jobs
consumers-console-mw|app/Console app/Http/Middleware
services-platforms|app/Services/Platforms app/Services/Brand
services-design-media|app/Services/Design app/Services/Media app/Services/WebsiteScan
services-core|app/Services/User app/Services/Site app/Services/PublicSite app/Services/Content
services-data|app/Services/Analytics app/Services/Cache app/Services/Moderation app/Services/Segments app/Services/Audit app/Services/Redis
services-rest|app/Services/PreAccount app/Services/Notifications app/Services/Http app/Services/Auth app/Services/Cloudflare app/Services/Streaming app/Services/BotProtection app/Services/FeatureFlags app/Services/FeatureAvailability app/Services/Accounts app/Services/Feedback app/Services/Diagnostics app/Services/Webhooks app/Services/EarlyAccess app/Services/Profile app/Services/Onboarding
EOF
        ;;
        test-coverage) cat <<'EOF'
sweep-conventions|tests/Pest.php tests/TestCase.php tests/Support tests/Feature/ExampleTest.php tests/Feature/MediaUploadBreadcrumbTest.php tests/Feature/MediaUploadFailureHandlingTest.php tests/Feature/SoftDeletePurgeCoverageTest.php tests/Feature/TrustProxiesTest.php tests/Feature/Security app/Policies
prod-http|app/Http/Controllers/Api/PublicSite app/Http/Controllers/Api/Webhooks app/Http/Middleware/Auth app/Http/Resources
prod-requests|app/Http/Requests
prod-platforms-controllers|app/Http/Controllers/Api/Platforms
prod-platforms-services|app/Services/Platforms
prod-catalog-routing-controllers|app/Http/Controllers/Api/Catalog app/Http/Controllers/Api/Routing app/Http/Controllers/Api/Site app/Http/Controllers/Api/Content
prod-catalog|app/Catalog
prod-routing|app/Routing
prod-ingest|app/Ingest
content|app/Content app/Site
prod-content|app/Content app/Site
prod-jobs|app/Jobs database/factories
prod-schema|supabase/migrations
feature-user-api|tests/Feature/User tests/Feature/Api tests/Feature/Http tests/Feature/Contact
feature-site-staff|tests/Feature/Site tests/Feature/Staff
feature-notif-moderation|tests/Feature/Notifications tests/Feature/Moderation
feature-domain|tests/Feature/Cache tests/Feature/Resilience tests/Feature/PublicSite tests/Feature/Account tests/Feature/Analytics
feature-domain-b|tests/Feature/Console tests/Feature/FeatureFlags tests/Feature/Design tests/Feature/WebsiteScan
feature-media-jobs|tests/Feature/Media tests/Feature/Mail tests/Feature/Documents tests/Feature/Jobs tests/Feature/Services tests/Feature/Database tests/Feature/Auth tests/Feature/Bootstrap tests/Feature/Gallery tests/Feature/Content tests/Feature/Observers tests/Feature/Commands tests/Feature/Middleware tests/Postgres tests/Schema tests/Authz
feature-misc-tail|tests/Feature/Webhooks tests/Feature/Feedback tests/Feature/Validation tests/Feature/Subdomain tests/Feature/Architecture tests/Feature/Enquiry tests/Feature/Export tests/Feature/Core tests/Feature/SoftDelete tests/Feature/Boot tests/Feature/Newsletter tests/Feature/Internal tests/Feature/Customers tests/Feature/CustomerLeads tests/Feature/Accounts tests/Feature/PreAccount tests/Feature/EarlyAccess tests/Feature/Onboarding tests/Feature/Health tests/Feature/Queue tests/Feature/Cors tests/Feature/Policies tests/Feature/Resources tests/Helpers
feature-platforms|tests/Feature/Platforms
feature-catalog-routing|tests/Feature/Catalog tests/Feature/Routing tests/Feature/Brand tests/fixtures/Routing
feature-ingest|tests/Feature/Ingest tests/Unit/Ingest
feature-content|tests/Feature/Site tests/Unit/Content tests/Unit/Site
unit-suite|tests/Unit
EOF
        ;;
        data-integrity) cat <<'EOF'
schema|supabase/migrations
enums-factories|app/Enums database/factories
models-gdpr|app/Models app/Observers app/Jobs/Gdpr app/Services/User
content-identity|app/Content app/Services/Content
ingest-ledger|app/Ingest/Landing app/Ingest/Projection app/Ingest/Message
EOF
        ;;
        job-queue-correctness) cat <<'EOF'
jobs|app/Jobs config/horizon.php config/queue.php
console|app/Console
mail|app/Mail
ingest-runtime|app/Ingest/Runtime app/Ingest/Manifest app/Ingest/Support
EOF
        ;;
        observability) cat <<'EOF'
jobs|app/Jobs config/queue.php config/horizon.php
console-hooks|app/Console app/Listeners app/Exceptions app/Services/Webhooks app/Http/Controllers/Api/Webhooks app/Http/Controllers/Api/Internal app/Http/Controllers/Api/HealthController.php app/Http/Middleware/Logging
vendor-services|app/Services/Cloudflare app/Services/Streaming app/Services/Media app/Services/Moderation app/Services/Audit
vendor-platforms|app/Services/Platforms
ingest-runtime|app/Ingest/Runtime app/Ingest/Projection app/Ingest/Support
routing-probes|app/Routing app/Content app/Site
EOF
        ;;
        edge-worker) cat <<'EOF'
worker|cloudflare-worker/src cloudflare-worker/wrangler.toml app/Jobs/Cloudflare app/Services/Cloudflare app/Services/Cache/SiteCacheService.php app/Observers app/Services/Moderation config/partna.php
EOF
        ;;
        privacy-compliance) cat <<'EOF'
rights-machinery|app/Jobs/Gdpr app/Jobs/Account app/Services/User app/Models app/Http/Resources
collection-retention|app/Services/Analytics app/Jobs/Analytics app/Services/Moderation app/Services/Notifications app/Services/Audit app/Http/Middleware/Logging config/partna.php routes/console.php
console-mail|app/Console app/Mail
edge-processors|app/Jobs/Cloudflare app/Services/Cloudflare
ingest-third-party|app/Ingest/Connectors app/Ingest/Landing app/Content
schema-pii|supabase/migrations
EOF
        ;;
        code-quality-slop) cat <<'EOF'
services-platforms|app/Services/Platforms app/Services/Brand
services-design-media|app/Services/Design app/Services/Media app/Services/WebsiteScan
services-site|app/Services/User app/Services/Site app/Services/PublicSite app/Services/Content
services-data|app/Services/Analytics app/Services/Cache app/Services/Segments app/Services/Moderation app/Services/Audit app/Services/Redis
services-integrations|app/Services/Accounts app/Services/Auth app/Services/EarlyAccess app/Services/Profile app/Services/Onboarding app/Services/PreAccount app/Services/Notifications app/Services/Http app/Services/Cloudflare app/Services/Streaming app/Services/FeatureFlags app/Services/FeatureAvailability app/Services/BotProtection app/Services/Feedback app/Services/Diagnostics app/Services/Webhooks app/Mail
controllers-platforms|app/Http/Controllers/Api/Platforms
controllers-user|app/Http/Controllers/Api/User
controllers-public-staff|app/Http/Controllers/Api/PublicSite app/Http/Controllers/Api/Staff app/Http/Controllers/Api/Internal app/Http/Controllers/Api/Webhooks app/Http/Controllers/Api/ApiController.php app/Http/Controllers/Api/HealthController.php app/Http/Controllers/Concerns app/Http/Controllers/Controller.php
controllers-catalog-routing|app/Http/Controllers/Api/Catalog app/Http/Controllers/Api/Routing app/Http/Controllers/Api/Site app/Http/Controllers/Api/Content
requests|app/Http/Requests
resources-models|app/Http/Resources app/Models
jobs-observers|app/Jobs app/Observers app/Notifications app/Listeners
console-policies|app/Console app/Policies app/Rules app/Support app/Contracts app/DTOs app/helpers.php
wiring|routes config app/Providers bootstrap/app.php bootstrap/providers.php
catalog|app/Catalog
routing|app/Routing
ingest|app/Ingest
content|app/Content app/Site
EOF
        ;;
        semantic-correctness) cat <<'EOF'
services-platforms|app/Services/Platforms app/Services/Brand
services-design-media|app/Services/Design app/Services/Media app/Services/WebsiteScan
services-site|app/Services/User app/Services/Site app/Services/PublicSite app/Services/Content
services-data|app/Services/Analytics app/Services/Cache app/Services/Segments app/Services/Moderation app/Services/Audit app/Services/Redis
services-integrations|app/Services/Accounts app/Services/Auth app/Services/EarlyAccess app/Services/Profile app/Services/Onboarding app/Services/PreAccount app/Services/Notifications app/Services/Http app/Services/Cloudflare app/Services/Streaming app/Services/FeatureFlags app/Services/FeatureAvailability app/Services/BotProtection app/Services/Feedback app/Services/Diagnostics app/Services/Webhooks app/Mail
controllers-platforms|app/Http/Controllers/Api/Platforms
controllers-user|app/Http/Controllers/Api/User
controllers-public-staff|app/Http/Controllers/Api/PublicSite app/Http/Controllers/Api/Staff app/Http/Controllers/Api/Internal app/Http/Controllers/Api/Webhooks app/Http/Controllers/Api/ApiController.php app/Http/Controllers/Api/HealthController.php app/Http/Controllers/Concerns app/Http/Controllers/Controller.php
controllers-catalog-routing|app/Http/Controllers/Api/Catalog app/Http/Controllers/Api/Routing app/Http/Controllers/Api/Site app/Http/Controllers/Api/Content
requests|app/Http/Requests
resources-models|app/Http/Resources app/Models
jobs-observers|app/Jobs app/Observers app/Notifications app/Listeners
console-policies|app/Console app/Policies app/Rules app/Support app/Contracts app/DTOs app/helpers.php
wiring|routes config app/Providers bootstrap/app.php bootstrap/providers.php
catalog|app/Catalog
routing|app/Routing
ingest|app/Ingest
content|app/Content app/Site
EOF
        ;;
        foundational-durability) cat <<'EOF'
platforms-controllers|app/Http/Controllers/Api/Platforms
platforms-services|app/Services/Platforms
schema-migrations|supabase/migrations
models-config|app/Models config/partna.php
catalog|app/Catalog
ingest|app/Ingest
routing-content|app/Routing app/Content app/Site
integration-cross-cutting|app/Jobs/Platforms app/Services/Notifications app/Jobs/Notifications app/Services/Accounts app/Services/FeatureFlags
controllers-user|app/Http/Controllers/Api/User app/Http/Controllers/Api/Internal app/Http/Controllers/Api/Webhooks
controllers-catalog-routing|app/Http/Controllers/Api/Catalog app/Http/Controllers/Api/Routing app/Http/Controllers/Api/Site app/Http/Controllers/Api/Content
controllers-staff-public|app/Http/Controllers/Api/Staff app/Http/Controllers/Api/PublicSite app/Http/Controllers/Concerns app/Http/Controllers/Api/ApiController.php app/Http/Controllers/Api/HealthController.php app/Http/Controllers/Controller.php
services-core|app/Services/User app/Services/Site app/Services/PublicSite app/Services/Content
services-auth-cache|app/Services/Auth app/Services/Cache
services-vendor|app/Services/Media app/Services/Analytics app/Services/Moderation app/Services/Streaming app/Services/Cloudflare app/Services/BotProtection app/Services/Diagnostics app/Services/Feedback app/Services/Webhooks app/Services/Audit
requests-resources|app/Http/Requests app/Http/Resources
routing-middleware|routes app/Http/Middleware app/Policies app/Observers
console|app/Console
jobs-providers-rest|app/Jobs/Moderation app/Jobs/Cloudflare app/Jobs/Gdpr app/Jobs/Cache app/Jobs/Account app/Jobs/Streaming app/Jobs/Analytics app/Jobs/Concerns app/Providers app/Mail app/Notifications app/Listeners app/Exceptions app/DTOs app/Support app/Enums app/Rules app/Contracts app/helpers.php
EOF
        ;;
        *) return 1 ;;
    esac
}

# The single source of scan chunks for codebase mode. Without --changed-since it
# is a pass-through to codebase_chunks(). With it, each chunk keeps only the
# changed files that live under its paths, then re-packs greedily into
# CHUNK_MAX_BYTES sub-chunks (named <chunk>-1, <chunk>-2, ...). Chunks whose
# paths saw no change drop out, so a lens only scans the part of the diff it
# owns. Must be deterministic: phase 1 (scan) and phase 2 (draft assembly) both
# call it and rely on identical chunk names.
lens_chunks() {
    local lens="$1" lines
    lines="$(codebase_chunks "$lens" || true)"
    [[ -n "$lines" ]] || return 0
    [[ -n "$CHANGED_LIST" ]] || { printf '%s\n' "$lines"; return 0; }

    # awk, not bash: a bash implementation would fork `wc -c` once per
    # (lens x chunk x changed file) — ~16k subprocesses across a full sweep.
    printf '%s\n' "$lines" | awk -v maxb="$CHUNK_MAX_BYTES" -v listf="$CHANGED_LIST" '
    BEGIN {
        while ((getline line < listf) > 0) {
            split(line, a, "\t"); n++; cf[n] = a[1]; cs[n] = a[2] + 0
        }
    }
    /\|/ {
        bar = index($0, "|")
        cname = substr($0, 1, bar - 1)
        np = split(substr($0, bar + 1), paths, /[ \t]+/)
        split("", parts); out_n = 0; part = ""; bytes = 0
        for (i = 1; i <= n; i++) {
            keep = 0
            for (j = 1; j <= np; j++) {
                p = paths[j]; sub(/\/$/, "", p)
                if (cf[i] == p || index(cf[i], p "/") == 1) { keep = 1; break }
            }
            if (!keep) continue
            if (part != "" && bytes + cs[i] > maxb) { parts[++out_n] = part; part = ""; bytes = 0 }
            part = (part == "" ? cf[i] : part " " cf[i]); bytes += cs[i]
        }
        if (part != "") parts[++out_n] = part
        for (k = 1; k <= out_n; k++) print (out_n == 1 ? cname : cname "-" k) "|" parts[k]
    }'
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --scope)         SCOPE_ARGS+=("--scope" "$2"); shift 2 ;;
        --lens)          LENS_ARG=("--lens" "$2"); shift 2 ;;
        --lens-file)     LENS_ARG=("--lens-file" "$2"); shift 2 ;;
        --full)          FULL=true; shift ;;
        --bundle)        BUNDLE="$2"; shift 2 ;;
        --codebase)      CODEBASE=true; shift ;;
        --only-lenses)   ONLY_LENSES="$2"; shift 2 ;;
        --changed-since) CHANGED_SINCE="$2"; shift 2 ;;
        --run-date)      RUN_DATE_OVERRIDE="$2"; shift 2 ;;
        --scan-jobs)     SCAN_JOBS="$2"; shift 2 ;;
        --category)      CATEGORY="$2"; shift 2 ;;
        --name)          NAME="$2"; shift 2 ;;
        --keep-drafts)   KEEP_DRAFTS=true; shift ;;
        -h|--help)       usage; exit 0 ;;
        *) echo "Unknown arg: $1" >&2; usage >&2; exit 2 ;;
    esac
done

# --- Validate ---
# --full is sugar for --bundle core
$FULL && [[ -z "$BUNDLE" ]] && BUNDLE="core"
$FULL && [[ -n "$BUNDLE" && "$BUNDLE" != "core" ]] && { echo "--full and --bundle <other> are mutually exclusive" >&2; exit 2; }

if [[ -n "$BUNDLE" ]]; then
    [[ ${#LENS_ARG[@]} -eq 0 ]] || { echo "--bundle/--full and --lens/--lens-file are mutually exclusive" >&2; exit 2; }
    FULL=true  # downstream logic gates on $FULL for the multi-lens path
else
    $CODEBASE && { echo "--codebase requires --bundle <name> (or --full)" >&2; exit 2; }
    [[ ${#LENS_ARG[@]} -gt 0 ]] || { echo "--lens, --bundle, or --full is required" >&2; exit 2; }
fi
# Checked BEFORE the --scope rules: --changed-since without --codebase otherwise
# fell through to "--scope is required", which points at the wrong fix entirely.
[[ -z "$CHANGED_SINCE" ]] || $CODEBASE || { echo "--changed-since requires --codebase (it narrows the per-lens scope maps)" >&2; exit 2; }
if $CODEBASE; then
    [[ -n "$BUNDLE" ]]              || { echo "--codebase requires --bundle <name> (or --full)" >&2; exit 2; }
    [[ ${#SCOPE_ARGS[@]} -eq 0 ]]   || { echo "--codebase uses built-in per-lens scopes; --scope is not allowed" >&2; exit 2; }
else
    [[ ${#SCOPE_ARGS[@]} -gt 0 ]]   || { echo "--scope is required (one or more)" >&2; exit 2; }
fi
[[ -z "$ONLY_LENSES" || -n "$BUNDLE" ]] || { echo "--only-lenses requires --bundle/--full (it filters a bundle's lenses)" >&2; exit 2; }
[[ -z "$RUN_DATE_OVERRIDE" || "$RUN_DATE_OVERRIDE" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || { echo "--run-date must be YYYY-MM-DD (got: $RUN_DATE_OVERRIDE)" >&2; exit 2; }
[[ "$SCAN_JOBS" =~ ^[0-9]+$ && "$SCAN_JOBS" -ge 1 ]] || { echo "--scan-jobs must be a positive integer" >&2; exit 2; }
[[ -n "${DEEPSEEK_API_KEY:-}" ]]  || { echo "DEEPSEEK_API_KEY not found (set in scripts/audit/.env or export)" >&2; exit 2; }
command -v claude >/dev/null      || { echo "claude CLI not on PATH — install from claude.ai/code" >&2; exit 2; }

# Preflight the DeepSeek balance. A mid-run 402 is expensive and quiet: the
# 2026-07-10 sweep spent 12 scans, then lost the remaining 36 to Insufficient
# Balance and left a 5/20 sweep behind. Network trouble here is not fatal —
# only an explicit is_available:false aborts.
DS_BALANCE="$(curl -s --max-time 10 https://api.deepseek.com/user/balance \
    -H "Authorization: Bearer $DEEPSEEK_API_KEY" 2>/dev/null || true)"
if [[ "$DS_BALANCE" == *'"is_available":false'* ]]; then
    echo "DeepSeek balance exhausted — top up before running a sweep." >&2
    echo "  $DS_BALANCE" >&2
    exit 2
fi

# --- --changed-since: materialise the diff as "path<TAB>bytes" --------------
# Added+modified only (a deleted file has nothing to audit). Filtered to the
# file types audit-scan.sh globs — anything else would be read as zero bytes.
if [[ -n "$CHANGED_SINCE" ]]; then
    git rev-parse --verify --quiet "${CHANGED_SINCE}^{commit}" >/dev/null \
        || { echo "--changed-since: not a commit-ish: $CHANGED_SINCE" >&2; exit 2; }
    CHANGED_LIST="$(mktemp)"
    git diff --name-only --diff-filter=AM "${CHANGED_SINCE}..HEAD" \
        | grep -Ev '^(audits|docs)/' \
        | grep -E '\.(php|blade\.php|sql|js|mjs|ts|tsx|jsx|yml|yaml|sh)$' \
        | sort \
        | while IFS= read -r f; do
              [[ -f "$f" ]] && printf '%s\t%s\n' "$f" "$(wc -c < "$f" | tr -d ' ')"
          done > "$CHANGED_LIST" || true
    [[ -s "$CHANGED_LIST" ]] \
        || { echo "--changed-since $CHANGED_SINCE: no changed files match the scanner's file types" >&2; exit 2; }
    echo "→ --changed-since $CHANGED_SINCE: $(wc -l < "$CHANGED_LIST" | tr -d ' ') changed files in scope" >&2
fi

# --- Output folder: one folder per audit run -------------------------------
# Nested + sortable so it's easy to look back over time:
#   targeted single-topic   → audits/<category>/<date>-<name>/
#   bundle / codebase sweep → audits/sweeps/<date>-<name>/
# Each folder always gets a CONSOLIDATED.md (the canonical tracked file the
# `execute audit` flow reads). --category/--name override the derived defaults.
RUN_DATE="${RUN_DATE_OVERRIDE:-$(date +%F)}"
if $FULL || $CODEBASE; then
    RUN_NAME="${NAME:-$BUNDLE}"
    BASE_DIR="audits/sweeps/${RUN_DATE}-${RUN_NAME}"
else
    RUN_CAT="${CATEGORY:-misc}"
    if [[ -n "$NAME" ]]; then
        RUN_NAME="$NAME"
    elif [[ "${LENS_ARG[0]:-}" == "--lens" ]]; then
        RUN_NAME="$(slugify "${LENS_ARG[1]}")"
    else
        # --lens-file path → use the lens file's basename
        RUN_NAME="$(basename "${LENS_ARG[1]}" .md)"
    fi
    BASE_DIR="audits/${RUN_CAT}/${RUN_DATE}-${RUN_NAME}"
fi
mkdir -p "$BASE_DIR"
echo "Audit folder: $BASE_DIR" >&2

# --- Drafts location ---
if $KEEP_DRAFTS; then
    if [[ -n "$BASE_DIR" ]]; then
        DRAFTS_DIR="${BASE_DIR}/.drafts"
        mkdir -p "$DRAFTS_DIR"
    else
        DRAFTS_DIR="."
    fi
    DRAFTS="$DRAFTS_DIR/drafts-$(date +%s).md"
else
    TMP="$(mktemp -d)"
    trap 'rm -rf "$TMP"' EXIT
    DRAFTS_DIR="$TMP"
    DRAFTS="$TMP/drafts.md"
fi

# --- Adjudicator budget (higher for --full because of merged drafts + tool use) ---
ADJ_BUDGET="2.00"
$FULL && ADJ_BUDGET="5.00"

if $FULL; then
    # --- Resolve bundle name → (lens files, meta-lens prefix list) ---
    case "$BUNDLE" in
        core)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/security.md"
                "$SCRIPT_DIR/lenses/lifecycle-correctness.md"
                "$SCRIPT_DIR/lenses/scaling-antipatterns.md"
                "$SCRIPT_DIR/lenses/database-and-queue-scaling.md"
                "$SCRIPT_DIR/lenses/schema-rls.md"
                "$SCRIPT_DIR/lenses/caching-gold-standard.md"
                "$SCRIPT_DIR/lenses/webhook-idempotency.md"
                "$SCRIPT_DIR/lenses/transaction-boundaries.md"
            )
            META_PREFIXES="security/policy (SEC-*), lifecycle correctness (LIFE-*), scaling antipatterns (CACHE-*), database/queue scaling — N+1/throughput (SCALE-*), schema/RLS correctness (SCHEMA-*), caching gold-standard adherence (CCH-*), webhook idempotency & delivery (WHK-*), and transaction-boundary correctness (TXN-*)"
            ;;
        concurrency)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/caching-gold-standard.md"
                "$SCRIPT_DIR/lenses/webhook-idempotency.md"
                "$SCRIPT_DIR/lenses/transaction-boundaries.md"
            )
            META_PREFIXES="caching gold-standard adherence (CCH-*), webhook idempotency & delivery (WHK-*), and transaction-boundary correctness (TXN-*) — the correctness-under-concurrency trio (silent state drift on rollback, replay, or stampede)"
            ;;
        pre-merge)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/migration-safety.md"
                "$SCRIPT_DIR/lenses/api-contract.md"
                "$SCRIPT_DIR/lenses/configuration-hygiene.md"
                "$SCRIPT_DIR/lenses/test-coverage.md"
                "$SCRIPT_DIR/lenses/test-prod-parity.md"
            )
            META_PREFIXES="migration safety (MIG-*), API contract (API-*), configuration hygiene (CFG-*), test coverage gaps (TEST-*), and SQLite-vs-Postgres test/prod drift (PARITY-*) — pre-merge sweep for PRs touching schema, public API, or config"
            ;;
        code-quality)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/code-quality-slop.md"
                "$SCRIPT_DIR/lenses/semantic-correctness.md"
            )
            META_PREFIXES="AI slop & low-value code (SLOP-*) and semantic correctness — type-valid-but-wrong behaviour (SEM-*). Complementary lenses: SLOP is a taste/maintainability pass graded against CLAUDE.md house style; SEM hunts plausible-but-wrong logic that compiles AND passes Larastan. Larastan already enforces symbol existence (undefined methods/properties/classes/config), so do NOT re-report missing-symbol findings here. When a single line is both slop and a semantic bug, keep the SEM finding (higher signal) and drop the SLOP duplicate. Apply both lenses' anti-hallucination directive: confirm every finding against the actual repo via Read/Grep before keeping it — never on a prior about how a library 'should' behave"
            ;;
        pre-pilot)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/security.md"
                "$SCRIPT_DIR/lenses/lifecycle-correctness.md"
                "$SCRIPT_DIR/lenses/scaling-antipatterns.md"
                "$SCRIPT_DIR/lenses/database-and-queue-scaling.md"
                "$SCRIPT_DIR/lenses/schema-rls.md"
                "$SCRIPT_DIR/lenses/caching-gold-standard.md"
                "$SCRIPT_DIR/lenses/webhook-idempotency.md"
                "$SCRIPT_DIR/lenses/transaction-boundaries.md"
                "$SCRIPT_DIR/lenses/data-integrity.md"
                "$SCRIPT_DIR/lenses/job-queue-correctness.md"
                "$SCRIPT_DIR/lenses/observability.md"
                "$SCRIPT_DIR/lenses/caching-coverage-gaps.md"
            )
            META_PREFIXES="security/policy (SEC-*), lifecycle correctness (LIFE-*), scaling antipatterns (CACHE-*), database/queue scaling (SCALE-*), schema/RLS (SCHEMA-*), caching gold-standard (CCH-*), inbound callbacks & idempotency (WHK-*), transaction boundaries (TXN-*), data integrity (DINT-*), job/queue correctness under failure (JOB-*), observability/silent failures (OBS-*), and caching coverage gaps (CCG-*) — the full pre-pilot correctness sweep"
            ADJ_BUDGET="8.00"
            ;;
        security)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/security.md"
                "$SCRIPT_DIR/lenses/schema-rls.md"
                "$SCRIPT_DIR/lenses/configuration-hygiene.md"
                "$SCRIPT_DIR/lenses/edge-worker.md"
                "$SCRIPT_DIR/lenses/privacy-compliance.md"
            )
            META_PREFIXES="security/auth/tenant-isolation/IDOR/injection/SSRF/PII/MFA (SEC-*), schema & RLS / search_path DB-layer tenant isolation (SCHEMA-*), configuration hygiene & secret leakage / CORS (CFG-*), edge worker origin-trust & KV poisoning (EDGE-*), and privacy & data-rights compliance / PII inventory (PRIV-*) — the full security & tenant-isolation sweep across the whole backend"
            ADJ_BUDGET="5.00"
            ;;
        launch-readiness)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/security.md"
                "$SCRIPT_DIR/lenses/privacy-compliance.md"
                "$SCRIPT_DIR/lenses/edge-worker.md"
                "$SCRIPT_DIR/lenses/configuration-hygiene.md"
                "$SCRIPT_DIR/lenses/migration-safety.md"
                "$SCRIPT_DIR/lenses/api-contract.md"
            )
            META_PREFIXES="security (SEC-*), privacy & data-rights compliance (PRIV-*), edge worker routing/cache (EDGE-*), configuration hygiene (CFG-*), migration operational safety (MIG-*), and API contract (API-*) — the go-live gate: what must be true before public launch"
            ADJ_BUDGET="5.00"
            ;;
        scale-health)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/caching-gold-standard.md"
                "$SCRIPT_DIR/lenses/caching-coverage-gaps.md"
                "$SCRIPT_DIR/lenses/scaling-antipatterns.md"
                "$SCRIPT_DIR/lenses/database-and-queue-scaling.md"
                "$SCRIPT_DIR/lenses/job-queue-correctness.md"
                "$SCRIPT_DIR/lenses/observability.md"
            )
            META_PREFIXES="caching gold-standard adherence (CCH-*), caching coverage gaps (CCG-*), scaling antipatterns (CACHE-*), database/queue scaling (SCALE-*), job/queue correctness (JOB-*), and observability (OBS-*). GRADE EVERY FINDING AGAINST A 10,000-USER FLEET: ~10k KV-routed sitepages, aggregate 100k–1M page views/day with single-page viral spikes, analytics ingest bursts of 50–100 events/sec, per-user cache and notification fan-out across a 10k keyspace, and Horizon queue depth under burst. A finding harmless at 100 users but breaking at 10k is in scope — re-tier it for 10k and say so in the Technical section"
            ADJ_BUDGET="5.00"
            ;;
        full-sweep)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/security.md"
                "$SCRIPT_DIR/lenses/lifecycle-correctness.md"
                "$SCRIPT_DIR/lenses/scaling-antipatterns.md"
                "$SCRIPT_DIR/lenses/database-and-queue-scaling.md"
                "$SCRIPT_DIR/lenses/schema-rls.md"
                "$SCRIPT_DIR/lenses/caching-gold-standard.md"
                "$SCRIPT_DIR/lenses/webhook-idempotency.md"
                "$SCRIPT_DIR/lenses/transaction-boundaries.md"
                "$SCRIPT_DIR/lenses/data-integrity.md"
                "$SCRIPT_DIR/lenses/job-queue-correctness.md"
                "$SCRIPT_DIR/lenses/observability.md"
                "$SCRIPT_DIR/lenses/caching-coverage-gaps.md"
                "$SCRIPT_DIR/lenses/privacy-compliance.md"
                "$SCRIPT_DIR/lenses/edge-worker.md"
                "$SCRIPT_DIR/lenses/configuration-hygiene.md"
                "$SCRIPT_DIR/lenses/migration-safety.md"
                "$SCRIPT_DIR/lenses/api-contract.md"
                "$SCRIPT_DIR/lenses/test-coverage.md"
                "$SCRIPT_DIR/lenses/test-prod-parity.md"
                "$SCRIPT_DIR/lenses/code-quality-slop.md"
                "$SCRIPT_DIR/lenses/semantic-correctness.md"
            )
            META_PREFIXES="every audit theme the pipeline knows: SEC, LIFE, CACHE, SCALE, SCHEMA, CCH, WHK, TXN, DINT, JOB, OBS, CCG, PRIV, EDGE, CFG, MIG, API, TEST, SLOP, SEM, and PARITY — the exhaustive nothing-left-unturned sweep"
            ADJ_BUDGET="12.00"
            ;;
        foundational)
            # Single custom lens, run through the chunked codebase path so its
            # large app+migrations+config scope stays under the scan-recall
            # ceiling. Scope map lives in codebase_chunks() under
            # 'foundational-durability'. Use with --codebase.
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/foundational-durability.md"
            )
            META_PREFIXES="foundational durability & extensibility — shotgun surgery, denormalization debt (JSON that should be columns/tables), leaky abstraction boundaries, and breaking-migration risk (FOUND-*), biased toward the new platform-integration / menu-scraping subsystem"
            ;;
        cross-repo)
            # Cross-repo campaign: backend↔frontend contract drift + frontend/cross-repo
            # dead code. Targeted mode ONLY (--scope the relevant backend paths + the
            # $PARTNA_FRONTEND_PATH frontend paths + the CONTRACT-INVENTORY.md pre-pass);
            # NOT --codebase (these lenses have no codebase_chunks() arm by design — their
            # scope is another repo). Pre-pass: scripts/audit/contract-inventory.sh.
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/frontend-backend-contract.md"
                "$SCRIPT_DIR/lenses/cross-repo-dead-code.md"
            )
            META_PREFIXES="frontend↔backend contract drift (XREPO-*) and cross-repo/frontend dead code (XDEAD-*). XREPO hunts routes/capabilities/flags wired on only one side of the boundary — most critically a frontend call to a backend route that no longer exists (a live 404, P1). XDEAD hunts frontend-side and cross-repo-provable dead code (backend intra-repo dead code stays with code-quality-slop). BOTH lenses require the adjudicator to grep BOTH repos before confirming ANY absence claim, and an UNRESOLVED entry from CONTRACT-INVENTORY.md may never become a finding without a source read in both repos — that asymmetry is the campaign's false-positive defense"
            ;;
        *)
            echo "Unknown bundle: $BUNDLE (expected: core, concurrency, pre-merge, code-quality, pre-pilot, security, launch-readiness, scale-health, full-sweep, foundational, cross-repo)" >&2
            exit 2
            ;;
    esac

    # The bundle's full lens list, before --only-lenses narrows it. CONSOLIDATED.md
    # is assembled in this order from whatever lens files exist in the folder, so a
    # resumed run re-emits the lenses it skipped rather than dropping them.
    ALL_BUNDLE_LENS_FILES=("${LENS_FILES[@]}")

    # --only-lenses <csv>: keep only the named lens basenames from the resolved
    # bundle. Purpose is resuming a bundle after a partial adjudication failure
    # (e.g. the Claude session limit hit mid-run) — re-scan + re-adjudicate ONLY
    # the lenses that failed, instead of re-running (and re-exhausting) the whole
    # bundle. Names must match lens file basenames (e.g. "edge-worker,api-contract").
    if [[ -n "$ONLY_LENSES" ]]; then
        IFS=',' read -ra _ONLY <<< "$ONLY_LENSES"
        _FILTERED=()
        for _o in "${_ONLY[@]}"; do
            _o="${_o// /}"  # tolerate spaces after commas
            _match=""
            for lf in "${LENS_FILES[@]}"; do
                [[ "$(basename "$lf" .md)" == "$_o" ]] && { _match="$lf"; break; }
            done
            [[ -n "$_match" ]] || { echo "--only-lenses: '$_o' is not a lens in bundle '$BUNDLE'" >&2; exit 2; }
            _FILTERED+=("$_match")
        done
        LENS_FILES=("${_FILTERED[@]}")
        echo "→ --only-lenses: restricted bundle '$BUNDLE' to ${#LENS_FILES[@]} lens(es): ${ONLY_LENSES}" >&2
    fi

    LENS_COUNT=${#LENS_FILES[@]}

    # Verify all lens files exist BEFORE starting expensive scans
    for lf in "${LENS_FILES[@]}"; do
        [[ -f "$lf" ]] || { echo "Lens file missing: $lf" >&2; exit 2; }
    done

    # ════════ Codebase mode: per-lens scopes, per-lens adjudication ════════
    # Each lens scans its own scope map (chunked), then gets its own
    # adjudication with --no-source (the adjudicator verifies via Read/Grep),
    # emitting one audit file per lens. A failed lens doesn't abort the run.
    if $CODEBASE; then
        # RUN_DATE is already resolved (honouring --run-date) alongside BASE_DIR;
        # re-deriving it here would name lens files with today's date inside a
        # folder pinned to another, breaking resume.
        FAILED_LENSES=()
        WRITTEN=()

        # Build the global scan task list up front: "lensfile|lens|chunk|paths".
        # Scans are independent stateless DeepSeek calls, so they parallelize
        # freely; adjudications stay sequential (they share the local claude
        # CLI's plan/rate budget and are the careful verification tier).
        TASKS=()
        for lf in "${LENS_FILES[@]}"; do
            LENS_NAME=$(basename "$lf" .md)
            CHUNK_LINES="$(lens_chunks "$LENS_NAME" || true)"
            if [[ -z "$CHUNK_LINES" ]]; then
                echo "WARNING: no codebase scope map for lens '$LENS_NAME' — skipping" >&2
                FAILED_LENSES+=("$LENS_NAME (no scope map)")
                continue
            fi
            while IFS='|' read -r CHUNK_NAME CHUNK_PATHS; do
                [[ -n "$CHUNK_NAME" ]] || continue
                TASKS+=("$lf|$LENS_NAME|$CHUNK_NAME|$CHUNK_PATHS")
            done <<< "$CHUNK_LINES"
        done
        TASK_COUNT=${#TASKS[@]}

        echo "" >&2
        echo "════════ Codebase audit: bundle '$BUNDLE' — $LENS_COUNT lenses, $TASK_COUNT chunk scans (${SCAN_JOBS}-wide) + $LENS_COUNT sequential adjudications ════════" >&2
        echo "Output: $BASE_DIR/audit-${RUN_DATE}-<lens>.md (one file per lens)" >&2

        # --- Phase 1: wave-parallel DeepSeek scans -------------------------
        # Waves of $SCAN_JOBS background scans; per-scan output goes to a log
        # file so interleaved stderr stays readable. Portable to bash 3.2
        # (no `wait -n`): launch a wave, then harvest each pid's status.
        ti=0
        SCANNED=0
        while [[ $ti -lt $TASK_COUNT ]]; do
            WAVE_PIDS=()
            WAVE_META=()
            wj=0
            while [[ $wj -lt $SCAN_JOBS && $ti -lt $TASK_COUNT ]]; do
                IFS='|' read -r lf LENS_NAME CHUNK_NAME CHUNK_PATHS <<< "${TASKS[$ti]}"
                read -ra PATHS <<< "$CHUNK_PATHS"
                SCOPES=()
                for p in "${PATHS[@]}"; do SCOPES+=(--scope "$p"); done
                CHUNK_OUT="$DRAFTS_DIR/drafts-${LENS_NAME}-${CHUNK_NAME}.md"
                SCAN_LOG="$DRAFTS_DIR/scan-${LENS_NAME}-${CHUNK_NAME}.log"
                "$SCRIPT_DIR/audit-scan.sh" --lens-file "$lf" "${SCOPES[@]}" --out "$CHUNK_OUT" >"$SCAN_LOG" 2>&1 &
                WAVE_PIDS+=($!)
                WAVE_META+=("${LENS_NAME}|${CHUNK_NAME}")
                ti=$((ti + 1)); wj=$((wj + 1))
            done
            wk=0
            for pid in "${WAVE_PIDS[@]}"; do
                IFS='|' read -r m_lens m_chunk <<< "${WAVE_META[$wk]}"
                if wait "$pid"; then
                    SCANNED=$((SCANNED + 1))
                    echo "  ✓ scanned [$SCANNED/$TASK_COUNT] ${m_lens} [${m_chunk}]" >&2
                else
                    echo "  ✗ scan FAILED: ${m_lens} [${m_chunk}] — tail of log:" >&2
                    tail -5 "$DRAFTS_DIR/scan-${m_lens}-${m_chunk}.log" 2>/dev/null | sed 's/^/      /' >&2
                    rm -f "$DRAFTS_DIR/drafts-${m_lens}-${m_chunk}.md"
                fi
                wk=$((wk + 1))
            done
        done

        # --- Phase 2: per-lens draft assembly + sequential adjudication ----
        LENS_NUM=0
        for lf in "${LENS_FILES[@]}"; do
            LENS_NUM=$((LENS_NUM + 1))
            LENS_NAME=$(basename "$lf" .md)
            CHUNK_LINES="$(lens_chunks "$LENS_NAME" || true)"
            [[ -n "$CHUNK_LINES" ]] || continue  # no scope map — already recorded

            LENS_DRAFTS="$DRAFTS_DIR/drafts-${LENS_NAME}.md"
            : > "$LENS_DRAFTS"
            ALL_PATHS=()
            while IFS='|' read -r CHUNK_NAME CHUNK_PATHS; do
                [[ -n "$CHUNK_NAME" ]] || continue
                read -ra PATHS <<< "$CHUNK_PATHS"
                ALL_PATHS+=("${PATHS[@]}")
                CHUNK_OUT="$DRAFTS_DIR/drafts-${LENS_NAME}-${CHUNK_NAME}.md"
                [[ -f "$CHUNK_OUT" ]] || continue  # chunk scan failed — skip it
                {
                    echo ""
                    echo "<!-- ═══ LENS: $LENS_NAME | CHUNK: $CHUNK_NAME ═══ -->"
                    echo ""
                    cat "$CHUNK_OUT"
                } >> "$LENS_DRAFTS"
            done <<< "$CHUNK_LINES"

            if [[ ! -s "$LENS_DRAFTS" ]]; then
                FAILED_LENSES+=("$LENS_NAME (all chunk scans failed)")
                continue
            fi

            echo "" >&2
            echo "──── Adjudicate ${LENS_NUM}/${LENS_COUNT}: $LENS_NAME ────" >&2
            ADJ_SCOPES=()
            for p in "${ALL_PATHS[@]}"; do ADJ_SCOPES+=(--scope "$p"); done
            OUT_FILE="$BASE_DIR/audit-${RUN_DATE}-${LENS_NAME}.md"

            # Adjudication budget scales with how many chunk-drafts feed THIS lens's
            # single adjudication. A whole-backend single-lens sweep (e.g. `foundational`)
            # merges ~12 chunks into one pass; a flat $3 ceiling starved it ("Exceeded
            # USD budget"). Multi-lens bundles reach here with 1-2 chunks/lens and stay
            # cheap — the ceiling only lifts for the lenses that need it. Floor $4, cap $18.
            CHUNKS_IN_DRAFT=$(grep -cF '<!-- ═══ LENS:' "$LENS_DRAFTS" 2>/dev/null || echo 1)
            [[ "$CHUNKS_IN_DRAFT" -ge 1 ]] || CHUNKS_IN_DRAFT=1
            ADJ_MAX=$(awk -v n="$CHUNKS_IN_DRAFT" 'BEGIN{ b=3.0+1.25*n; if(b<4)b=4; if(b>18)b=18; printf "%.2f", b }')

            if "$SCRIPT_DIR/audit-adjudicate.sh" \
                --drafts "$LENS_DRAFTS" \
                --lens-file "$lf" \
                "${ADJ_SCOPES[@]}" \
                --no-source \
                --max-budget "$ADJ_MAX" \
                --out "$OUT_FILE" \
                && grep -qE '^#{1,3} ' "$OUT_FILE" 2>/dev/null; then
                WRITTEN+=("$OUT_FILE")
            else
                # Move the partial/error blob aside. It is excluded from
                # CONSOLIDATED.md either way, but left in place it sits in the run
                # folder looking like a real per-lens audit that found nothing.
                [[ -f "$OUT_FILE" ]] && mv "$OUT_FILE" "$OUT_FILE.failed"
                echo "WARNING: adjudication failed for $LENS_NAME (partial output at ${OUT_FILE}.failed)" >&2
                FAILED_LENSES+=("$LENS_NAME (adjudication failed)")
            fi
        done

        # Build CONSOLIDATED.md: header + every per-lens file concatenated
        # (each lens already carries its own bundling section). Cross-lens
        # dedup happens at fix time. Mechanical = deterministic, never fails.
        #
        # Assembled from the lens files PRESENT IN THE FOLDER, in bundle order —
        # not from the lenses this invocation happened to run. A resumed sweep
        # (--only-lenses after a mid-run failure) would otherwise rebuild the file
        # from the resumed lenses alone and silently drop the earlier ones, while
        # their audit-*.md sat on disk unreferenced. If a lens was re-run on a
        # later date, the newest dated file wins.
        # Glob, not `ls | sort | tail` — under `set -o pipefail` an unmatched ls
        # fails the command substitution and `set -e` kills the run before this
        # file is written. Glob expansion is already lexicographic, which for
        # audit-YYYY-MM-DD-<lens>.md is date order, so the last hit is the newest.
        MERGED_LENS_FILES=()
        for lf in "${ALL_BUNDLE_LENS_FILES[@]}"; do
            ln="$(basename "$lf" .md)"
            cand=""
            for f in "$BASE_DIR"/audit-*-"${ln}".md; do
                if [[ -e "$f" ]]; then cand="$f"; fi
            done
            if [[ -n "$cand" ]]; then MERGED_LENS_FILES+=("$cand"); fi
        done

        CONSOLIDATED="$BASE_DIR/CONSOLIDATED.md"
        MERGED="$(mktemp)"
        for f in ${MERGED_LENS_FILES[@]+"${MERGED_LENS_FILES[@]}"}; do
            {
                echo ""
                echo "<!-- ═══════════ $(basename "$f") ═══════════ -->"
                echo ""
                cat "$f"
                echo ""
            } >> "$MERGED"
        done
        cp "$MERGED" "$CONSOLIDATED"; rm -f "$MERGED"

        # Header must describe what the file actually contains, not what the bundle
        # intended: a partial sweep that claims its bundle's lens count reads as
        # complete to anyone (or any runbook) that trusts the header.
        MERGED_COUNT=${#MERGED_LENS_FILES[@]}
        BUNDLE_TOTAL=${#ALL_BUNDLE_LENS_FILES[@]}
        LENS_TALLY="${MERGED_COUNT}/${BUNDLE_TOTAL} lenses"
        [[ "$MERGED_COUNT" -eq "$BUNDLE_TOTAL" ]] || LENS_TALLY="${LENS_TALLY} (INCOMPLETE — $((BUNDLE_TOTAL - MERGED_COUNT)) never produced findings)"
        if [[ -n "$CHANGED_SINCE" ]]; then
            SWEEP_TITLE="${BUNDLE} changed-scope sweep"
            SWEEP_LENS="bundle '${BUNDLE}' — ${LENS_TALLY} over the $(wc -l < "$CHANGED_LIST" | tr -d ' ') files changed since \`${CHANGED_SINCE}\` ($(git rev-parse --short "$CHANGED_SINCE")..$(git rev-parse --short HEAD))"
        else
            SWEEP_TITLE="${BUNDLE} codebase sweep"
            SWEEP_LENS="bundle '${BUNDLE}' — whole-repo sweep across ${LENS_TALLY}"
        fi
        write_consolidated "$CONSOLIDATED" \
            "$SWEEP_TITLE" \
            "$SWEEP_LENS" \
            "$(printf '%s\n' "${MERGED_LENS_FILES[@]##*/}")"

        echo "" >&2
        echo "════════ Codebase audit complete ════════" >&2
        for f in ${WRITTEN[@]+"${WRITTEN[@]}"}; do echo "  ✓ $f" >&2; done
        echo "  ✓ $CONSOLIDATED — ${LENS_TALLY}" >&2
        if [[ ${#FAILED_LENSES[@]} -gt 0 ]]; then
            echo "  Incomplete lenses (re-run individually with --bundle/--lens):" >&2
            for fl in "${FAILED_LENSES[@]}"; do echo "    ✗ $fl" >&2; done
        fi
        $KEEP_DRAFTS && echo "Per-lens drafts kept in: $DRAFTS_DIR" >&2
        # Non-zero when any lens produced nothing. A partial sweep exiting 0 reads as
        # a clean full-sweep to callers and CI — the 2026-07-10 run lost 15 of 20
        # lenses to a DeepSeek 402 and still exited 0.
        [[ ${#FAILED_LENSES[@]} -eq 0 ]] || exit 1
        exit 0
    fi

    echo "" >&2
    echo "════════ Bundle '$BUNDLE' — $LENS_COUNT lens-focused scans + 1 adjudication ════════" >&2

    : > "$DRAFTS"  # truncate / create

    # Wave-parallel scans (independent DeepSeek calls, $SCAN_JOBS at a time);
    # per-scan output goes to a log file so interleaved stderr stays readable.
    # Drafts are concatenated AFTER all scans, in bundle order, so the merged
    # file is deterministic. Bundle mode needs every lens — any failure aborts.
    li=0
    SCANNED=0
    while [[ $li -lt $LENS_COUNT ]]; do
        WAVE_PIDS=()
        WAVE_NAMES=()
        wj=0
        while [[ $wj -lt $SCAN_JOBS && $li -lt $LENS_COUNT ]]; do
            lf="${LENS_FILES[$li]}"
            LENS_NAME=$(basename "$lf" .md)
            echo "──── Scan $((li + 1))/${LENS_COUNT}: $LENS_NAME ────" >&2
            "$SCRIPT_DIR/audit-scan.sh" --lens-file "$lf" "${SCOPE_ARGS[@]}" \
                --out "$DRAFTS_DIR/drafts-${LENS_NAME}.md" \
                >"$DRAFTS_DIR/scan-${LENS_NAME}.log" 2>&1 &
            WAVE_PIDS+=($!)
            WAVE_NAMES+=("$LENS_NAME")
            li=$((li + 1)); wj=$((wj + 1))
        done
        wk=0
        ANY_FAIL=false
        for pid in "${WAVE_PIDS[@]}"; do
            if wait "$pid"; then
                SCANNED=$((SCANNED + 1))
                echo "  ✓ scanned [$SCANNED/$LENS_COUNT] ${WAVE_NAMES[$wk]}" >&2
            else
                echo "  ✗ scan FAILED: ${WAVE_NAMES[$wk]} — tail of log:" >&2
                tail -5 "$DRAFTS_DIR/scan-${WAVE_NAMES[$wk]}.log" 2>/dev/null | sed 's/^/      /' >&2
                ANY_FAIL=true
            fi
            wk=$((wk + 1))
        done
        $ANY_FAIL && { echo "Aborting: bundle mode requires every lens scan to succeed." >&2; exit 1; }
    done

    for lf in "${LENS_FILES[@]}"; do
        LENS_NAME=$(basename "$lf" .md)
        {
            echo ""
            echo "<!-- ═══ LENS: $LENS_NAME ═══ -->"
            echo ""
            cat "$DRAFTS_DIR/drafts-${LENS_NAME}.md"
        } >> "$DRAFTS"
    done

    # Meta-lens describing the bundle for the adjudicator
    META_LENS="Bundle '$BUNDLE' audit across $LENS_COUNT focused themes: ${META_PREFIXES}. Drafts below are concatenated from $LENS_COUNT lens-focused scans, each prefixed with a <!-- LENS: name --> marker. Dedupe across lenses where the same finding appears under multiple prefixes."
    LENS_PASS_ARGS=(--lens "$META_LENS")
    OUT="${BASE_DIR}/CONSOLIDATED.md"
    HEADER_TITLE="${BUNDLE} bundle audit"
    HEADER_LENS="bundle '${BUNDLE}' — ${META_PREFIXES}"
else
    # --- Targeted mode — single scan ---
    echo "" >&2
    echo "════════ Step 1/2: DeepSeek scan ════════" >&2
    "$SCRIPT_DIR/audit-scan.sh" "${LENS_ARG[@]}" "${SCOPE_ARGS[@]}" --out "$DRAFTS"
    LENS_PASS_ARGS=("${LENS_ARG[@]}")
    OUT="${BASE_DIR}/CONSOLIDATED.md"
    HEADER_TITLE="${RUN_NAME} audit"
    if [[ "${LENS_ARG[0]}" == "--lens" ]]; then
        HEADER_LENS="${LENS_ARG[1]}"
    else
        HEADER_LENS="lens-file: $(basename "${LENS_ARG[1]}" .md)"
    fi
fi

# Newline-separated scope paths for the header (strip the --scope tokens).
SCOPE_LIST=""
for tok in "${SCOPE_ARGS[@]}"; do
    [[ "$tok" == "--scope" ]] || SCOPE_LIST+="${tok}"$'\n'
done

# --- Adjudicate ---
echo "" >&2
if $FULL; then
    echo "════════ Final step: Claude adjudication across all ${LENS_COUNT} lenses ════════" >&2
else
    echo "════════ Step 2/2: Claude adjudication ════════" >&2
fi

# The adjudicator writes its full audit doc straight to the folder's
# CONSOLIDATED.md; write_consolidated then prepends the deterministic
# scope/counts/exec-policy header in place.
# Scale the targeted-run budget by how much source the adjudicator must verify.
# A flat $2 ceiling starved a 669KB scope ("Error: Exceeded USD budget (2)") and,
# worse, wrote that message into CONSOLIDATED.md. Mirrors the per-chunk scaling
# the codebase path already does. Floor = whatever the bundle set, cap $18.
SCOPE_BYTES=0
for _p in "${SCOPE_ARGS[@]}"; do
    [[ "$_p" == "--scope" ]] && continue
    [[ -e "$_p" ]] || continue
    _b=$(find "$_p" -type f \( -name '*.php' -o -name '*.blade.php' -o -name '*.sql' \
        -o -name '*.js' -o -name '*.mjs' -o -name '*.ts' -o -name '*.tsx' -o -name '*.jsx' \
        -o -name '*.yml' -o -name '*.yaml' -o -name '*.sh' \) \
        ! -path "*/node_modules/*" ! -path "*/.next/*" ! -path "*/build/*" ! -path "*/out/*" ! -path "*/.turbo/*" \
        -exec cat {} + 2>/dev/null | wc -c)
    SCOPE_BYTES=$((SCOPE_BYTES + _b))
done
ADJ_BUDGET=$(awk -v cur="$ADJ_BUDGET" -v kb="$((SCOPE_BYTES / 1024))" \
    'BEGIN{ b=2.0+2.0*(kb/100); if(b<cur)b=cur; if(b>18)b=18; printf "%.2f", b }')
echo "→ Adjudication budget: \$${ADJ_BUDGET} (scope $((SCOPE_BYTES / 1024))KB)" >&2

# A failed adjudication must NOT leave a CONSOLIDATED.md behind. The claude CLI
# writes its stdout to --out whatever happens, so a session-limit or budget error
# ends up sitting in the folder looking exactly like a finished audit ("You've hit
# your session limit", "Error: Exceeded USD budget (2)"). Anyone — or
# archive-done.sh — reading that folder later sees an audit that says nothing and
# concludes the code is clean. Fail loudly and leave no result instead.
adjudication_failed() {
    local reason="$1"
    [[ -f "$OUT" ]] && mv "$OUT" "$OUT.failed"
    echo "" >&2
    echo "ERROR: adjudication failed — $reason" >&2
    echo "       NO audit was written. Any partial output is at ${OUT}.failed" >&2
    echo "       Drafts are preserved; re-run with --keep-drafts to reuse them." >&2
    exit 1
}

if ! "$SCRIPT_DIR/audit-adjudicate.sh" \
    --drafts "$DRAFTS" \
    --max-budget "$ADJ_BUDGET" \
    "${LENS_PASS_ARGS[@]}" \
    "${SCOPE_ARGS[@]}" \
    --out "$OUT"; then
    adjudication_failed "audit-adjudicate.sh exited non-zero"
fi

# Exit 0 is not proof of an audit: a truncated or refused run can still write a
# short non-audit blob. A real audit always carries a markdown heading.
if ! grep -qE '^#{1,3} ' "$OUT" 2>/dev/null; then
    adjudication_failed "adjudicator produced no audit content"
fi

write_consolidated "$OUT" "$HEADER_TITLE" "$HEADER_LENS" "$SCOPE_LIST"

if $KEEP_DRAFTS; then
    echo "" >&2
    if $FULL; then
        echo "Per-lens drafts kept in: $DRAFTS_DIR" >&2
        echo "Merged drafts: $DRAFTS" >&2
    else
        echo "Drafts kept at: $DRAFTS" >&2
    fi
fi
