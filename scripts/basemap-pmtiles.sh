#!/usr/bin/env bash
#
# Extract a Netherlands basemap from the Protomaps daily planet build into
# public/basemap-nl.pmtiles using HTTP range requests (no full planet download).
#
# Requires go-pmtiles:  brew install pmtiles
#
# Usage:
#   scripts/basemap-pmtiles.sh                 # use default build date
#   BUILD_DATE=20260518 scripts/basemap-pmtiles.sh
#   MAXZOOM=14 scripts/basemap-pmtiles.sh      # smaller file
#
set -euo pipefail

# Bounding box with generous margins around the Netherlands (incl. coast/border).
# west,south,east,north
BBOX="${BBOX:-2.8,50.4,7.8,54.0}"

# Max zoom. Each level roughly doubles file size. 15 = full detail.
MAXZOOM="${MAXZOOM:-15}"

# Protomaps daily planet build base URL.
BUILD_BASE="${BUILD_BASE:-https://build.protomaps.com}"

# How many days back to start looking. Today's build is usually not ready yet,
# and very recent builds can be flaky, so start with a small margin.
START_OFFSET="${START_OFFSET:-2}"

# Auto-detect the most recent available build (older builds are pruned after
# ~a week). Probe backwards from START_OFFSET days ago and pick the first that
# answers an HTTP range request. Override by setting BUILD_DATE explicitly.
find_build_date() {
  local offset="${START_OFFSET}"
  local date_cmd
  while [ "${offset}" -le 14 ]; do
    if date -v-1d >/dev/null 2>&1; then
      date_cmd="$(date -v-"${offset}"d +%Y%m%d)"   # BSD/macOS
    else
      date_cmd="$(date -d "${offset} days ago" +%Y%m%d)"   # GNU/Linux
    fi
    if curl -fsS -o /dev/null -r 0-0 "${BUILD_BASE}/${date_cmd}.pmtiles"; then
      echo "${date_cmd}"
      return 0
    fi
    offset=$((offset + 1))
  done
  return 1
}

if [ -z "${BUILD_DATE:-}" ]; then
  echo "Detecting most recent available build..." >&2
  BUILD_DATE="$(find_build_date)" || {
    echo "error: no available build found in the last 14 days" >&2
    exit 1
  }
fi
BUILD_URL="${BUILD_URL:-${BUILD_BASE}/${BUILD_DATE}.pmtiles}"

# Resolve repo root from this script's location, output into public/.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
OUTPUT="${ROOT_DIR}/public/basemap-nl.pmtiles"

if ! command -v pmtiles >/dev/null 2>&1; then
  echo "error: 'pmtiles' not found. Install with: brew install pmtiles" >&2
  exit 1
fi

echo "Extracting Netherlands basemap"
echo "  source : ${BUILD_URL}"
echo "  bbox   : ${BBOX}"
echo "  maxzoom: ${MAXZOOM}"
echo "  output : ${OUTPUT}"

pmtiles extract "${BUILD_URL}" "${OUTPUT}" \
  --bbox="${BBOX}" \
  --maxzoom="${MAXZOOM}"

echo "Done -> ${OUTPUT}"
