#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
host="${CSS_GTX_HOST:-tchou@192.168.1.163}"
remote='C:/Users/tchou/combat-sprite-simulator-review'
review='reviews/aircraft/hurricane-mk1'
ssh -o BatchMode=yes "$host" 'if not exist C:\Users\tchou\combat-sprite-simulator-review\output mkdir C:\Users\tchou\combat-sprite-simulator-review\output'
scp -O tools/blender/review_hurricane.py tools/blender/run-review-hurricane.cmd \
  "$review/outline.json" "$host:$remote/"
ssh -o BatchMode=yes "$host" 'C:\Users\tchou\combat-sprite-simulator-review\run-review-hurricane.cmd'
scp -O "$host:$remote/output/hurricane-prototype.png" "$review/hurricane-prototype.png"
scp -O "$host:$remote/output/hurricane-prototype.blend" "$review/hurricane-prototype.blend"
scp -O "$host:$remote/output/render-info.json" "$review/render-info.json"
scp -O "$host:$remote/output/render.log" "$review/render.log"
echo 'Hurricane review Blender outputs retrieved from GTX.'
