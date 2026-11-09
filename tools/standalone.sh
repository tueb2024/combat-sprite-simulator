#!/usr/bin/env bash
# A game as one executable (bin/jumphp export:standalone), run in docker/standalone:
#   1. PHP's micro SAPI with JumPHP's extensions (StaticPHP + docker/standalone/registry), one per set of
#      extensions and registry (tools/standalone-key.sh): downloaded or built once, kept in /cache (~/.jumphp/standalone)
#   2. the game (games/<game>, its vendor/ with the engine) in a PHAR whose stub unpacks it once and boots it
#      (tools/standalone-stub.php)
#   3. micro + PHAR = games/<game>/dist/standalone/<game>-linux-x86_64 (or -windows-x86_64.exe)
# Environment: GAME, TARGET (linux-x86_64, windows-x86_64), JUMPHP_STANDALONE_BUILD=1 (build, never download). /workspace: the project; /cache: StaticPHP's downloads, builds and the micro binaries.
# Desktop apps (bin/jumphp app:export, docs/tauri.md) set, unset for games (nothing changes then):
#   JUMPHP_STANDALONE_EXTENSIONS  another list of extensions (tools/app-extensions.txt: FFI, no SDL): another micro
#   JUMPHP_STANDALONE_SPC         StaticPHP's work folder under /cache (default spc)
#   JUMPHP_STANDALONE_BOOT        the class whose boot($root)->run() starts it (default JumPHP\Kernel\Application)
#   JUMPHP_STANDALONE_NATIVE      a native library carried in the package, at native/<target>/<its name>
#   JUMPHP_STANDALONE_NATIVE_DIR  its folder instead of <target> (macOS: macos-universal, one library for both)
#   JUMPHP_STANDALONE_INI         php.ini lines injected in the executable (StaticPHP's micro ini section), plus
#                                 game.yaml php_ini: "a=1\nb=2" (the game's own settings)
set -euo pipefail
# config/project.yaml, else the older config/game.yaml (both read)
project_yaml() { if [ -f "$1/config/project.yaml" ]; then echo "$1/config/project.yaml"; else echo "$1/config/game.yaml"; fi; }

say() { printf '\033[1;36m==> %s\033[0m\n' "$*" >&2; }
die() { printf '\033[1;31m[x] %s\033[0m\n' "$*" >&2; exit 1; }

TARGET="${TARGET:-linux-x86_64}"
PHP_VERSION="${JUMPHP_STANDALONE_PHP:-8.5}"
EXTENSIONS="/workspace/${JUMPHP_STANDALONE_EXTENSIONS:-tools/standalone-extensions.txt}"
SPC="/cache/${JUMPHP_STANDALONE_SPC:-spc}"
BOOT="${JUMPHP_STANDALONE_BOOT:-JumPHP\\Kernel\\Application}"
GAME_DIR="/workspace/games/$GAME"; [ -d "$GAME_DIR" ] || GAME_DIR="/workspace/apps/$GAME"
OUT="$GAME_DIR/dist/standalone"
case "$TARGET" in
  linux-x86_64) FILE="$GAME-$TARGET" ;;
  windows-x86_64) FILE="$GAME-$TARGET.exe" ;;
  macos-arm64|macos-x86_64) FILE="$GAME-$TARGET" ;;   # desktop apps: bin/jumphp app:export --macos puts both in an .app
  *) die "unknown target $TARGET (linux-x86_64, windows-x86_64, macos-arm64, macos-x86_64)" ;;
esac

# 1. the micro (PHP + JumPHP's extensions) for this target: kept, else JumPHP's GitHub release of it, else built here
#    (Linux only: Windows' is built by .github/workflows/standalone.yml on a Windows machine)
key="$(cd /workspace && bash tools/standalone-key.sh | sha256sum | cut -c1-16)"
micro="/cache/micro-$TARGET-$key.sfx"
release="${JUMPHP_STANDALONE_RELEASES:-https://git.jumphp.net/jumphp/jumPhp/releases/download}/standalone-$key/micro-$TARGET.sfx"
if [ ! -s "$micro" ] && [ -z "${JUMPHP_STANDALONE_BUILD:-}" ]; then
  say "PHP for $TARGET: $release"
  curl -fsSL -A jumphp-standalone -o "$micro.part" "$release" && mv "$micro.part" "$micro" || rm -f "$micro.part"
