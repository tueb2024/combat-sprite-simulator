#!/usr/bin/env bash
# All 3D creation and rendering happens inside Blender on the Windows GTX PC.
set -euo pipefail
if [[ "${1:-}" != "--aircraft" || "${2:-}" != "all" ]]; then
  echo 'Usage: tools/blender/run_on_gtx.sh --aircraft all' >&2
  exit 2
fi
cd "$(dirname "$0")/../.."
host="${CSS_GTX_HOST:-tchou@192.168.1.163}"
remote='C:/Users/tchou/combat-sprite-simulator-ww2'
ssh -o BatchMode=yes "$host" 'if not exist C:\Users\tchou\combat-sprite-simulator-ww2\specs mkdir C:\Users\tchou\combat-sprite-simulator-ww2\specs'
ssh -o BatchMode=yes "$host" 'if not exist C:\Users\tchou\combat-sprite-simulator-ww2\output mkdir C:\Users\tchou\combat-sprite-simulator-ww2\output'
scp -O tools/blender/airframe_geometry.py tools/blender/build_ww2.py \
  tools/blender/assemble_ww2.py tools/blender/assemble_atlas.py \
  tools/blender/assemble_parametric_atlas.py tools/blender/run-ww2.cmd "$host:$remote/"
for spec in assets/aircraft/specs/*.json; do
  scp -O "$spec" "$host:$remote/specs/"
done
ssh -o BatchMode=yes "$host" 'C:\Users\tchou\combat-sprite-simulator-ww2\run-ww2.cmd'
mkdir -p assets/source/ww2 games/combat-sprite-simulator/assets/sprites artifacts/ww2
for spec in assets/aircraft/specs/*.json; do
  id="$(basename "$spec" .json)"
  scp -O "$host:$remote/output/$id.blend" "assets/source/ww2/$id.blend"
  scp -O "$host:$remote/output/$id-atlas.png" "games/combat-sprite-simulator/assets/sprites/$id-atlas.png"
done
scp -O "$host:$remote/output/ww2-atlas.json" games/combat-sprite-simulator/assets/sprites/ww2-atlas.json
scp -O "$host:$remote/output/ww2-comparison.png" artifacts/ww2/ww2-comparison.png
scp -O "$host:$remote/output/ww2-render-log.json" artifacts/ww2/ww2-render-log.json
scp -O "$host:$remote/output/render.log" artifacts/ww2/render.log
scp -O "$host:$remote/output/atlas.log" artifacts/ww2/atlas.log
python3 - <<'PY'
from pathlib import Path
for path in [Path('games/combat-sprite-simulator/assets/sprites/ww2-atlas.json'),
             Path('artifacts/ww2/ww2-render-log.json')]:
    path.write_text(path.read_text(encoding='utf-8'), encoding='utf-8', newline='\n')
PY
echo 'WW2 Blender outputs retrieved from GTX.'
