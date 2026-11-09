#!/usr/bin/env bash
# Run in an ordinary Aiphonse terminal. Codex's sandbox cannot open docker.sock.
set -euo pipefail
cd /srv/projects/combat-sprite-simulator
books="$HOME/.local/share/combat-sprite-simulator/reference-books"
index="$HOME/.local/share/combat-sprite-simulator/reference-index"
test -f "$books/manifest.json"
test "$(find "$books" -maxdepth 1 -type f -name '*.pdf' | wc -l)" -eq 8
mkdir -p "$index"
chmod 700 "$index"
export CSS_UID="$(id -u)" CSS_GID="$(id -g)"
docker compose -f compose.references.yaml build reference-index
docker compose -f compose.references.yaml run --rm reference-index
docker compose -f compose.references.yaml run --rm --entrypoint python reference-index /work/search_index.py Hurricane --limit 3
python3 - <<'PY'
import json, pathlib, sys
p = pathlib.Path.home() / '.local/share/combat-sprite-simulator/reference-index/catalog.local.json'
catalog = json.loads(p.read_text(encoding='utf-8'))
for row in catalog:
    print(row['id'], row['pages'], row['index_status'], 'hash OK' if row['sha256_matches_lenovo'] else 'HASH MISMATCH')
if len(catalog) != 8 or not all(row['sha256_matches_lenovo'] for row in catalog):
    sys.exit('Expected eight verified volumes')
PY
