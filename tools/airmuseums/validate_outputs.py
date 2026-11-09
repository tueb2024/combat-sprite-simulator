#!/usr/bin/env python3
"""Audit private Hangar downloads and GTX renders without opening the images."""

import argparse
import hashlib
import json
from pathlib import Path
import struct


ROOT = Path(__file__).resolve().parents[2]
DEFAULT = ROOT / 'artifacts/airmuseums'
VIEWS = ('top-bank-left', 'top', 'top-bank-right', 'left', 'right')


def png_size(path):
    with path.open('rb') as file:
        header = file.read(26)
    if header[:8] != b'\x89PNG\r\n\x1a\n' or header[12:16] != b'IHDR':
        raise ValueError(f'{path}: not a PNG')
    width, height = struct.unpack_from('>II', header, 16)
    if header[25] != 6:
        raise ValueError(f'{path}: expected RGBA PNG, color type {header[25]}')
    return width, height


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--out', type=Path, default=DEFAULT)
    parser.add_argument('--strict', action='store_true', help='Exit with error unless every catalogue entry has five GTX views')
    args = parser.parse_args()
    data = json.loads((args.out / 'manifest.json').read_text())
    complete = []
    missing = []
    bad = []
    for item in data['aircraft']:
        slug = item['slug']
        folder = args.out / slug
        model = folder / f'{slug}.glb'
        profile = folder / f'{slug}-profile.webp'
        if not model.is_file() or not profile.is_file():
            bad.append(f'{slug}: missing GLB or profile')
            continue
        for path, key in ((model, 'model_sha256'), (profile, 'profile_sha256')):
            actual = hashlib.sha256(path.read_bytes()).hexdigest()
            if actual != item.get(key):
                bad.append(f'{slug}: SHA-256 mismatch {path.name}')
        views = [folder / f'{slug}-{view}.png' for view in VIEWS]
        if not all(path.is_file() for path in views):
            missing.append(slug)
            continue
        try:
            if any(png_size(path) != (512, 512) for path in views):
                bad.append(f'{slug}: wrong view dimensions')
                continue
            render_info = json.loads((folder / f'{slug}-render.json').read_text())
            if render_info['views'] != list(VIEWS) or 'GTX 1660 SUPER' not in render_info['device']:
                bad.append(f'{slug}: GTX render metadata mismatch')
                continue
        except (OSError, ValueError, KeyError) as error:
            bad.append(f'{slug}: {error}')
            continue
        complete.append(slug)
    print(f"Downloaded: {len(data['aircraft'])}; GTX five-view sets: {len(complete)}; pending: {len(missing)}; invalid: {len(bad)}")
    if data.get('errors'):
        print(f"Collector errors: {len(data['errors'])}")
    if bad:
        print('Invalid:', *bad[:20], sep='\n  ')
    if args.strict and (missing or bad or data.get('errors')):
        raise SystemExit(1)


if __name__ == '__main__':
    main()
