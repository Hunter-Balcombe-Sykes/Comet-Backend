#!/usr/bin/env bash
# Embedded-rework 6-phase audit runner — sequential execution.
# Wrote 2026-05-15; safe to delete after the audits complete.
set -uo pipefail

cd "$(git rev-parse --show-toplevel)"

OUT_DIR="audits/embedded-rework-2026-05-15"
LOG_DIR="$OUT_DIR/.logs"
mkdir -p "$LOG_DIR"

# Source-code scope (used by all 6 phases)
SRC_SCOPE=(
    --scope app/Http/Controllers/Api/Internal/EmbeddedConnectController.php
    --scope app/Http/Controllers/Api/Internal/EmbeddedSetupController.php
    --scope app/Http/Controllers/Api/Internal/EmbeddedOrderAnalyticsController.php
    --scope app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php
    --scope app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php
    --scope app/Http/Middleware/Auth/VerifyShopifySessionToken.php
    --scope app/Http/Requests/Api/Internal/Embedded/
    --scope app/Services/Shopify/ShopifyShopResolver.php
    --scope app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php
    --scope bootstrap/app.php
)

# Phase 5 (tests) also includes the embedded test files
TEST_SCOPE=(
    --scope tests/Feature/Embedded/
    --scope tests/Feature/Security/VerifyShopifySessionTokenTest.php
    --scope tests/Feature/Shopify/EmbeddedConnectControllerTest.php
    --scope tests/Feature/Validation/EmbeddedSetupRequestValidationTest.php
)

# Phase → lens-file, output-suffix
declare -a PHASES=(
    "security|scripts/audit/lenses/security.md"
    "lifecycle|scripts/audit/lenses/lifecycle-correctness.md"
    "scaling|scripts/audit/lenses/scaling-antipatterns.md"
    "database|scripts/audit/lenses/database-and-queue-scaling.md"
    "tests|scripts/audit/lenses/test-coverage.md"
    "data|scripts/audit/lenses/data-integrity-and-privacy.md"
)

OVERALL_START=$(date +%s)
FAILED=()

for entry in "${PHASES[@]}"; do
    PHASE="${entry%%|*}"
    LENS_FILE="${entry##*|}"
    OUT_FILE="$OUT_DIR/audit-2026-05-15-${PHASE}.md"
    LOG_FILE="$LOG_DIR/${PHASE}.log"

    # Per-phase scope: phase-5 (tests) also gets the test files.
    SCOPE=("${SRC_SCOPE[@]}")
    if [[ "$PHASE" == "tests" ]]; then
        SCOPE+=("${TEST_SCOPE[@]}")
    fi

    echo "════════════════════════════════════════════════════════════════"
    echo "  Phase: $PHASE"
    echo "  Lens:  $LENS_FILE"
    echo "  Out:   $OUT_FILE"
    echo "  Log:   $LOG_FILE"
    echo "  Time:  $(date '+%F %T')"
    echo "════════════════════════════════════════════════════════════════"

    PHASE_START=$(date +%s)
    if scripts/audit/audit.sh \
        --lens-file "$LENS_FILE" \
        "${SCOPE[@]}" \
        --out "$OUT_FILE" \
        > "$LOG_FILE" 2>&1
    then
        PHASE_DUR=$(( $(date +%s) - PHASE_START ))
        echo "✓ $PHASE done in ${PHASE_DUR}s"
    else
        PHASE_DUR=$(( $(date +%s) - PHASE_START ))
        echo "✗ $PHASE FAILED after ${PHASE_DUR}s — see $LOG_FILE"
        FAILED+=("$PHASE")
    fi
    echo ""
done

TOTAL=$(( $(date +%s) - OVERALL_START ))
echo "════════════════════════════════════════════════════════════════"
echo "  Total wall time: ${TOTAL}s ($((TOTAL / 60))m $((TOTAL % 60))s)"
if (( ${#FAILED[@]} > 0 )); then
    echo "  Failed phases:   ${FAILED[*]}"
    exit 1
fi
echo "  All 6 phases complete."
echo "════════════════════════════════════════════════════════════════"
