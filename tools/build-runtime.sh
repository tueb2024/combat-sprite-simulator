#!/bin/sh
# The shared engine of remote games (bin/jumphp runtime:build, docs/remote.md), in build/runtime/: the
# WebAssembly engine (build/engine, without any game inside), the page scripts, the page template (page.html)
# and jumphp-vendor.pack: the engine's PHP code with its Composer dependencies (Composer's autoloader renamed
# autoload_engine.php: jumphp-files.js adds the game's namespaces). Runs in the desktop image.
set -eu
OUT=/workspace/build/runtime
STAGE=/workspace/.build/runtime     # two levels under /workspace, like games/<game>: ../../engine resolves
ENGINE=/workspace/build/engine

rm -rf "$OUT" "$STAGE"
mkdir -p "$OUT" "$STAGE/src"
cat > "$STAGE/composer.json" <<'JSON'
{
    "name": "jumphp/runtime",
    "type": "project",
    "require": { "php": ">=8.2", "jumphp/engine": "@dev" },
    "repositories": [
        { "type": "path", "url": "../../engine" },
        { "type": "vcs", "url": "https://git.jumphp.net/jumphp/php-wasm-network" }
    ],
    "minimum-stability": "dev",
    "prefer-stable": true,
    "autoload": { "psr-4": { "App\\": "src/" } },
    "config": { "platform-check": false }
}
JSON
cd "$STAGE"
COMPOSER_MIRROR_PATH_REPOS=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    --ignore-platform-reqs 2>&1 | grep -vE '^\s*$|funding' || true
[ -f vendor/autoload.php ] || { echo "composer install failed" >&2; exit 1; }
rm -rf vendor/jumphp/engine/vendor vendor/jumphp/engine/tests vendor/jumphp/engine/web vendor/jumphp/engine/stubs \
       vendor/jumphp/engine/composer.lock vendor/jumphp/engine/phpstan.neon vendor/jumphp/engine/phpunit.xml* vendor/bin
mv vendor/autoload.php vendor/autoload_engine.php
php /workspace/tools/pack.php vendor "$OUT/jumphp-vendor.pack"

cp "$ENGINE/php-web.mjs" "$ENGINE/php-web.wasm" "$ENGINE/php-wasm-network.js" "$ENGINE/engine.json" "$OUT/"
for f in /workspace/engine/web/*.js; do
    [ "$(basename "$f")" = sw.js ] || cp "$f" "$OUT/"
done
[ -f /workspace/build/ssh/ssh.wasm ] && cp /workspace/build/ssh/ssh.js /workspace/build/ssh/ssh.wasm "$OUT/"
cp /workspace/engine/web/index.html "$OUT/page.html"

cd "$OUT"
hash() { sha256sum "$1" | cut -c1-12; }
{
    printf '{\n  "build": "%s"' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    for f in *.js *.mjs *.wasm *.pack page.html; do printf ',\n  "%s": "%s"' "$f" "$(hash "$f")"; done
    printf '\n}\n'
} > version.json
php -r '$e = json_decode(file_get_contents("engine.json"), true) ?: [];
    echo json_encode(["version" => trim((string)@file_get_contents("/workspace/VERSION")) ?: "dev", "php" => $e["php"] ?? null,
        "wasm_size" => filesize("php-web.wasm"), "build" => gmdate("c")], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";' > runtime.json
rm -rf "$STAGE"
ls -la "$OUT"
