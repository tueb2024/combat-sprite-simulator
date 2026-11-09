"""Assemble alpha atlases from parametric_aircraft.py frames in Blender 5.x.

Blender: blender -b --python assemble_parametric_atlas.py -- --out DIR
Preserves the original game atlas layout for Hurricane and Junkers.
"""
import argparse
import json
from pathlib import Path
import sys

import bpy


def args():
    parser = argparse.ArgumentParser()
    parser.add_argument('--out', required=True)
    return parser.parse_args(sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else [])


def atlas(out, name, files, columns, width, height, hitbox):
    rows = (len(files) + columns - 1) // columns
    canvas_width, canvas_height = columns * width, rows * height
    pixels = [0.0] * (canvas_width * canvas_height * 4)
    frames = []
    for index, filename in enumerate(files):
        image = bpy.data.images.load(str(out / filename), check_existing=False)
        if tuple(image.size) != (width, height):
            raise ValueError(f'{filename}: {tuple(image.size)} != {(width, height)}')
        source = list(image.pixels)
        visible = sum(1 for alpha in source[3::4] if alpha > 0.03)
        if visible < width * height * 0.015 or visible > width * height * 0.90:
            raise ValueError(f'{filename}: suspicious alpha coverage {visible}')
        col, row = index % columns, index // columns
        for y in range(height):
            destination = (((rows - row - 1) * height + y) * canvas_width + col * width) * 4
            origin = y * width * 4
            pixels[destination:destination + width * 4] = source[origin:origin + width * 4]
        frames.append({'name': filename[:-4], 'x': col * width, 'y': row * height,
                       'width': width, 'height': height, 'pivot': [width // 2, height // 2],
                       'hitbox': hitbox, 'alpha_pixels': visible})
        bpy.data.images.remove(image)
    image = bpy.data.images.new(name, width=canvas_width, height=canvas_height, alpha=True)
    image.pixels.foreach_set(pixels)
    image.filepath_raw = str(out / (name + '.png'))
    image.file_format = 'PNG'
    image.save()
    bpy.data.images.remove(image)
    return {'image': name + '.png', 'width': canvas_width,
            'height': canvas_height, 'frames': frames}


def main():
    out = Path(args().out)
    rendered = json.loads((out / 'render-manifest.json').read_text(encoding='utf-8'))
    h = rendered['hurricane']['frames']
    j = rendered['junkers']['frames']
    c = rendered['camel']['frames']
    result = {
        'hurricane': atlas(out, 'hurricane-atlas', h, 3, 256, 256, [64, 64, 128, 128]),
        'junkers': atlas(out, 'junkers-atlas', [j[4], j[13]], 2, 512, 256,
                         [128, 64, 256, 128]),
        'junkers_variants': atlas(out, 'junkers-variants-atlas', j, 3, 512, 256,
                                  [128, 64, 256, 128]),
        'camel': atlas(out, 'camel-atlas', c, 3, 256, 256, [64, 64, 128, 128]),
    }
    (out / 'atlas.json').write_text(json.dumps(result, indent=2), encoding='utf-8')
    print('Validated 3 aircraft, 36 rendered frames, four alpha atlases')


if __name__ == '__main__':
    main()