fi
if [ ! -s "$micro" ] && [ "$TARGET" = linux-x86_64 ]; then
  # one StaticPHP build at a time in a work folder: two exports at once (the IDE and a terminal) would write the
  # same php-src and break each other; the second one waits, then finds the micro the first one made
  mkdir -p "$SPC" && exec 9>"$SPC.lock"
  flock -n 9 || { say "another build of PHP is running in ~/.jumphp/standalone/${JUMPHP_STANDALONE_SPC:-spc}: waiting for it"; flock 9; }
fi
if [ ! -s "$micro" ]; then
  [ "$TARGET" = linux-x86_64 ] || die "no PHP for $TARGET (standalone-$key) on JumPHP's GitHub releases: it is built on Windows / macOS by the workflow .github/workflows/standalone.yml (Actions > standalone > Run workflow), see docs/standalone.md"
  say "PHP $PHP_VERSION with $(cat "$EXTENSIONS"), built here (StaticPHP: ~15 min the first time, then kept)"
  for dir in downloads source buildroot pkgroot log; do mkdir -p "$SPC/$dir"; rm -rf "/opt/spc/$dir"; ln -s "$SPC/$dir" "/opt/spc/$dir"; done
  printf 'php-version: %s\nextensions: %s\nsapi:\n  - micro\n' "$PHP_VERSION" "$(cat "$EXTENSIONS")" > /opt/spc/craft.yml
  # docker/standalone/spc-target: Zig's target, any x86_64 (not "native": the building machine's processor) and glibc 2.17
  (cd /opt/spc && SPC_TARGET="$(cat /workspace/docker/standalone/spc-target)" SPC_REGISTRIES=/workspace/docker/standalone/registry/spc.registry.yml spc craft) \
    || die "StaticPHP failed: its logs are in ~/.jumphp/standalone/${JUMPHP_STANDALONE_SPC:-spc}/log"
  cp /opt/spc/buildroot/bin/micro.sfx "$micro"
fi

# 1b. the micro really holds what its key says. The key names a file (micro-<target>-<key>.sfx) but nothing else ties them: a micro copied or
#     renamed under another key (2026-11-06: the desktop apps' micro, without SDL, was copied under the games' key) silently made every
#     game and graphic app crash with "Call to undefined function SDL_Init()". Each extension of the list leaves a symbol in the binary.
for ext in $(tr ',' ' ' < "$EXTENSIONS"); do
  case "$ext" in
    sdl) marker=SDL_CreateWindow ;; sdl_image) marker=IMG_Load ;; sdl_mixer) marker=Mix_OpenAudio ;; sdl_ttf) marker=TTF_OpenFont ;;
    opengl) marker=glGenBuffers ;; openal) marker=alcOpenDevice ;; *) continue ;;
  esac
  if ! grep -aq -- "$marker" "$micro"; then
    rm -f "$micro"
    die "the PHP for $TARGET (standalone-$key) has no $ext ($marker is not in it): it was not built from tools/$(basename "$EXTENSIONS"), a file was copied or renamed under this key. It is removed from the cache: export again (Linux: JUMPHP_STANDALONE_BUILD=1 builds it here, ~15 min; Windows / macOS: workflow .github/workflows/standalone.yml). Never copy a micro from one key to another."
  fi
done

