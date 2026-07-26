# Shared helpers for scripts/dast/. Sourced, not executed.
# DAST_DIR must be set by the caller before sourcing (path to scripts/dast).

DAST_ROOT="$(cd "$DAST_DIR/../.." && pwd)"

# Load scripts/dast/.env if present. Never fails on a missing key — lanes
# that need a specific key check for it themselves and die() with a clear
# message, so a bare `source .env` failure doesn't mask which key was needed.
if [[ -f "$DAST_DIR/.env" ]]; then
    set -a
    # shellcheck disable=SC1091
    source "$DAST_DIR/.env"
    set +a
fi

log() {
    echo "[dast] $*" >&2
}

die() {
    echo "[dast] ERROR: $*" >&2
    exit 1
}

# audits/dast/<date>/ — creates it if missing, echoes the path.
dast_outdir() {
    local dir="$DAST_ROOT/audits/dast/$(date +%F)"
    mkdir -p "$dir"
    echo "$dir"
}

# require_docker_image <image> — pulls if not already present locally.
require_docker_image() {
    local image="$1"
    if ! docker image inspect "$image" >/dev/null 2>&1; then
        log "pulling $image (not found locally)"
        docker pull "$image" || die "failed to pull $image"
    fi
}

# wcvs has no published Docker Hub/GHCR image (confirmed 2026-07-26: `docker
# pull hackmanit/web-cache-vulnerability-scanner` and the ghcr.io equivalent
# both fail — upstream only ships prebuilt Go binaries + a Dockerfile). Build
# it locally from their repo, pinned to release tag 2.0.0, tagged under the
# same image name so every `docker run hackmanit/web-cache-vulnerability-scanner`
# call elsewhere in this tool needs no change.
WCVS_IMAGE="hackmanit/web-cache-vulnerability-scanner:2.0.0"
WCVS_PINNED_TAG="2.0.0"

require_wcvs_image() {
    if docker image inspect "$WCVS_IMAGE" >/dev/null 2>&1; then
        return 0
    fi
    log "building $WCVS_IMAGE from upstream tag $WCVS_PINNED_TAG (no prebuilt image exists)"
    local src
    src="$(mktemp -d)/wcvs-src"
    git clone --depth 1 --branch "$WCVS_PINNED_TAG" \
        https://github.com/Hackmanit/Web-Cache-Vulnerability-Scanner.git "$src" \
        || die "failed to clone wcvs source at tag $WCVS_PINNED_TAG"
    docker build -t "$WCVS_IMAGE" "$src" || die "failed to build $WCVS_IMAGE"
    rm -rf "$src"
}
