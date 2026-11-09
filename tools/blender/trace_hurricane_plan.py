#!/usr/bin/env python3
"""Sample the Mk I plan's actual wing and tail outlines for the review model.

Requires svgpathtools only for this one-time/source update step. Blender itself
uses the generated JSON and needs no third-party Python modules.
"""

import json
from pathlib import Path
import xml.etree.ElementTree as ET

from svgpathtools import parse_path


ROOT = Path(__file__).resolve().parents[2]
REVIEW = ROOT / 'reviews/aircraft/hurricane-mk1'
SOURCE = REVIEW / 'source-hurricane-3view.svg'
OUT = REVIEW / 'outline.json'
SVG = '{http://www.w3.org/2000/svg}'


def samples(element, count):
    path = parse_path(element.attrib['d'])
    return [[round(path.point(i / (count - 1)).real + 419.99999, 3),
             round(path.point(i / (count - 1)).imag + 2.1105522, 3)]
            for i in range(count)]


def main():
    tree = ET.parse(SOURCE)
    paths = {p.attrib.get('id'): p for p in tree.iter(f'{SVG}path')}
    # These are the outer leading/tip/trailing curves in MLWatts' Mk I top view.
    wing = samples(paths['path3089'], 128)
    tail = samples(paths['path3095'], 64)
    center = 419.99999 + 1241.6676 / 2
    data = {
        'source': SOURCE.name,
        'source_paths': {'right_wing': 'path3089', 'right_tailplane': 'path3095'},
        'source_image_px': [1658, 1260],
        'center_x_px': round(center, 3),
        'nose_y_px': 285,
        'tail_y_px': 1227,
        'span_px': 1200,
        'dimensions_m': {'span': 12.19, 'length': 9.57},
        'right_wing': wing,
        'right_tailplane': tail,
        # Hand-calibrated to the outer top-view fuselage contour, including
        # the Merlin cowling, wing-root fairing and tapered rear fuselage.
        # y in the original SVG; half-width in pixels, from x=1040.834.
        'fuselage_sections_px': [
            [285, 1], [295, 20], [310, 30], [335, 36], [365, 38],
            [405, 43], [460, 51], [490, 53], [545, 53], [625, 52],
            [700, 51], [770, 49], [835, 46], [905, 41], [975, 34],
            [1040, 27], [1090, 19], [1145, 12], [1200, 5], [1227, 1]
        ],
        'canopy_source_box_px': [1008, 570, 1075, 705],
        'roundel_centers_px': [[818, 585], [1263, 585]],
    }
    OUT.write_text(json.dumps(data, indent=2) + '\n', encoding='utf-8')
    print(f'{OUT}: {len(wing)} wing and {len(tail)} tail contour points')


if __name__ == '__main__':
    main()
