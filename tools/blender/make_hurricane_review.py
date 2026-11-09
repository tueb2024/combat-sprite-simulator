#!/usr/bin/env python3
"""Make four browser-readable JPG boards from the GTX Hurricane render.

This script only composites/reduces images. All 3D modeling and rendering are
performed by Blender on the GTX PC via run_review_hurricane_on_gtx.sh.
"""

import io
import json
from pathlib import Path

import cairosvg
from PIL import Image, ImageChops, ImageDraw, ImageFilter, ImageFont


ROOT = Path(__file__).resolve().parents[2]
REVIEW = ROOT / 'reviews/aircraft/hurricane-mk1'
GAME = ROOT / 'games/combat-sprite-simulator'
OUTLINE = json.loads((REVIEW / 'outline.json').read_text(encoding='utf-8'))
RENDER = Image.open(REVIEW / 'hurricane-prototype.png').convert('RGBA')
OLD = Image.open(REVIEW / 'legacy-frame.png').convert('RGBA')
SOURCE = Image.open(io.BytesIO(cairosvg.svg2png(url=str(REVIEW / 'source-hurricane-3view.svg')))).convert('RGBA')
FONT_PATH = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'


def font(size, bold=False):
    path = FONT_PATH.replace('Sans.ttf', 'Sans-Bold.ttf') if bold else FONT_PATH
    return ImageFont.truetype(path, size)


def label(image, title, subtitle):
    draw = ImageDraw.Draw(image)
    draw.text((22, 16), title, font=font(25, True), fill=(28, 39, 43))
    draw.text((22, 50), subtitle, font=font(15), fill=(65, 78, 80))


def source_geometry():
    mask = Image.new('L', SOURCE.size, 0)
    draw = ImageDraw.Draw(mask)
    center = OUTLINE['center_x_px']
    for key in ('right_wing', 'right_tailplane'):
        points = [tuple(p) for p in OUTLINE[key]]
        draw.polygon(points, fill=255)
        draw.polygon([(2 * center - x, y) for x, y in points], fill=255)
    sections = OUTLINE['fuselage_sections_px']
    right = [(center + half, y) for y, half in sections]
    left = [(center - half, y) for y, half in reversed(sections)]
    draw.polygon(right + left, fill=255)
    return mask


def transform(image, resample=Image.Resampling.LANCZOS):
    # Same physical scale as Blender's 13.5 m orthographic camera.
    scale = 1024 * OUTLINE['dimensions_m']['span'] / (13.5 * OUTLINE['span_px'])
    width, height = round(image.width * scale), round(image.height * scale)
    scaled = image.resize((width, height), resample)
    x = round(512 - OUTLINE['center_x_px'] * scale)
    y = round(512 - (OUTLINE['nose_y_px'] + OUTLINE['tail_y_px']) / 2 * scale)
    return scaled, x, y


def plan_canvas():
    source_mask = source_geometry()
    source_top_ink = ImageChops.multiply(SOURCE.getchannel('A'), source_mask.filter(ImageFilter.MaxFilter(21)))
    source_view = Image.new('RGBA', SOURCE.size, (0, 0, 0, 0))
    source_view.putalpha(source_top_ink)
    scaled, x, y = transform(source_view)
    plan = Image.new('RGBA', (1024, 1024), (255, 255, 255, 0))
    plan.alpha_composite(scaled, (x, y))
    mask_scaled, _, _ = transform(source_mask)
    mask = Image.new('L', (1024, 1024), 0)
    mask.paste(mask_scaled, (x, y))
    return plan, mask


def save(image, name):
    image.convert('RGB').save(REVIEW / name, 'JPEG', quality=93, subsampling=0)


def board_one(plan, reference_mask):
    img = Image.new('RGB', (1024, 1024), (241, 243, 239))
    img.paste(plan, mask=plan.getchannel('A'))
    actual_mask = RENDER.getchannel('A').point(lambda a: 255 if a > 32 else 0)
    binary_a = actual_mask.convert('1')
    binary_b = reference_mask.point(lambda a: 255 if a > 128 else 0).convert('1')
    intersection = ImageChops.logical_and(binary_a, binary_b)
    union = ImageChops.logical_or(binary_a, binary_b)
    intersection_count = sum(v != 0 for v in intersection.get_flattened_data())
    union_count = sum(v != 0 for v in union.get_flattened_data())
    score = intersection_count / union_count if union_count else 0
    overlay = Image.new('RGB', (1024, 1024), (46, 151, 158))
    img.paste(overlay, mask=actual_mask.point(lambda a: 52 if a else 0))
    draw = ImageDraw.Draw(img)
    edge_source = ImageChops.subtract(reference_mask, reference_mask.filter(ImageFilter.MinFilter(7)))
    edge_render = ImageChops.subtract(actual_mask, actual_mask.filter(ImageFilter.MinFilter(7)))
    img.paste((31, 104, 129), mask=edge_source)
    img.paste((212, 102, 49), mask=edge_render)
    label(img, '01  PLAN ET CONTOUR', 'Plan Mk I MLWatts CC0 + silhouette Blender GTX, même échelle')
    draw.text((24, 961), f'Bleu = plan ; orange = rendu | recouvrement IoU = {score:.3f}',
              font=font(17), fill=(34, 48, 51))
    save(img, '01-source-and-outline.jpg')
    return score


