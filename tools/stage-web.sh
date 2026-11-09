#!/bin/sh
# Browser build, step 1 (runs in the desktop image, called by bin/jumphp build): a clean copy of the game in
# .build/<game> (without the films of assets/videos/: their pictures are there) with production dependencies (engine copied, not symlinked), the precompiled service
# container, and the web page (index.html, game.json) in .build/<game>.web.
# Step 2 is tools/package-web.sh (emscripten image).
set -eu
# config/project.yaml, else the older config/game.yaml (both read)
project_yaml() { if [ -f "$1/config/project.yaml" ]; then echo "$1/config/project.yaml"; else echo "$1/config/game.yaml"; fi; }
GAME_DIR="$1"                       # /workspace/games/<game>
GAME_ID="$(basename "$GAME_DIR")"
STAGE="/workspace/.build/$GAME_ID"  # two levels under /workspace, like games/<game>: ../../engine still resolves
WEB="$STAGE.web"

rm -rf "$STAGE" "$WEB"
mkdir -p "$STAGE" "$WEB"
tar -C "$GAME_DIR" \
    --exclude=./vendor --exclude=./dist --exclude=./.build --exclude=./var --exclude=./cache \
    --exclude=./tests --exclude=./site --exclude=./phpstan.neon --exclude='./core' --exclude='./core.[0-9]*' --exclude='./.git*' \
    --exclude='./assets/videos/*.mp4' --exclude='./assets/videos/*.webm' --exclude='./assets/videos/*.mov' --exclude='./assets/videos/*.mkv' --exclude='./assets/videos/*.avi' --exclude='./assets/videos/*.m4v' -cf - . | tar -C "$STAGE" -xf -
mkdir -p "$STAGE/var"

cd "$STAGE"
# path repositories copied instead of symlinked: the browser gets real files
COMPOSER_MIRROR_PATH_REPOS=1 composer install --no-dev --optimize-autoloader --classmap-authoritative \
    --no-interaction --no-progress --ignore-platform-reqs 2>&1 | grep -vE '^\s*$|funding' || true
[ -f vendor/autoload.php ] || { echo "composer install failed" >&2; exit 1; }
# the engine copy brings its own dev files: not needed in the game
rm -rf vendor/jumphp/engine/vendor vendor/jumphp/engine/tests vendor/jumphp/engine/web vendor/jumphp/engine/stubs \
       vendor/jumphp/engine/composer.lock vendor/jumphp/engine/phpstan.neon vendor/jumphp/engine/phpunit.xml*

# desktop apps (ui: webview) have no service container
grep -qE '^[[:space:]]*ui:[[:space:]]*["'"'"']?webview' "$(project_yaml "$STAGE")" || php /workspace/tools/dump-container.php "$STAGE"
php /workspace/tools/render-web.php "$STAGE" "$WEB" /workspace/engine/web /workspace/build/engine
echo "staged: $STAGE"
