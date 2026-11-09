#!/usr/bin/env python3
"""Pack private GTX top renders into three-frame shmup candidate atlases.

This is 2D packing only. The source GLBs and 3D renders stay in the ignored
artifacts tree; generated atlases are not cleared for public game distribution.
"""

import argparse
import json
from pathlib import Path

from PIL import Image


ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'artifacts/airmuseums'
WIDTH_BY_ROLE = {'fighter': 64, 'strike': 80, 'bomber': 120, 'transport': 104,
                 'airliner': 104, 'recon': 76, 'helicopter': 70}
VIEWS = ('top-bank-left', 'top', 'top-bank-right')


def pack(item, source, output):
    slug = item['slug']
    frames = [Image.open(source / slug / f'{slug}-{name}.png').convert('RGBA') for name in VIEWS]
    if any(frame.size != (512, 512) for frame in frames):
        raise ValueError(f'{slug}: expected three 512 x 512 renders')
    boxes = [frame.getchannel('A').getbbox() for frame in frames]
    if any(box is None for box in boxes):
        raise ValueError(f'{slug}: transparent frame')
    union = (min(box[0] for box in boxes), min(box[1] for box in boxes),
             max(box[2] for box in boxes), max(box[3] for box in boxes))
    crop_size = max(union[2] - union[0], union[3] - union[1])
    cx = (union[0] + union[2]) / 2
    cy = (union[1] + union[3]) / 2
    crop = (round(cx - crop_size / 2), round(cy - crop_size / 2),
            round(cx + crop_size / 2), round(cy + crop_size / 2))
    atlas = Image.new('RGBA', (1536, 512))
    frame_specs = []
    for index, frame in enumerate(frames):
        normalized = frame.crop(crop).resize((448, 448), Image.Resampling.LANCZOS)
        tile = Image.new('RGBA', (512, 512))
        tile.paste(normalized, (32, 32))
        if item['game_role'] == 'enemy_candidate':
            tile = tile.rotate(180)
        atlas.paste(tile, (index * 512, 0))
        bbox = tile.getchannel('A').getbbox()
        frame_specs.append({'name': VIEWS[index], 'rect': [index * 512, 0, 512, 512],
                            'visible_bbox': list(bbox)})
    output.mkdir(parents=True, exist_ok=True)
    atlas_path = output / f'{slug}-atlas.png'
    atlas.save(atlas_path, optimize=True)
    draw_size = WIDTH_BY_ROLE.get(item['role'], 72)
    center_bbox = frame_specs[1]['visible_bbox']
    hitbox = [round(v * draw_size / 512) for v in (
        center_bbox[0], center_bbox[1], center_bbox[2] - center_bbox[0], center_bbox[3] - center_bbox[1])]
    return {'slug': slug, 'modelled_variant': item.get('modelled_variant', item['name']),
            'site_role': item['role'], 'game_role': item['game_role'],
            'source_page': item['hangar_url'], 'model_url': item['model_url'],
            'rights': item['rights'], 'atlas': atlas_path.name,
            'orientation': 'up' if item['game_role'] == 'player_candidate' else 'down',
            'tile_size': [512, 512], 'draw_size': [draw_size, draw_size],
            'hitbox': hitbox, 'frames': frame_specs}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, default=SOURCE)
    parser.add_argument('--out', type=Path, default=None)
    parser.add_argument('--slugs', default='', help='Comma-separated slugs; default: all downloaded renders')
    args = parser.parse_args()
    source = args.source
    output = args.out or source / 'shmup-atlases'
    manifest = json.loads((source / 'manifest.json').read_text())
    wanted = {slug.strip() for slug in args.slugs.split(',') if slug.strip()}
    records = {}
    for item in manifest['aircraft']:
        if wanted and item['slug'] not in wanted:
            continue
        if not item.get('rendered_views'):
            continue
        records[item['slug']] = pack(item, source, output)
        print(f"packed {item['slug']} -> {item['game_role']}")
    if wanted - set(records):
        raise RuntimeError('No rendered views for: ' + ', '.join(sorted(wanted - set(records))))
    if not records:
        raise RuntimeError('No rendered aircraft found')
    (output / 'roster.json').write_text(json.dumps({'aircraft': records}, indent=2) + '\n')
    print(f'{len(records)} private atlases: {output}')


if __name__ == '__main__':
    main()
