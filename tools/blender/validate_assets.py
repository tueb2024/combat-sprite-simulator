#!/usr/bin/env python3
"""Validate generated RGBA PNG atlases and the manifest without image packages."""
import argparse
import json
from pathlib import Path
import struct
import zlib


def png_alpha(path):
    raw = path.read_bytes()
    if not raw.startswith(b'\x89PNG\r\n\x1a\n'):
        raise ValueError(f'{path}: missing PNG signature')
    offset, compressed, size = 8, bytearray(), None
    while offset < len(raw):
        length = struct.unpack_from('>I', raw, offset)[0]
        kind = raw[offset + 4:offset + 8]
        payload = raw[offset + 8:offset + 8 + length]
        crc = struct.unpack_from('>I', raw, offset + 8 + length)[0]
        if zlib.crc32(kind + payload) != crc:
            raise ValueError(f'{path}: corrupt {kind!r} chunk')
        if kind == b'IHDR':
            width, height, depth, colour, _, _, interlace = struct.unpack('>IIBBBBB', payload)
            if (depth, colour, interlace) != (8, 6, 0):
                raise ValueError(f'{path}: expected non-interlaced RGBA 8-bit')
            size = (width, height)
        elif kind == b'IDAT':
            compressed.extend(payload)
        elif kind == b'IEND':
            break
        offset += length + 12
    if size is None:
        raise ValueError(f'{path}: no IHDR')
    width, height = size
    data = zlib.decompress(compressed)
    stride = width * 4
    if len(data) != height * (stride + 1):
        raise ValueError(f'{path}: truncated pixel stream')
    rows, previous, offset = [], bytearray(stride), 0
    for _ in range(height):
        filt = data[offset]
        row = bytearray(data[offset + 1:offset + 1 + stride])
        offset += stride + 1
        for i in range(stride):
            left = row[i - 4] if i >= 4 else 0
            up = previous[i]
            upper_left = previous[i - 4] if i >= 4 else 0
            if filt == 1:
                row[i] = (row[i] + left) & 255
            elif filt == 2:
                row[i] = (row[i] + up) & 255
            elif filt == 3:
                row[i] = (row[i] + (left + up) // 2) & 255
            elif filt == 4:
                p = left + up - upper_left
                distances = (abs(p - left), abs(p - up), abs(p - upper_left))
                predictor = (left, up, upper_left)[distances.index(min(distances))]
                row[i] = (row[i] + predictor) & 255
            elif filt != 0:
                raise ValueError(f'{path}: unsupported PNG filter {filt}')
        rows.append(row[3::4])
        previous = row
    return size, rows


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--spec', default='tools/blender/aircraft-spec.json')
    parser.add_argument('--assets', default='games/combat-sprite-simulator/assets/sprites')
    args = parser.parse_args()
    specs = json.loads(Path(args.spec).read_text(encoding='utf-8'))['aircraft']
    root = Path(args.assets)
    atlases = json.loads((root / 'atlas.json').read_text(encoding='utf-8'))
    expected = {'hurricane': 9, 'junkers': 2, 'junkers_variants': 18, 'camel': 9}
    assert set(atlases) == set(expected), 'Missing or unexpected atlas'
    ids = {spec['id'] for spec in specs}
    assert ids == {'hurricane', 'junkers', 'camel'}, 'Aircraft spec set changed'
    for name, expected_count in expected.items():
        atlas = atlases[name]
        frames = atlas['frames']
        assert len(frames) == expected_count, f'{name}: wrong frame count'
        (width, height), alpha = png_alpha(root / atlas['image'])
        assert (width, height) == (atlas['width'], atlas['height']), f'{name}: wrong image size'
        for frame in frames:
            x, y, w, h = (frame[key] for key in ('x', 'y', 'width', 'height'))
            assert 0 <= x < width and 0 <= y < height and x + w <= width and y + h <= height
            visible = sum(sum(v > 8 for v in row[x:x + w]) for row in alpha[y:y + h])
            assert w * h * 0.015 < visible < w * h * 0.9, f'{name}/{frame["name"]}: alpha coverage {visible}'
        print(f'{name}: {width}x{height}, {len(frames)} valid RGBA frames')
    for spec in specs:
        assert spec['wingspan_m'] > spec['length_m'] > 0
        assert len(spec['engine_positions']) == (4 if spec['id'] == 'junkers' else 1)
        assert spec['source'] and spec['evidence'] and spec['game']
        assert all('page' in source and 'book_id' in source for source in spec['pdf_sources'])
    print('Aircraft specifications and atlas manifests valid')


if __name__ == '__main__':
    main()
