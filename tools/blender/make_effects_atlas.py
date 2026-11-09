#!/usr/bin/env python3
"""Create reusable 2D projectile, muzzle and pickup sprites (no 3D rendering)."""

from pathlib import Path
from PIL import Image, ImageDraw


ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'games/combat-sprite-simulator/assets/sprites/effects-atlas.png'
SCALE = 4
SIZE = 64 * SCALE


def draw_tile(kind):
    image = Image.new('RGBA', (SIZE, SIZE), (0, 0, 0, 0))
    d = ImageDraw.Draw(image)
    if kind in ('player', 'enemy'):
        warm = kind == 'player'
        outer = (255, 177, 60, 38) if warm else (255, 85, 54, 45)
        core = (255, 241, 171, 255) if warm else (255, 183, 143, 255)
        for width, alpha in [(18, outer[3]), (12, 95), (7, 205)]:
            color = (outer[0], outer[1], outer[2], alpha)
            d.ellipse((32*SCALE-width*SCALE/2, 7*SCALE,
                       32*SCALE+width*SCALE/2, 57*SCALE), fill=color)
        d.rounded_rectangle((29*SCALE, 16*SCALE, 35*SCALE, 52*SCALE),
                            radius=3*SCALE, fill=core)
        d.polygon([(32*SCALE, 5*SCALE), (26*SCALE, 21*SCALE),
                   (38*SCALE, 21*SCALE)], fill=core)
    elif kind == 'speed':
        d.ellipse((8*SCALE, 8*SCALE, 56*SCALE, 56*SCALE),
                  fill=(16, 90, 115, 210), outline=(140, 237, 255, 255), width=3*SCALE)
        for y in (19, 32):
            d.line([(18*SCALE, (y+10)*SCALE), (32*SCALE, y*SCALE),
                    (46*SCALE, (y+10)*SCALE)], fill=(236, 254, 255, 255),
                   width=5*SCALE, joint='curve')
    elif kind == 'rapid':
        d.ellipse((8*SCALE, 8*SCALE, 56*SCALE, 56*SCALE),
                  fill=(142, 78, 20, 220), outline=(255, 208, 100, 255), width=3*SCALE)
        for x in (20, 28, 36, 44):
            d.line([(x*SCALE, 22*SCALE), (x*SCALE, 42*SCALE)],
                   fill=(255, 248, 210, 255), width=4*SCALE)
    else:  # muzzle
        points = [(32, 5), (37, 22), (51, 16), (43, 30),
                  (59, 33), (42, 38), (48, 54), (32, 45),
                  (17, 56), (22, 39), (5, 33), (22, 28),
                  (15, 15), (28, 22)]
        d.polygon([(x*SCALE, y*SCALE) for x, y in points], fill=(255, 173, 41, 220))
        d.ellipse((25*SCALE, 25*SCALE, 39*SCALE, 39*SCALE),
                  fill=(255, 251, 192, 255))
    return image.resize((64, 64), Image.Resampling.LANCZOS)


def main():
    atlas = Image.new('RGBA', (320, 64), (0, 0, 0, 0))
    for index, name in enumerate(('player', 'enemy', 'speed', 'rapid', 'muzzle')):
        atlas.alpha_composite(draw_tile(name), (index * 64, 0))
    atlas.save(OUT)
    print(f'{OUT}: 5 transparent 64x64 effect frames')


if __name__ == '__main__':
    main()
