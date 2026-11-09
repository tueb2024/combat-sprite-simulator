#!/usr/bin/env python3
"""Check the game-ready WWII atlases against their documented specifications."""

import json
from pathlib import Path

from validate_assets import png_alpha


ROOT = Path(__file__).resolve().parents[2]
SPRITES = ROOT / 'games/combat-sprite-simulator/assets/sprites'
SPECS = ROOT / 'assets/aircraft/specs'


def main():
    manifest = json.loads((SPRITES / 'ww2-atlas.json').read_text(encoding='utf-8'))
    expected = {'hurricane-mk1', 'bf109-e4', 'ju87-b2'}
    assert set(manifest) == expected, 'Unexpected WWII atlas set'
    for name, atlas in manifest.items():
        spec = json.loads((SPECS / f'{name}.json').read_text(encoding='utf-8'))
        assert spec['designation'] and spec['sources'] and spec['source_confidence']
        assert spec['dimensions_m']['span'] > spec['dimensions_m']['length'] > 0
        assert len(atlas['frames']) == 9
        size, alpha = png_alpha(SPRITES / atlas['image'])
        assert size == (1536, 1536) == (atlas['width'], atlas['height'])
        assert atlas['source_spec'] == f'{name}.json'
        for bank in range(3):
            for prop in range(3):
                frame = atlas['frames'][bank * 3 + prop]
                assert frame['name'] == f'{name}-b{bank}-p{prop}'
                assert (frame['x'], frame['y'], frame['width'], frame['height']) == (prop * 512, bank * 512, 512, 512)
                assert frame['pivot'] == [256, 256]
                area = sum(sum(value > 8 for value in row[prop * 512:(prop + 1) * 512])
                           for row in alpha[bank * 512:(bank + 1) * 512])
                assert 5000 < area < 130000, f'{name}/{frame["name"]}: alpha coverage {area}'
                assert abs(area - frame['alpha_pixels']) < 2500, f'{name}/{frame["name"]}: manifest disagrees with image'
        print(f'{name}: 9 valid RGBA frames, 1536x1536')


if __name__ == '__main__':
    main()
