#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
host="${CSS_GTX_HOST:-tchou@192.168.1.163}"
remote='C:/Users/tchou/combat-sprite-simulator-review-fleet'
out='artifacts/source-review'
ssh -o BatchMode=yes "$host" 'if not exist C:\Users\tchou\combat-sprite-simulator-review-fleet\specs mkdir C:\Users\tchou\combat-sprite-simulator-review-fleet\specs'
ssh -o BatchMode=yes "$host" 'if not exist C:\Users\tchou\combat-sprite-simulator-review-fleet\output mkdir C:\Users\tchou\combat-sprite-simulator-review-fleet\output'
scp -O tools/blender/render_source_airframes.py tools/blender/run-review-fleet.cmd "$host:$remote/"
for id in bf109-e4 ju87-b2 junkers-g38; do
  scp -O "reviews/aircraft/$id/outline.json" "$host:$remote/specs/$id.json"
done
ssh -o BatchMode=yes "$host" 'C:\Users\tchou\combat-sprite-simulator-review-fleet\run-review-fleet.cmd'
mkdir -p "$out"
for id in bf109-e4 ju87-b2 junkers-g38; do
  scp -O "$host:$remote/output/$id-review.png" "reviews/aircraft/$id/prototype.png"
  scp -O "$host:$remote/output/$id.blend" "reviews/aircraft/$id/prototype.blend"
done
for id in bf109-e4 ju87-b2; do
  for bank in 0 1 2; do
    for prop in 0 1 2; do
      scp -O "$host:$remote/output/$id-b$bank-p$prop.png" "$out/$id-b$bank-p$prop.png"
    done
  done
done
scp -O "$host:$remote/output/junkers-g38-boss.png" "$out/junkers-g38-boss.png"
scp -O "$host:$remote/output/junkers-g38-damage.png" "$out/junkers-g38-damage.png"
scp -O "$host:$remote/output/render-info.json" "$out/render-info.json"
scp -O "$host:$remote/output/render.log" "$out/render.log"
echo 'Fleet Blender outputs retrieved from GTX.'
