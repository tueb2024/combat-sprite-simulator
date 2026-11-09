#!/usr/bin/env python3
"""Create four review JPGs per GTX-rendered aircraft; only 2D compositing here."""
import io
import json
from pathlib import Path

import cairosvg
from PIL import Image, ImageDraw, ImageFont


ROOT = Path(__file__).resolve().parents[2]
REVIEWS = ROOT / 'reviews/aircraft'
GAME = ROOT / 'games/combat-sprite-simulator'
FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'


def title(board, line, subtitle):
    draw = ImageDraw.Draw(board)
    draw.text((24, 18), line, fill='#21343b', font=ImageFont.truetype(FONT, 27))
    draw.text((24, 56), subtitle, fill='#506267', font=ImageFont.truetype(FONT, 15))


def fit(image, width, height):
    ratio = min(width / image.width, height / image.height)
    return image.resize((round(image.width * ratio), round(image.height * ratio)), Image.Resampling.LANCZOS)


def panel(board, source, box):
    img = fit(source, box[2], box[3])
    x = box[0] + (box[2] - img.width) // 2
    y = box[1] + (box[3] - img.height) // 2
    if img.mode == 'RGBA':
        board.paste(img, (x, y), img)
    else:
        board.paste(img, (x, y))


def save(board, folder, name):
    board.convert('RGB').save(folder / name, quality=91, subsampling=0)


def main(name):
    folder = REVIEWS / name
    spec = json.loads((folder / 'outline.json').read_text())
    render = Image.open(folder / 'prototype.png').convert('RGBA')
    source_path = folder / spec['source_file']
    if source_path.suffix == '.svg':
        source = Image.open(io.BytesIO(cairosvg.svg2png(url=str(source_path)))).convert('RGBA')
    else:
        source = Image.open(source_path).convert('RGBA')
    source = source.resize(tuple(spec['source_working_size']), Image.Resampling.LANCZOS)
    draw = ImageDraw.Draw(source)
    c = spec['centerline_px']
    if spec['source_orientation'] == 'nose_bottom':
        mirror = lambda points: [(2 * c - x, y) for x, y in points]
        sections = spec['body_sections_px']
        body = [(c + half, y) for y, half in sections] + [(c - half, y) for y, half in reversed(sections)]
    else:
        mirror = lambda points: [(x, 2 * c - y) for x, y in points]
        sections = spec['body_sections_px']
        body = [(x, c + half) for x, half in sections] + [(x, c - half) for x, half in reversed(sections)]
    for key in ('right_wing_px', 'right_tail_px'):
        points = [tuple(p) for p in spec[key]]
        draw.line(points + [points[0]], fill='#e15b31', width=5)
        other = mirror(points)
        draw.line(other + [other[0]], fill='#e15b31', width=5)
    draw.line(body + [body[0]], fill='#24bdc2', width=5)
    board = Image.new('RGB', (1120, 950), '#f0f3ef')
    title(board, f'01  {spec["designation"]} : PLAN ET CONTOUR',
          'Plan public + tracé manuel paramétrique, superposés aux mêmes coordonnées')
    panel(board, source, (30, 95, 1060, 820))
    save(board, folder, '01-source-and-outline.jpg')

    board = Image.new('RGB', (1024, 1024), '#edf1eb')
    board.paste(render, (0, 0), render)
    title(board, f'02  {spec["designation"].upper()}', 'Blender GTX : vue orthographique sur fond transparent')
    save(board, folder, '02-clean-top-view.jpg')

    ground = Image.open(GAME / 'assets/tiles/ign-beauce-2023.png').convert('RGB').resize((320, 480))
    game_w, game_h = spec['gameplay_size']
    if name == 'junkers-g38':
        game = render.resize((game_w, game_h), Image.Resampling.LANCZOS)
        px, py = 160 - game_w // 2, 65
    else:
        game = render.resize((game_w, game_h), Image.Resampling.LANCZOS)
        px, py = 160 - game_w // 2, 190
    ground.paste(game, (px, py), game)
    board = Image.new('RGB', (810, 590), '#eff2ee')
    title(board, '03  TAILLE REELLE EN JEU', f'{spec["designation"]} : {game_w} x {game_h} px, fond IGN du jeu')
    board.paste(ground, (24, 85))
    crop = ground.crop((max(0, px - 8), max(0, py - 8),
                        min(320, px + game_w + 8), min(480, py + game_h + 8)))
    panel(board, crop.resize((crop.width * 4, crop.height * 4), Image.Resampling.NEAREST),
          (380, 95, 400, 420))
    save(board, folder, '03-actual-game-size.jpg')

    old = Image.open(folder / 'legacy-frame.png').convert('RGBA').rotate(180)
    old = old.crop(old.getchannel('A').getbbox())
    clean = render.crop(render.getchannel('A').getbbox())
    if spec['source_orientation'] == 'nose_bottom':
        source_top = source.crop((spec['span_bounds_px'][0] - 20, 0,
                                  spec['span_bounds_px'][1] + 20, spec['nose_px'] + 25)).rotate(180)
    else:
        source_top = source.crop((max(0, spec['nose_px'] - 20), spec['span_bounds_px'][0] - 20,
                                  spec['tail_px'] + 20, spec['span_bounds_px'][1] + 20)).rotate(270, expand=True)
    board = Image.new('RGB', (1140, 500), '#eff2ed')
    title(board, '04  COMPARAISON', f'Même envergure affichée : ancien atlas / nouveau Blender GTX / plan {spec["designation"]}')
    for i, (label, picture) in enumerate((('ANCIEN', old), ('NOUVEAU', clean), ('PLAN', source_top))):
        x = 18 + 375 * i
        draw = ImageDraw.Draw(board)
        draw.rectangle((x, 95, x + 354, 475), fill='#fcfdfb', outline='#bcc9c4', width=2)
        draw.text((x + 12, 108), label, fill='#263c40', font=ImageFont.truetype(FONT, 17))
        panel(board, picture, (x + 12, 138, 330, 320))
    save(board, folder, '04-three-way-comparison.jpg')
    print(f'{name}: four JPG review boards')


if __name__ == '__main__':
    for aircraft in ('bf109-e4', 'ju87-b2', 'junkers-g38'):
        main(aircraft)
