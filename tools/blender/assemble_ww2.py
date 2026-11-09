"""Assemble WWII RGBA atlases and a gameplay-size comparison on GTX Blender."""
import argparse
import json
from pathlib import Path
import sys

import bpy

sys.path.insert(0, str(Path(__file__).resolve().parent))
from assemble_parametric_atlas import atlas


GLYPHS = {
    'A': ('01110','10001','10001','11111','10001','10001','10001'),
    'B': ('11110','10001','10001','11110','10001','10001','11110'),
    'C': ('01111','10000','10000','10000','10000','10000','01111'),
    'E': ('11111','10000','10000','11110','10000','10000','11111'),
    'F': ('11111','10000','10000','11110','10000','10000','10000'),
    'H': ('10001','10001','10001','11111','10001','10001','10001'),
    'I': ('11111','00100','00100','00100','00100','00100','11111'),
    'J': ('00111','00010','00010','00010','10010','10010','01100'),
    'K': ('10001','10010','10100','11000','10100','10010','10001'),
    'N': ('10001','11001','10101','10011','10001','10001','10001'),
    'R': ('11110','10001','10001','11110','10100','10010','10001'),
    'S': ('01111','10000','10000','01110','00001','00001','11110'),
    'T': ('11111','00100','00100','00100','00100','00100','00100'),
    'U': ('10001','10001','10001','10001','10001','10001','01110'),
    '0': ('01110','10011','10101','10101','11001','10001','01110'),
    '1': ('00100','01100','00100','00100','00100','00100','01110'),
    '4': ('10010','10010','10010','11111','00010','00010','00010'),
    '7': ('11111','00001','00010','00100','01000','01000','01000'),
    '8': ('01110','10001','10001','01110','10001','10001','01110'),
    '9': ('01110','10001','10001','01111','00001','00010','11100'),
    ' ': ('00000',) * 7,
}


def args():
    parser = argparse.ArgumentParser()
    parser.add_argument('--out', required=True)
    parser.add_argument('--spec-dir', required=True)
    return parser.parse_args(sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else [])


def comparison(out, specs):
    width, height = 960, 270
    background = (0.085, 0.12, 0.12, 1.0)
    pixels = list(background) * (width * height)

    def put(x, y, rgba):
        if 0 <= x < width and 0 <= y < height:
            start = (y * width + x) * 4
            a = rgba[3]
            for channel in range(3):
                pixels[start + channel] = rgba[channel] * a + pixels[start + channel] * (1 - a)
            pixels[start + 3] = 1.0

    def label(x, y, text):
        for letter in text.upper():
            glyph = GLYPHS[letter]
            for row, pattern in enumerate(glyph):
                for col, value in enumerate(pattern):
                    if value == '1':
                        for dx in range(2):
                            for dy in range(2):
                                put(x + col * 2 + dx, y - row * 2 - dy, (0.93, 0.89, 0.69, 1))
            x += 12

    for index, spec in enumerate(specs):
        x0 = index * 320
        for x in range(x0 + 3, x0 + 317):
            for y in range(3, height - 3):
                if x in (x0 + 3, x0 + 316) or y in (3, height - 4):
                    put(x, y, (0.38, 0.48, 0.43, 1))
        label(x0 + 18, 247, {'hurricane-mk1': 'HURRICANE',
                               'bf109-e4': 'BF 109 E4',
                               'ju87-b2': 'JU 87 STUKA'}[spec['id']])
        filename = f"{spec['id']}-b1-p1.png"
        image = bpy.data.images.load(str(out / filename), check_existing=False)
        source = list(image.pixels)

        def blit(dx, dy, size):
            for y in range(size):
                sy = min(511, int(y * 512 / size))
                for x in range(size):
                    sx = min(511, int(x * 512 / size))
                    src = (sy * 512 + sx) * 4
                    put(dx + x, dy + y, source[src:src + 4])

        blit(x0 + 23, 37, 180)
        blit(x0 + 222, 60, spec['gameplay']['sprite_width'])
        bpy.data.images.remove(image)
    image = bpy.data.images.new('WW2 original sprites at 180 px and game size',
                                width=width, height=height, alpha=False)
    image.pixels.foreach_set(pixels)
    image.filepath_raw = str(out / 'ww2-comparison.png')
    image.file_format = 'PNG'
    image.save()
    bpy.data.images.remove(image)


def main():
    options = args()
    out = Path(options.out)
    specs = [json.loads(p.read_text(encoding='utf-8'))
             for p in sorted(Path(options.spec_dir).glob('*.json'))]
    log = json.loads((out / 'ww2-render-log.json').read_text(encoding='utf-8'))
    by_id = {entry['id']: entry for entry in log}
    result = {}
    for spec in specs:
        item = by_id[spec['id']]
        frames = item['frames']
        if len(frames) != 9:
            raise ValueError(f'{spec["id"]}: nine frames required')
        hitbox = spec['gameplay']['hitbox']
        # Atlas hitbox scales the actual gameplay rectangle into the 512 tile.
        box = [round(hitbox[0] * 512 / spec['gameplay']['sprite_width']),
               round(hitbox[1] * 512 / spec['gameplay']['sprite_height']),
               round(hitbox[2] * 512 / spec['gameplay']['sprite_width']),
               round(hitbox[3] * 512 / spec['gameplay']['sprite_height'])]
        result[spec['id']] = atlas(out, spec['id'] + '-atlas', frames, 3, 512, 512, box)
        result[spec['id']]['gameplay_size'] = item['gameplay_size']
        result[spec['id']]['source_spec'] = spec['id'] + '.json'
    (out / 'ww2-atlas.json').write_text(json.dumps(result, indent=2), encoding='utf-8')
    comparison(out, specs)
    print('WW2 ATLASES COMPLETE:', [(key, len(value['frames'])) for key, value in result.items()])


if __name__ == '__main__':
    main()
