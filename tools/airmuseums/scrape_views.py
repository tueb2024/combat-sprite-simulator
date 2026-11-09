#!/usr/bin/env python3
"""Inventory Aviation Gazetteer's Hangar and acquire private model references.

The public catalogue is /aircraft. /models/aircraft/ is an asset path, not an
HTML directory. Render downloaded GLBs only on the owner's GTX PC with `render`.
No downloaded model or derived image is placed in the game's published assets.
"""

import argparse
import hashlib
from html.parser import HTMLParser
from html import unescape
import json
from pathlib import Path
import re
import subprocess
import sys
import time
from urllib.parse import urljoin, urlparse
from urllib.request import Request, urlopen
from urllib.robotparser import RobotFileParser


BASE = 'https://airmuseums.co.uk'
CATALOG = BASE + '/aircraft'
AGENT = 'CombatSpriteSimulatorReferenceCollector/1.0 (+private research; one request per second)'
MAX_BYTES = 30 * 1024 * 1024
DEFAULT_OUT = Path(__file__).resolve().parents[2] / 'artifacts/airmuseums'
RENDERER = Path(__file__).with_name('render_glb_views.py')
RUNNER = Path(__file__).with_name('run-view-render.cmd')
REMOTE = 'C:/Users/tchou/combat-sprite-airmuseums'
SLUG = re.compile(r'^[a-z0-9]+(?:-[a-z0-9]+)*$')


class CatalogParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.entries = []
        self.current = None

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if tag == 'li' and 'w-t' in values.get('class', '').split():
            self.current = {
                'slug': values.get('data-s', ''),
                'name': values.get('data-name', ''),
                'role': values.get('data-role', ''),
                'era': values.get('data-era', ''),
                'variant': values.get('data-name', ''),
                'hangar_url': '',
            }
        elif self.current is not None and tag == 'a':
            href = values.get('href', '')
            if href.startswith('/hangar/'):
                self.current['hangar_url'] = urljoin(BASE, href)

    def handle_endtag(self, tag):
        if tag == 'li' and self.current is not None:
            if SLUG.fullmatch(self.current['slug']) and self.current['hangar_url']:
                self.current['game_role'] = 'player_candidate' if self.current['role'] == 'fighter' else 'enemy_candidate'
                self.entries.append(self.current)
            self.current = None


class HangarParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.model = ''
        self.profile = ''
        self.download = ''

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if tag == 'model-viewer':
            self.model = values.get('src', '').split('?', 1)[0]
        if tag == 'a' and 'download' in values:
            href = values.get('href', '')
            if href.endswith('.glb'):
                self.download = href
        for value in values.values():
            if isinstance(value, str):
                path = urlparse(value).path
                if re.fullmatch(r'/models/aircraft/[a-z0-9-]+-profile\.webp', path):
                    self.profile = value


def safe_url(path, suffix):
    url = urljoin(BASE, path)
    parsed = urlparse(url)
    if parsed.scheme != 'https' or parsed.netloc != 'airmuseums.co.uk':
        raise ValueError(f'Off-site URL refused: {url}')
    if not re.fullmatch(r'/models/aircraft/[a-z0-9-]+\.' + suffix, parsed.path):
        raise ValueError(f'Unexpected asset path: {url}')
    return url


class Client:
    def __init__(self, delay):
        self.delay = delay
        self.last = 0.0
        self.robots = RobotFileParser()
        request = Request(BASE + '/robots.txt', headers={'User-Agent': AGENT})
        with urlopen(request, timeout=25) as reply:
            self.robots.parse(reply.read(128 * 1024).decode('utf-8').splitlines())

    def read(self, url, limit=MAX_BYTES):
        if not self.robots.can_fetch(AGENT, url):
            raise RuntimeError(f'robots.txt disallows {url}')
        wait = self.delay - (time.monotonic() - self.last)
        if wait > 0:
            time.sleep(wait)
        request = Request(url, headers={'User-Agent': AGENT, 'Accept': 'text/html,image/webp,model/gltf-binary,*/*;q=0.5'})
        try:
            with urlopen(request, timeout=25) as reply:
                if reply.status != 200:
                    raise RuntimeError(f'HTTP {reply.status}: {url}')
                data = reply.read(limit + 1)
        finally:
            self.last = time.monotonic()
        if len(data) > limit:
            raise RuntimeError(f'Asset exceeds {limit} bytes: {url}')
        return data


