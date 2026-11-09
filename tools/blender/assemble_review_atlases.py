#!/usr/bin/env python3
"""Pack GTX Blender RGBA frames into the existing JumPHP atlas contracts.

This script only resizes and packs rendered images. No 3D work runs here.
"""
import json
from pathlib import Path

from PIL import Image


ROOT = Path(__file__).resolve().parents[2]
RAW = ROOT / 'artifacts/source-review'
SPRITES = ROOT / 'games/combat-sprite-simulator/assets/sprites'
MANIFEST = json.loads((SPRITES / 'ww2-atlas.json').read_text())


for name in ('hurricane-mk1', 'bf109-e4', 'ju87-b2'):
    atlas = Image.new('RGBA', (1536, 1536))
    for bank in range(3):
        for prop in range(3):
            frame_name = f'{name}-b{bank}-p{prop}'
            frame = Image.open(RAW / f'{frame_name}.png').convert('RGBA')
            if frame.size != (512, 512):
                raise ValueError(f'{frame_name}: expected 512 square, got {frame.size}')
            atlas.paste(frame, (prop * 512, bank * 512))
            pixels = frame.getchannel('A').get_flattened_data()
            MANIFEST[name]['frames'][bank * 3 + prop]['alpha_pixels'] = sum(p > 8 for p in pixels)
    atlas.save(SPRITES / f'{name}-atlas.png', optimize=True)
    print(f'{name}: 9 GTX frames packed')

boss = Image.new('RGBA', (1024, 256))
for index, state in enumerate(('boss', 'damage')):
    source = Image.open(RAW / f'junkers-g38-{state}.png').convert('RGBA')
    # The existing boss uses 512 x 256 tiles, twice as wide as they are tall.
    boss.paste(source.resize((512, 256), Image.Resampling.LANCZOS), (index * 512, 0))
boss.save(SPRITES / 'junkers-atlas.png', optimize=True)
(SPRITES / 'ww2-atlas.json').write_text(json.dumps(MANIFEST, indent=2) + '\n')
print('junkers-g38: base and damage GTX frames packed')