# 2. the game, as the desktop runs it (tools/stage-game.sh)
say "the game: $GAME"
stage=/tmp/stage/game
build="$(bash /workspace/tools/stage-game.sh "$GAME_DIR" "$stage")" || die "${GAME_DIR#/workspace/} has no vendor/ (bin/jumphp install $GAME)"
if [ -n "${JUMPHP_STANDALONE_NATIVE:-}" ]; then
  # the native library of a desktop app (Tauri), unpacked with it: JumPHP\Tauri\TauriRuntime finds it there
  native="$stage/native/${JUMPHP_STANDALONE_NATIVE_DIR:-$TARGET}"
  mkdir -p "$native"
  cp "/workspace/$JUMPHP_STANDALONE_NATIVE" "$native/"
  build="$(cd "$stage" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum | cut -c1-12)"
fi
id="$(sed -nE 's/^ *id: *["'\'']?([a-z0-9-]+).*/\1/p' "$(project_yaml "$GAME_DIR")" | head -1)"
id="${id:-$GAME}"

php -d phar.readonly=0 -r '
    [, $stage, $stub, $out, $id, $build, $boot] = $argv;
    @unlink($out);
    $phar = new Phar($out, 0, "jumphp-game.phar");
    $phar->startBuffering();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $phar->addFile($file->getPathname(), "game/" . substr($file->getPathname(), strlen($stage) + 1));
    }
    $phar->addEmptyDir("game/var");
    $phar->setStub(strtr(file_get_contents($stub), ["{{ID}}" => $id, "{{BUILD}}" => $build, "{{BOOT}}" => $boot]));
    $phar->stopBuffering();
    echo $phar->count(), " files\n";
' "$stage" /workspace/tools/standalone-stub.php /tmp/stage/game.phar "$id" "$build" "$BOOT" >&2

# 3. one file
mkdir -p "$OUT"
# php.ini lines for this executable: StaticPHP's micro reads them between itself and the PHAR (micro:combine's format)
# + game.yaml `php_ini: "a=1\nb=2"` (one line, \n between settings): the game's own (get_cfg_var() reads them)
ini=/tmp/stage/ini.part
: > "$ini"
INI_EXTRA="$(php -r 'if (preg_match("/^[ \t]*php_ini:[ \t]*\"((?:[^\"\\\\]|\\\\.)*)\"/m", (string)@file_get_contents($argv[1]), $m)) echo stripcslashes($m[1]);' "$(project_yaml "$GAME_DIR")")"
INI="${JUMPHP_STANDALONE_INI:-}${INI_EXTRA:+${JUMPHP_STANDALONE_INI:+
}$INI_EXTRA}"
if [ -n "$INI" ]; then
  INI="$INI" php -r '$ini = getenv("INI") . "\n"; echo "\xfd\xf6\x69\xe6", pack("N", strlen($ini)), $ini;' > "$ini"
fi
cat "$micro" "$ini" /tmp/stage/game.phar > "$OUT/$FILE"
chmod 755 "$OUT/$FILE"
# Windows: a GUI program (SUBSYSTEM:WINDOWS in its PE header, as editbin does), not a console one: no console window
# beside the game / app / installer. The programs it starts get no window either (registry/src/Patch/WindowsGui.php).
# Nothing moves in the file (two bytes). JUMPHP_STANDALONE_CONSOLE=1 keeps the console (a command-line tool).
if [ "$TARGET" = windows-x86_64 ] && [ -z "${JUMPHP_STANDALONE_CONSOLE:-}" ]; then
  php -r '
    $f = fopen($argv[1], "r+b");
    fseek($f, 0x3c); $pe = unpack("V", fread($f, 4))[1];
    fseek($f, $pe); if (fread($f, 4) !== "PE\0\0") { fwrite(STDERR, "not a PE executable\n"); exit(1); }
    fseek($f, $pe + 24 + 68); fwrite($f, pack("v", 2));    // optional header: Subsystem = IMAGE_SUBSYSTEM_WINDOWS_GUI
    fclose($f);
  ' "$OUT/$FILE" || die "could not make $FILE a GUI program"
fi
echo "OUT=$OUT/$FILE"