def catalogue(client):
    parser = CatalogParser()
    parser.feed(client.read(CATALOG, 3 * 1024 * 1024).decode('utf-8'))
    if not parser.entries:
        raise RuntimeError('No Hangar entries found; site structure may have changed')
    return parser.entries


def select(entries, args):
    slugs = {item.strip() for item in args.slugs.split(',') if item.strip()}
    unknown = slugs - {entry['slug'] for entry in entries}
    if unknown:
        raise ValueError('Unknown Hangar slugs: ' + ', '.join(sorted(unknown)))
    roles = {item.strip() for item in args.roles.split(',') if item.strip()}
    chosen = [entry for entry in entries if entry['slug'] in slugs] if slugs else [
        entry for entry in entries if (args.era == 'all' or entry['era'] == args.era)
        and (args.roles == 'all' or entry['role'] in roles)
    ]
    return chosen[:args.limit] if args.limit else chosen


def detail(client, item):
    parser = HangarParser()
    html = client.read(item['hangar_url']).decode('utf-8')
    parser.feed(html)
    model = parser.download or parser.model
    profile = parser.profile
    if not profile:
        match = re.search(r'https://airmuseums\.co\.uk/models/aircraft/[a-z0-9-]+-profile\.webp', html)
        profile = match.group(0) if match else ''
    if not model or not profile:
        raise RuntimeError(f'Missing GLB or full-size profile at {item["hangar_url"]}')
    result = dict(item)
    heading = re.search(r'<h1[^>]*>(.*?)</h1>', html, flags=re.IGNORECASE | re.DOTALL)
    if heading:
        result['modelled_variant'] = unescape(re.sub(r'<[^>]+>', '', heading.group(1))).strip()
    result['model_url'] = safe_url(model, 'glb')
    result['profile_url'] = safe_url(profile, 'webp')
    result['rights'] = 'Download offered; reuse/redistribution licence not stated on the inspected page'
    return result


def acquire(client, item, out):
    folder = out / item['slug']
    folder.mkdir(parents=True, exist_ok=True)
    for key, suffix, magic in [('model_url', '.glb', b'glTF'), ('profile_url', '-profile.webp', b'RIFF')]:
        target = folder / (item['slug'] + suffix)
        valid = target.is_file()
        if valid:
            with target.open('rb') as existing:
                valid = existing.read(4) == magic
        if not valid:
            data = client.read(item[key])
            if not data.startswith(magic):
                raise RuntimeError(f'Unexpected file format: {item[key]}')
            temporary = target.with_suffix(target.suffix + '.part')
            temporary.write_bytes(data)
            temporary.replace(target)
        item[key.replace('_url', '_file')] = str(target.relative_to(out))
        item[key.replace('_url', '_sha256')] = hashlib.sha256(target.read_bytes()).hexdigest()
    return item


def gtx_prepare(host):
    subprocess.run(['ssh', '-o', 'BatchMode=yes', host,
                    r'if not exist C:\Users\tchou\combat-sprite-airmuseums\input mkdir C:\Users\tchou\combat-sprite-airmuseums\input'], check=True)
    subprocess.run(['ssh', '-o', 'BatchMode=yes', host,
                    r'if not exist C:\Users\tchou\combat-sprite-airmuseums\output mkdir C:\Users\tchou\combat-sprite-airmuseums\output'], check=True)
    subprocess.run(['scp', '-O', str(RENDERER), str(RUNNER), f'{host}:{REMOTE}/'], check=True)