def board_two():
    img = Image.new('RGBA', (1024, 1024), (236, 239, 234, 255))
    img.alpha_composite(RENDER)
    label(img, '02  HURRICANE MK I', 'Blender GTX | orthographique 1024 px | sans ombre au sol')
    save(img, '02-clean-top-view.jpg')


def board_three():
    ground = Image.open(GAME / 'assets/tiles/ign-beauce-2023.png').convert('RGB').resize(
        (320, 480), Image.Resampling.LANCZOS)
    sprite = RENDER.resize((60, 60), Image.Resampling.LANCZOS)
    ground.paste(sprite, (130, 375), sprite)
    hud = ImageDraw.Draw(ground)
    hud.rectangle((0, 0, 319, 30), fill=(19, 30, 30))
    hud.text((8, 7), 'LIVES 3    SCORE 0', font=font(13, True), fill=(244, 238, 204))
    hud.text((220, 461), '(C) IGN 2023', font=font(9), fill=(242, 236, 203))
    board = Image.new('RGB', (810, 590), (239, 241, 236))
    label(board, '03  TAILLE REELLE EN JEU', 'Montage avec le fond IGN utilisé par JumPHP, vue 320 x 480 px')
    board.paste(ground, (30, 70))
    crop = ground.crop((125, 370, 195, 440)).resize((350, 350), Image.Resampling.NEAREST)
    board.paste(crop, (420, 118))
    draw = ImageDraw.Draw(board)
    draw.rectangle((420, 118, 769, 467), outline=(50, 62, 65), width=2)
    draw.text((420, 477), 'Zoom x5, pixels non interpolés', font=font(16), fill=(42, 57, 59))
    draw.text((30, 565), 'Rendu source Blender GTX, intégré dans l atlas actif du jeu.',
              font=font(13), fill=(71, 79, 79))
    save(board, '03-actual-game-size.jpg')


def fit_rgba(image, bounds, width):
    cropped = image.crop(bounds)
    scale = width / cropped.width
    return cropped.resize((width, round(cropped.height * scale)), Image.Resampling.LANCZOS)


def board_four(plan, reference_mask):
    board = Image.new('RGB', (1140, 470), (238, 241, 236))
    label(board, '04  COMPARAISON TROIS VUES', 'Même envergure affichée : ancien atlas / nouveau rendu / plan Mk I')
    old_mask = OLD.getchannel('A')
    new_mask = RENDER.getchannel('A')
    entries = [
        ('ANCIEN ATLAS', OLD, old_mask),
        ('NOUVEAU PROTOTYPE', RENDER, new_mask),
        ('PLAN DE REFERENCE', plan, reference_mask),
    ]
    draw = ImageDraw.Draw(board)
    for i, (name, picture, mask) in enumerate(entries):
        x = 20 + i * 375
        draw.rectangle((x, 91, x + 354, 446), fill=(249, 250, 247), outline=(194, 205, 201), width=2)
        draw.text((x + 16, 103), name, font=font(17, True), fill=(37, 52, 55))
        bounds = mask.getbbox()
        fitted = fit_rgba(picture, bounds, 300)
        px = x + (354 - fitted.width) // 2
        py = 143 + (285 - fitted.height) // 2
        if fitted.mode == 'RGBA':
            board.paste(fitted, (px, py), fitted)
        else:
            board.paste(fitted, (px, py))
    save(board, '04-three-way-comparison.jpg')


def main():
    plan, mask = plan_canvas()
    score = board_one(plan, mask)
    board_two()
    board_three()
    board_four(plan, mask)
    print(f'Four JPG review boards created; source/render silhouette IoU={score:.3f}')


if __name__ == '__main__':
    main()
