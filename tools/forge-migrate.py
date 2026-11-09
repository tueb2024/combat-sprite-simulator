#!/usr/bin/env python3
"""
Moves a JumPHP project's references from GitHub (davidnurdin/...) to git.jumphp.net: JumPHP's own repositories and packages in
composer.json / composer.lock / Dockerfiles / scripts / docs, and the remotes. Third-party GitHub addresses are never touched.
  python3 tools/forge-migrate.py [--root <project>] [--owner <github-owner> --forge-owner <forge-owner> --repos a,b,c] [--dry-run]
Run by `bin/jumphp forge:migrate`.
"""
import argparse, json, os, re, subprocess, sys

FORGE = 'https://git.jumphp.net'
JUMPHP_REPOS = 'jumPhp|jumphp2installer|dsk2jumphp|jumphp-playground|php-wasm-sdl|php-wasm-network|php-sdl|jumphp-[a-z0-9-]+'

def rules(owner, forge_owner, repos):
    r = repos or JUMPHP_REPOS
    return [
        (r'https://api\.github\.com/repos/%s/(%s)/zipball/([0-9a-f]{40})' % (owner, r), lambda m: f'{FORGE}/{forge_owner}/{m[1]}/archive/{m[2]}.zip'),
        (r'https://raw\.githubusercontent\.com/%s/(%s)/(?:HEAD|main)/' % (owner, r), lambda m: f'{FORGE}/{forge_owner}/{m[1]}/raw/branch/main/'),
        (r'https://github\.com/%s/(%s)/(?:blob|tree)/main/' % (owner, r), lambda m: f'{FORGE}/{forge_owner}/{m[1]}/src/branch/main/'),
        (r'https://github\.com/%s/(%s)/releases/latest/download/' % (owner, r), lambda m: f'{FORGE}/{forge_owner}/{m[1]}/releases/download/latest/'),
        (r'https://github\.com/%s/(%s)(?![A-Za-z0-9_-])' % (owner, r), lambda m: f'{FORGE}/{forge_owner}/{m[1]}'),
        (r'git@github\.com:%s/(%s)\.git' % (owner, r), lambda m: f'{FORGE}/{forge_owner}/{m[1]}.git'),
        (r'(?<![\w/.-])%s/(php-sdl|php-wasm-network)(?=\s+"\$)' % owner, lambda m: f'git.jumphp.net/{forge_owner}/{m[1]}'),
    ]

def main():
    p = argparse.ArgumentParser()
    p.add_argument('--root', default='.')
    p.add_argument('--owner', default='davidnurdin')
    p.add_argument('--forge-owner', default='jumphp')
    p.add_argument('--repos', default='')
    p.add_argument('--dry-run', action='store_true')
    a = p.parse_args()
    root = os.path.abspath(a.root)
    files = subprocess.check_output(['git', '-C', root, 'ls-files'], text=True).split('\n')
    skip = re.compile(r'(^vendor/|\.(png|jpe?g|gif|ico|woff2?|wasm|zip|gz|pem)$)')
    rs = [(re.compile(x), f) for x, f in rules(a.owner, a.forge_owner, a.repos)]
    changed = []
    for f in files:
        path = os.path.join(root, f)
        if not f or skip.search(f) or not os.path.isfile(path):
            continue
        try:
            s = open(path, encoding='utf-8').read()
        except Exception:
            continue
        o = s
        for rx, fn in rs:
            s = rx.sub(fn, s)
        if s != o:
            if f.endswith('composer.lock'):
                json.loads(s)
            changed.append(f)
            if not a.dry_run:
                open(path, 'w', encoding='utf-8').write(s)
    print(f'{len(changed)} file(s) {"would change" if a.dry_run else "changed"}')
    for f in changed[:40]:
        print('  ', f)
    return 0

sys.exit(main())
