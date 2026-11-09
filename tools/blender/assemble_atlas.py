"""Original atlas assembler; --out selects validated parametric atlases."""
import runpy
import sys
from pathlib import Path

if '--ww2' in sys.argv:
    sys.argv.remove('--ww2')
    runpy.run_path(str(Path(__file__).with_name('assemble_ww2.py')), run_name='__main__')
    raise SystemExit(0)

if '--out' in sys.argv:
    runpy.run_path(str(Path(__file__).with_name('assemble_parametric_atlas.py')), run_name='__main__')
    raise SystemExit(0)

import bpy
import json
import os

out = 'C:/Users/tchou/combat-sprite-simulator-assets'

def atlas(name, files, columns, tile_w, tile_h):
    rows = (len(files) + columns - 1) // columns
    width, height = columns * tile_w, rows * tile_h
    pixels = [0.0] * (width * height * 4)
    frames = []
    for i, filename in enumerate(files):
        image = bpy.data.images.load(os.path.join(out, filename), check_existing=False)
        source = list(image.pixels)
        col, row = i % columns, i // columns
        for y in range(tile_h):
            dest = (((rows - row - 1) * tile_h + y) * width + col * tile_w) * 4
            src = y * tile_w * 4
            pixels[dest:dest + tile_w * 4] = source[src:src + tile_w * 4]
        frames.append({'name': filename[:-4], 'x': col * tile_w, 'y': row * tile_h,
                       'width': tile_w, 'height': tile_h, 'pivot': [tile_w // 2, tile_h // 2],
                       'hitbox': [int(tile_w * .25), int(tile_h * .25), int(tile_w * .5), int(tile_h * .5)]})
        bpy.data.images.remove(image)
    image = bpy.data.images.new(name, width=width, height=height, alpha=True)
    image.pixels.foreach_set(pixels)
    image.filepath_raw = os.path.join(out, name + '.png')
    image.file_format = 'PNG'
    image.save()
    return {'image': name + '.png', 'width': width, 'height': height, 'frames': frames}

h = atlas('hurricane-atlas', [f'hurricane-{row}-{col}.png' for row in range(3) for col in range(3)], 3, 256, 256)
j = atlas('junkers-atlas', [f'junkers-{i}.png' for i in range(2)], 2, 512, 256)
with open(os.path.join(out, 'atlas.json'), 'w', encoding='utf-8') as file:
    json.dump({'hurricane': h, 'junkers': j}, file, indent=2)
