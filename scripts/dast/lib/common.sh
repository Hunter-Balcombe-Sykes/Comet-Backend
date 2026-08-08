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

# audits/dast/<date>-<lane>/ — creates it if missing, echoes the path.
#
# The lane suffix is load-bearing, not cosmetic. This used to be <date> alone,
# which meant BOTH lanes on the same day shared one directory — and since
# audits/dast/.gitignore whitelists exactly one file per directory
# (*/REPORT.md), the second lane's report silently replaced the first's. You
# could never keep both lanes' results for a single day.
#
# It was also the shared mutable location behind a containment bug (2026-08-07):
# a second run on the same date inherited the previous run's bring-up.env, and
# zap-active.sh's readiness poll — which tests only for that file's existence —
# passed instantly against a stack that was still booting. See the `rm -f` at
# the top of zap-active.sh, which remains the direct guard for that.
#
# Both consumers of this path glob on the directory name rather than matching
# <date>, so neither needed changing: audits/dast/.gitignore's `!*/` +
# `!*/REPORT.md`, and dast-edge.yml's `audits/dast/*/REPORT.md` artifact path.
dast_outdir() {
    local lane="${1:-run}"
    local dir="$DAST_ROOT/audits/dast/$(date +%F)-${lane}"
    mkdir -p "$dir"
    echo "$dir"
}

# Canonicalize a possibly-relative OUTDIR to an absolute path (creating it if
# needed) — `docker run -v` bind mounts reject relative host paths outright
# (misread as a named-volume request), so every lane script that mounts
# OUTDIR into a container must call this before its first `docker run`.
# dast_outdir() already returns absolute; this guards direct/manual
# invocation with a relative path too.
dast_abspath() {
    local dir="$1"
    mkdir -p "$dir"
    (cd "$dir" && pwd)
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
#
# Upstream's own Dockerfile is also broken as of tag 2.0.0 (confirmed
# 2026-07-26): it builds with `golang:latest` (glibc 2.34+) but runs on
# `debian:buster` (glibc 2.28) — the binary fails at container start with
# "version `GLIBC_2.34' not found". lib/wcvs.Dockerfile fixes this with a
# static build (CGO_ENABLED=0); everything else matches upstream.
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
    docker build -t "$WCVS_IMAGE" -f "$DAST_DIR/lib/wcvs.Dockerfile" "$src" || die "failed to build $WCVS_IMAGE"
    rm -rf "$src"
}