def gtx_render(item, out, host):
    slug = item['slug']
    model = out / item['model_file']
    if not model.is_file():
        raise RuntimeError(f'Missing private GLB: {model}; run fetch first')
    subprocess.run(['scp', '-O', str(model), f'{host}:{REMOTE}/input/{slug}.glb'], check=True)
    subprocess.run(['ssh', '-o', 'BatchMode=yes', host,
                    rf'C:\Users\tchou\combat-sprite-airmuseums\run-view-render.cmd {slug}'], check=True)
    folder = out / slug
    views = ('top-bank-left', 'top', 'top-bank-right', 'left', 'right')
    for view in views:
        subprocess.run(['scp', '-O', f'{host}:{REMOTE}/output/{slug}-{view}.png',
                        str(folder / f'{slug}-{view}.png')], check=True)
    subprocess.run(['scp', '-O', f'{host}:{REMOTE}/output/{slug}-render.json',
                    str(folder / f'{slug}-render.json')], check=True)
    item['rendered_views'] = [f'{slug}/{slug}-{view}.png' for view in views]
    item['render_host'] = 'owner GTX PC'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['list', 'fetch', 'render'])
    parser.add_argument('--era', default='ww2', help='Catalogue era, e.g. ww2 or all')
    parser.add_argument('--roles', default='fighter,strike,bomber', help='Catalog roles or all')
    parser.add_argument('--slugs', default='', help='Comma-separated exact Hangar slugs')
    parser.add_argument('--limit', type=int, default=8, help='Maximum aircraft (0 = all)')
    parser.add_argument('--out', type=Path, default=DEFAULT_OUT)
    parser.add_argument('--catalog-json', action='store_true', help='With list, save every selected role/variant as private JSON')
    parser.add_argument('--delay', type=float, default=1.0, help='Minimum seconds between HTTP requests')
    parser.add_argument('--gtx-host', default='tchou@192.168.1.163')
    parser.add_argument('--continue-on-error', action='store_true', help='Keep processing a large batch and record failed slugs')
    parser.add_argument('--force', action='store_true', help='Regenerate existing GTX PNGs')
    args = parser.parse_args()
    if args.limit < 0 or args.delay < 0.5:
        parser.error('--limit must be >= 0 and --delay >= 0.5 seconds')
    client = Client(args.delay)
    chosen = select(catalogue(client), args)
    if not chosen:
        raise RuntimeError('No aircraft matched the selection')
    if args.command == 'list':
        if args.catalog_json:
            args.out.mkdir(parents=True, exist_ok=True)
            (args.out / 'catalog.json').write_text(json.dumps({
                'source': CATALOG, 'aircraft': chosen,
            }, indent=2) + '\n')
        for item in chosen:
            print(f"{item['slug']:45} {item['era']:5} {item['role']:12} {item['game_role']:16} {item['variant']}")
        print(f'{len(chosen)} aircraft listed; no assets downloaded')
        return
    args.out.mkdir(parents=True, exist_ok=True)
    manifest_path = args.out / 'manifest.json'
    previous = json.loads(manifest_path.read_text()) if manifest_path.exists() else {}
    manifest = {item['slug']: item for item in previous.get('aircraft', [])}
    errors = {item['slug']: item for item in previous.get('errors', [])}
    gtx_is_ready = False
    for entry in chosen:
        try:
            item = (dict(manifest[entry['slug']]) if args.command == 'render' and entry['slug'] in manifest
                    else detail(client, entry))
            if args.command == 'fetch':
                item = acquire(client, item, args.out)
                print(f"fetched {item['slug']} ({item['role']} -> {item['game_role']})", flush=True)
            else:
                views = ('top-bank-left', 'top', 'top-bank-right', 'left', 'right')
                present = all((args.out / item['slug'] / f"{item['slug']}-{view}.png").is_file() for view in views)
                if present and not args.force:
                    item['rendered_views'] = [f"{item['slug']}/{item['slug']}-{view}.png" for view in views]
                    item['render_host'] = 'owner GTX PC'
                    print(f"already rendered {item['slug']}", flush=True)
                else:
                    if not gtx_is_ready:
                        gtx_prepare(args.gtx_host)
                        gtx_is_ready = True
                    gtx_render(item, args.out, args.gtx_host)
                    print(f"GTX rendered {item['slug']}: top with left/right bank, side left/right", flush=True)
            manifest[item['slug']] = item
            errors.pop(item['slug'], None)
        except (OSError, ValueError, RuntimeError, subprocess.CalledProcessError) as error:
            if not args.continue_on_error:
                raise
            errors[entry['slug']] = {'slug': entry['slug'], 'command': args.command, 'message': str(error)}
            print(f"failed {entry['slug']}: {error}", file=sys.stderr, flush=True)
        temporary = manifest_path.with_suffix('.json.part')
        temporary.write_text(json.dumps({'source': CATALOG, 'aircraft': list(manifest.values()),
                                         'errors': list(errors.values())}, indent=2) + '\n')
        temporary.replace(manifest_path)
    print(f'Private manifest: {manifest_path}')
    if errors:
        print(f'{len(errors)} aircraft need retry; see manifest errors', file=sys.stderr)


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.CalledProcessError) as error:
        print(f'error: {error}', file=sys.stderr)
        sys.exit(1)
