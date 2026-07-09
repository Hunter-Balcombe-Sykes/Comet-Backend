#!/usr/bin/env bash
# Install a static ffmpeg + ffprobe build for Laravel Cloud.
#
# Wired in via Laravel Cloud → Settings → Build Commands. Runs every deploy
# in the builder container; the resulting files persist at /var/www/html/bin/ffmpeg/
# in the runtime container.
#
# Path at runtime:
#   /var/www/html/bin/ffmpeg/ffmpeg
#   /var/www/html/bin/ffmpeg/ffprobe
#
# Resilient download: tries johnvansickle.com first, then falls back to the
# BtbN GitHub static builds — each with curl retries — so a single-host outage
# (johnvansickle periodically goes down / times out at TLS) can't block a
# deploy. Binaries are located by name after extraction, tolerating either
# tarball's layout (johnvansickle: <dir>/ffmpeg ; BtbN: <dir>/bin/ffmpeg).
#
# Idempotent: skips download if the binary is already present (e.g. when
# Cloud reuses a cached builder layer).
set -euo pipefail

# Install relative to the script's location so the binary lands inside the
# repo root (e.g. <repo>/bin/ffmpeg) and gets bundled into the runtime image
# at /var/www/html/bin/ffmpeg/. $HOME in the builder is /var/www (outside the
# repo) so anything written there is discarded.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALL_DIR="${REPO_ROOT}/bin/ffmpeg"
FFMPEG_BIN="${INSTALL_DIR}/ffmpeg"
ARCH="$(uname -m)"

# Per-arch download sources, tried in order. Primary = johnvansickle static
# release; fallback = BtbN 'gpl' (fully static) latest build hosted on GitHub.
case "${ARCH}" in
    aarch64|arm64)
        SOURCES=(
            "https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-arm64-static.tar.xz"
            "https://github.com/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-linuxarm64-gpl.tar.xz"
        )
        ;;
    x86_64|amd64)
        SOURCES=(
            "https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz"
            "https://github.com/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-linux64-gpl.tar.xz"
        )
        ;;
    *) echo "[ffmpeg.sh] Unsupported arch: ${ARCH}" >&2; exit 1 ;;
esac

if [[ -x "${FFMPEG_BIN}" ]]; then
    echo "[ffmpeg.sh] ffmpeg already installed at ${FFMPEG_BIN} — skipping download"
    "${FFMPEG_BIN}" -version | head -1
    exit 0
fi

echo "[ffmpeg.sh] Installing static ffmpeg build for ${ARCH} into ${INSTALL_DIR}"
mkdir -p "${INSTALL_DIR}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

# Try each source in turn; --retry handles transient timeouts (incl. exit 28),
# the loop handles a fully-down host by moving to the next mirror.
TARBALL="${TMP_DIR}/ffmpeg.tar.xz"
downloaded=false
for url in "${SOURCES[@]}"; do
    echo "[ffmpeg.sh] Fetching ${url}"
    if curl --fail --location --silent --show-error \
        --retry 2 --retry-delay 3 --connect-timeout 20 --max-time 300 \
        --output "${TARBALL}" "${url}" && [[ -s "${TARBALL}" ]]; then
        downloaded=true
        break
    fi
    echo "[ffmpeg.sh] Source failed, trying next mirror: ${url}" >&2
done

if ! ${downloaded}; then
    echo "[ffmpeg.sh] All download sources failed for ${ARCH}" >&2
    exit 1
fi

tar -xf "${TARBALL}" -C "${TMP_DIR}"

# Locate the binaries by name — tolerates either tarball layout (johnvansickle
# puts them at <dir>/ffmpeg, BtbN under <dir>/bin/ffmpeg).
SRC_FFMPEG="$(find "${TMP_DIR}" -type f -name ffmpeg | head -1)"
SRC_FFPROBE="$(find "${TMP_DIR}" -type f -name ffprobe | head -1)"
if [[ -z "${SRC_FFMPEG}" || -z "${SRC_FFPROBE}" ]]; then
    echo "[ffmpeg.sh] Could not locate ffmpeg/ffprobe in the extracted tarball" >&2
    exit 1
fi

cp "${SRC_FFMPEG}"  "${INSTALL_DIR}/ffmpeg"
cp "${SRC_FFPROBE}" "${INSTALL_DIR}/ffprobe"
chmod +x "${INSTALL_DIR}/ffmpeg" "${INSTALL_DIR}/ffprobe"

echo "[ffmpeg.sh] Installed:"
"${INSTALL_DIR}/ffmpeg"  -version | head -1
"${INSTALL_DIR}/ffprobe" -version | head -1
