#!/usr/bin/env bash
# Run from any Aiphonse terminal; OCR only four possible contents pages.
set -euo pipefail
cd /srv/projects/combat-sprite-simulator
export CSS_UID="$(id -u)" CSS_GID="$(id -g)"
docker compose -f compose.references.yaml run --rm --entrypoint=python reference-index /work/ocr_page.py munson-airliners-interwar 3 4 5 6 --query Junkers
