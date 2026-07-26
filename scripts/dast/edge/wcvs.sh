#!/usr/bin/env bash
# Edge lane: wcvs cache-deception scan. Implemented in Phase 6.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DAST_DIR="$HERE"
# shellcheck source=../lib/common.sh
source "$HERE/lib/common.sh"
die "edge/wcvs.sh not yet implemented (Phase 6)"
