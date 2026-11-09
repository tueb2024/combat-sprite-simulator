# shellcheck shell=bash
# Desktop apps (Tauri: native windows with HTML / CSS / JS pages, driven by PHP), sourced by bin/jumphp: the app:* and
# tauri:* commands. Nothing here is used by games: SDL games (desktop, browser, standalone, phones) work as before.
# docs/tauri.md.

TAURI_IMAGE="${JUMPHP_TAURI_IMAGE:-jumphp/tauri:dev}"
XDEBUG_TAURI_IMAGE="${JUMPHP_XDEBUG_TAURI_IMAGE:-jumphp/tauri-xdebug:dev}"
TAURI_HOME="${JUMPHP_HOME:-$HOME/.jumphp}/tauri"
TAURI_PLATFORM="linux-x86_64"
TAURI_LIB="build/tauri/$TAURI_PLATFORM/libjumphp_tauri.so"

ensure_tauri_image() {
  ensure_desktop_image
  docker image inspect "$TAURI_IMAGE" >/dev/null 2>&1 || cmd_tauri_setup
}

# tauri_run <workdir repo-relative> [docker flags...] -- <command...>: in the Tauri image (PHP + FFI, Rust, WebKitGTK)
tauri_run() {
  local wd="$1"; shift
  local flags=()
  while [ $# -gt 0 ] && [ "$1" != "--" ]; do flags+=("$1"); shift; done
  [ "${1:-}" = "--" ] && shift
  ensure_tauri_image
  local image="$TAURI_IMAGE"
  # JUMPHP_XDEBUG=<port>: the Tauri image with Xdebug (cargo builds left alone)
  if [ -n "${JUMPHP_XDEBUG:-}" ] && [[ " $* " != *" cargo "* ]]; then
    xdebug_image "$TAURI_IMAGE" "$XDEBUG_TAURI_IMAGE" docker/desktop-xdebug "the Tauri image"
    image="$XDEBUG_IMAGE_USED"
    flags+=("${XDEBUG_FLAGS[@]}")
    [[ " ${flags[*]-} " == *" --network host "* ]] || [ "$(uname -s)" != Linux ] || flags+=(--network host)
  fi
  mkdir -p "$TAURI_HOME/cargo" "$TAURI_HOME/target"
  docker run --rm $TTY_FLAGS --user "$(id -u):$(id -g)" \
    "${WS[@]}" -w "/workspace/$wd" \
    -v "$TAURI_HOME/cargo:/opt/jumphp-cargo" -v "$TAURI_HOME/target:/opt/jumphp-target" \
    -e HOME=/tmp -e XDG_RUNTIME_DIR=/tmp -e COMPOSER_HOME=/tmp/composer -e COMPOSER_CACHE_DIR=/workspace/.cache/composer \
    -e CARGO_HOME=/opt/jumphp-cargo -e CARGO_TARGET_DIR=/opt/jumphp-target \
    -e "JUMPHP_TAURI_LIB=/workspace/$TAURI_LIB" \
    ${flags[@]+"${flags[@]}"} "$image" "$@"
}

cmd_tauri_setup() {
  ensure_desktop_image
  say "Tauri image ($TAURI_IMAGE: the desktop runtime + FFI + Rust + WebKitGTK, first time ~5 min)"
  docker build -t "$TAURI_IMAGE" --build-arg "BASE=$DESKTOP_IMAGE" docker/tauri
}

# the runtime library (tauri/, Rust) -> build/tauri/linux-x86_64/libjumphp_tauri.so; ~5 min the first time, then seconds
cmd_tauri_build() {
  say "Tauri runtime: tauri/ -> $TAURI_LIB"
  local locked=""; [ -f "$ROOT/tauri/Cargo.lock" ] && locked="--locked"
  tauri_run tauri -- sh -c "cargo build --release $locked && mkdir -p /workspace/build/tauri/$TAURI_PLATFORM \
    && cp /opt/jumphp-target/release/libjumphp_tauri.so /workspace/$TAURI_LIB"
  say "done: $TAURI_LIB ($(du -h "$ROOT/$TAURI_LIB" | cut -f1))"
}

tauri_key() { (cd "$ROOT" && bash tools/tauri-key.sh | sha256sum | cut -c1-16); }

# the published libraries (release tauri-<key>, .github/workflows/tauri.yml): <platform> -> its asset name, its file
tauri_asset() {
  case "$1" in
    linux-x86_64) echo "libjumphp_tauri-linux-x86_64.so libjumphp_tauri.so" ;;
    windows-x86_64) echo "jumphp_tauri-windows-x86_64.dll jumphp_tauri.dll" ;;
    macos-universal) echo "libjumphp_tauri-macos-universal.dylib libjumphp_tauri.dylib" ;;
    *) return 1 ;;
  esac
}

# tauri:download [platform...]: the libraries of this version of tauri/ from JumPHP's GitHub releases, into
# build/tauri/<platform>/ (default: linux-x86_64; --all: Linux, Windows, macOS). Returns 1 when one is missing.
cmd_tauri_download() {
  local platforms=("$@") key status=0 p asset file url out
  [ ${#platforms[@]} -gt 0 ] || platforms=("$TAURI_PLATFORM")
  [ "${platforms[0]}" = "--all" ] && platforms=(linux-x86_64 windows-x86_64 macos-universal)
  key="$(tauri_key)"
  for p in "${platforms[@]}"; do
    asset=""; file=""
    read -r asset file <<<"$(tauri_asset "$p" || true)"
    [ -n "$asset" ] || die "unknown platform $p (linux-x86_64, windows-x86_64, macos-universal)"
    url="${JUMPHP_TAURI_RELEASES:-https://git.jumphp.net/jumphp/jumPhp/releases/download}/tauri-$key/$asset"
    out="$ROOT/build/tauri/$p/$file"
    mkdir -p "$(dirname "$out")"
    if curl -fsSL -A jumphp-tauri -o "$out.part" "$url"; then
      mv "$out.part" "$out"
      [ "$p" = linux-x86_64 ] || [ "$p" = macos-universal ] && chmod 755 "$out"
      touch "$out"
      echo "$key" > "$(dirname "$out")/.key"    # the sources it was built from (tauri_lib_current)
      say "tauri-$key: build/tauri/$p/$file"
    else
      rm -f "$out.part"
      warn "no $asset in the release tauri-$key (the workflow .github/workflows/tauri.yml publishes it)"
      status=1
    fi
  done
  return $status
}

# the Windows / macOS library (they can not be built here) of THESE sources of tauri/: downloaded again when it is
# missing or came from other sources (a library of older sources lacks functions TauriRuntime declares: the exported
# app does not start, "Failed resolving C function"); fails rather than packing a stale one
ensure_tauri_platform_lib() {
  local p="$1" asset file
  read -r asset file <<<"$(tauri_asset "$p")"
  if [ -f "$ROOT/build/tauri/$p/$file" ] && [ "$(cat "$ROOT/build/tauri/$p/.key" 2>/dev/null)" = "$(tauri_key)" ]; then return 0; fi
  cmd_tauri_download "$p" \
    || die "no $p library for these sources of tauri/ (tauri-$(tauri_key)): run the GitHub workflow tauri (Actions > tauri), then again"
}

# the library, downloaded (the release of these sources) or built, when missing or older than its sources
ensure_tauri_lib() {
  if [ ! -f "$ROOT/$TAURI_LIB" ] || [ -n "$(find "$ROOT/tauri/src" "$ROOT/tauri/Cargo.toml" "$ROOT/tauri/Cargo.lock" "$ROOT/tauri/tauri.conf.json" -newer "$ROOT/$TAURI_LIB" 2>/dev/null | head -1)" ]; then
    if [ -n "${JUMPHP_TAURI_BUILD:-}" ] || ! cmd_tauri_download "$TAURI_PLATFORM" 2>/dev/null; then
      cmd_tauri_build
    fi
  fi
}

app_dir() {
  local dir; dir="$(project_dir "${1:-}")"
  grep -qE '^[[:space:]]*ui:[[:space:]]*["'"'"']?webview' "$(project_yaml "$ROOT/$dir")" 2>/dev/null \
    || die "$dir is not a desktop app (config/project.yaml: ui: webview). A game: bin/jumphp run / test / shot"
  echo "$dir"
}

# docker flags for a window on this screen (X11, else Wayland), the session bus (notifications) and the GPU
app_display_flags() {
  local xdg="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
  if [ -n "${DISPLAY:-}" ] && [ "${JUMPHP_WAYLAND:-}" != 1 ]; then
    echo "-e DISPLAY=$DISPLAY -v /tmp/.X11-unix:/tmp/.X11-unix:ro -e GDK_BACKEND=x11"
    if [ -n "${XAUTHORITY:-}" ] && [ -f "$XAUTHORITY" ]; then
      echo "-v $XAUTHORITY:/tmp/.Xauthority:ro -e XAUTHORITY=/tmp/.Xauthority"
    elif [ -f "$HOME/.Xauthority" ]; then
      echo "-v $HOME/.Xauthority:/tmp/.Xauthority:ro -e XAUTHORITY=/tmp/.Xauthority"
    fi
  elif [ -n "${WAYLAND_DISPLAY:-}" ] && [ -S "$xdg/$WAYLAND_DISPLAY" ]; then
    echo "-e WAYLAND_DISPLAY=$WAYLAND_DISPLAY -v $xdg/$WAYLAND_DISPLAY:/tmp/$WAYLAND_DISPLAY -e GDK_BACKEND=wayland"
  else
    die "no display (DISPLAY / WAYLAND_DISPLAY). Headless: bin/jumphp app:test / app:shot"
  fi
  [ -S "$xdg/bus" ] && echo "-v $xdg/bus:/tmp/bus -e DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/bus"
  if [ -d /dev/dri ]; then
    echo "--device /dev/dri"
    local grp gid
    for grp in video render; do
      gid="$(getent group "$grp" | cut -d: -f3 || true)"
      [ -n "$gid" ] && echo "--group-add $gid"
    done
  fi
  return 0
}

cmd_app_new() {
  local name="${1:-}" title="${2:-}"
  [[ "$name" =~ ^[a-z][a-z0-9-]*$ ]] || die "app name: lower case letters, digits and dashes (e.g. notes)"
  [ -z "$(project_path "$name")" ] || die "$(project_path "$name") already exists"
  [ -n "$title" ] || title="$(echo "$name" | tr '-' ' ' | awk '{for (i = 1; i <= NF; i++) $i = toupper(substr($i, 1, 1)) substr($i, 2)} 1')"
  mkdir -p apps
  cp -r skeleton-app "apps/$name"
  php_run "apps/$name" -- php /workspace/tools/new-app.php "$name" "$title"
  say "apps/$name created. Next: bin/jumphp install $name && bin/jumphp app:run $name"
}

cmd_app_run() {
  local dir; dir="$(app_dir "${1:-}")"
  ensure_vendor "$dir"
  ensure_tauri_lib
  # shellcheck disable=SC2046
  tauri_run "$dir" --network host $(app_display_flags) -e "JUMPHP_DEBUG=${JUMPHP_DEBUG:-}" -- php app.php
}

cmd_app_test() {
  local dir; dir="$(app_dir "${1:-}")"
  ensure_vendor "$dir"
  [ -d "$ROOT/$dir/tests" ] && ensure_engine_tools
  live_announce
  say "$dir: php -l"
  php_run "$dir" -- sh -c 'find src app.php -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null'
  if [ -f "$ROOT/$dir/phpstan.neon" ] && [ -x "$ROOT/engine/vendor/bin/phpstan" ]; then
    say "$dir: phpstan"
    php_run "$dir" -- /workspace/engine/vendor/bin/phpstan analyse --no-progress --memory-limit=1G
  fi
  if [ -d "$ROOT/$dir/tests" ] && [ -x "$ROOT/engine/vendor/bin/phpunit" ]; then
    say "$dir: phpunit (AppTestCase: the app without a window)"
    php_run "$dir" -- /workspace/engine/vendor/bin/phpunit --bootstrap vendor/autoload.php --testdox tests
  fi
  ensure_tauri_lib
  say "$dir: the app for real on a virtual screen (${JUMPHP_MAX_FRAMES:-240} frames: the page must load, no PHP warning)"
  tauri_run "$dir" -e JUMPHP_SMOKE=1 -e "JUMPHP_MAX_FRAMES=${JUMPHP_MAX_FRAMES:-240}" -- jumphp-xvfb php app.php
  say "$dir: all checks passed"
}

cmd_app_shot() {
  local dir; dir="$(app_dir "${1:-}")"
  local frames="${2:-120}" out="${3:-var/screenshots/app.png}"
  ensure_vendor "$dir"
  ensure_tauri_lib
  tauri_run "$dir" -e "JUMPHP_SCREENSHOT=$out" -e "JUMPHP_MAX_FRAMES=$frames" -- jumphp-xvfb php app.php
  echo "$ROOT/$dir/$out"
}

# the app as one executable (StaticPHP's micro with FFI + the app + the Tauri library), through export:standalone
# with the apps' settings (docs/tauri.md). Linux: the player needs WebKitGTK 4.1 (as every Tauri app); Windows:
# WebView2 (in Windows 10 / 11). Windows' micro and library come from JumPHP's GitHub releases.
cmd_app_export() {
  local app="${1:-}"; local dir; dir="$(app_dir "$app")"; shift || true
  local targets=()
  while [ $# -gt 0 ]; do
    case "$1" in
      --linux) targets+=(linux) ;;
      --windows) targets+=(windows) ;;
      --macos) targets+=(macos) ;;
      *) die "unknown option $1 (--linux, --windows, --macos)" ;;
    esac
    shift
  done
  [ ${#targets[@]} -gt 0 ] || targets=(linux)
  ensure_vendor "$dir"
  local t lib
  for t in "${targets[@]}"; do
    if [ "$t" = macos ]; then
      app_export_macos "$app" "$dir"
      continue
    fi
    if [ "$t" = linux ]; then
      ensure_tauri_lib
      lib="$TAURI_LIB"
    else
      lib="build/tauri/windows-x86_64/jumphp_tauri.dll"
      ensure_tauri_platform_lib windows-x86_64
    fi
    JUMPHP_STANDALONE_EXTENSIONS=tools/app-extensions.txt JUMPHP_STANDALONE_SPC=spc-app \
    JUMPHP_STANDALONE_BOOT='JumPHP\Tauri\App' JUMPHP_STANDALONE_NATIVE="$lib" \
    JUMPHP_STANDALONE_INI=$'ffi.enable=1\nmemory_limit=512M' \
      cmd_export_standalone "$app" "--$t"
  done
  echo "  A desktop app. Linux: the computer needs WebKitGTK 4.1 (Debian 12+, Ubuntu 22.04+, Fedora 39+: libwebkit2gtk-4.1)." >&2
  echo "  Windows: WebView2 (part of Windows 10 / 11)." >&2
  echo "  macOS: 12 or newer; not signed: the first time, right click on the app > Open (docs/tauri.md)." >&2
}

# app:export --macos: <app>-macos.zip holding <Title>.app (Apple silicon + Intel executables, the universal library)
app_export_macos() {
  local app="$1" dir="$2" lib="build/tauri/macos-universal/libjumphp_tauri.dylib" arch
  ensure_tauri_platform_lib macos-universal
  for arch in arm64 x86_64; do
    JUMPHP_STANDALONE_EXTENSIONS=tools/app-extensions.txt JUMPHP_STANDALONE_SPC=spc-app \
    JUMPHP_STANDALONE_BOOT='JumPHP\Tauri\App' JUMPHP_STANDALONE_NATIVE="$lib" JUMPHP_STANDALONE_NATIVE_DIR=macos-universal \
    JUMPHP_STANDALONE_INI=$'ffi.enable=1\nmemory_limit=512M' \
      cmd_export_standalone "$app" "--macos-$arch"
  done
  local out="$(project_dir "$app")/dist/standalone"
  docker run --rm --user "$(id -u):$(id -g)" "${WS[@]}" -w /workspace "$DESKTOP_IMAGE" \
    php tools/macos-app.php "$(project_dir "$app")" "$out/$app-macos-arm64" "$out/$app-macos-x86_64" "$out/$app-macos.zip" >/dev/null \
    || die "the .app was not made (see above)"
  # the two executables stay beside it: app:release offers them as updates of the .app (each Mac replaces its own)
  say "done: $out/$app-macos.zip ($(du -h "$ROOT/$out/$app-macos.zip" | cut -f1)): the .app inside, Apple silicon + Intel"
}

# app:release <app> --url <base> [--notes "..."] [--linux] [--windows] [--macos]: app:export, then update.json signed
# with the app's key (~/.jumphp/keys/<id>-update.pem, made the first time; its public half: config/update.pub).
# Upload dist/standalone/ (the executables + update.json) to <base>; game.yaml app.update.url = <base>/update.json.
cmd_app_release() {
  local app="${1:-}"; local dir; dir="$(app_dir "$app")"; shift || true
  local base="" notes="" flags=()
  while [ $# -gt 0 ]; do
    case "$1" in
      --url) base="${2:-}"; shift ;;
      --notes) notes="${2:-}"; shift ;;
      --linux|--windows|--macos) flags+=("$1") ;;
      *) die "unknown option $1 (--url <base>, --notes <text>, --linux, --windows, --macos)" ;;
    esac
    shift
  done
  [ -n "$base" ] || die "where the files will be: bin/jumphp app:release $app --url https://example.com/$app"
  local keys="${JUMPHP_HOME:-$HOME/.jumphp}/keys" id
  id="$(sed -nE 's/^ *id: *["'"'"']?([a-z0-9-]+).*/\1/p' "$(project_yaml "$ROOT/$dir")" | head -1)"
  id="${id:-$app}"
  mkdir -p "$keys"; chmod 700 "$keys"
  # the public key first (the executables carry it: a first run of the tool makes the key, then stops for want
  # of executables), then the executables, then the signed list of them
  if [ ! -f "$ROOT/$dir/config/update.pub" ]; then
    mkdir -p "$ROOT/$dir/dist/standalone"
    docker run --rm --user "$(id -u):$(id -g)" "${WS[@]}" -v "$keys:/keys" -w /workspace "$DESKTOP_IMAGE" \
      php tools/app-release.php "$dir" "/keys/$id-update.pem" "$base" "$notes" >/dev/null 2>&1 || true
    [ -f "$ROOT/$dir/config/update.pub" ] || die "no key made (see tools/app-release.php)"
  fi
  cmd_app_export "$app" ${flags[@]+"${flags[@]}"}
  # only what was just exported (an older executable left in dist/ is not offered)
  local platforms="" f
  [ ${#flags[@]} -gt 0 ] || flags=(--linux)
  for f in "${flags[@]}"; do
    case "$f" in
      --linux) platforms+=",linux-x86_64" ;;
      --windows) platforms+=",windows-x86_64" ;;
      --macos) platforms+=",macos-arm64,macos-x86_64" ;;
    esac
  done
  docker run --rm --user "$(id -u):$(id -g)" "${WS[@]}" -v "$keys:/keys" -w /workspace "$DESKTOP_IMAGE" \
    php tools/app-release.php "$dir" "/keys/$id-update.pem" "$base" "$notes" "${platforms#,}" || die "update.json not written"
  say "done: $dir/dist/standalone/update.json: upload dist/standalone/ to $base"
  echo "  The app checks it when project.yaml has  app: {update: {url: $base/update.json}}  (\$app->updater()->check())." >&2
  echo "  The signing key: $keys/$id-update.pem: keep a copy (without it, the apps already given can not be updated)." >&2
}

# run / test / shot of a desktop app's folder: the app:* commands (a game's folder: nothing changes)
is_app() {
  [ -n "${1:-}" ] && grep -qE '^[[:space:]]*ui:[[:space:]]*["'"'"']?webview' "$(project_yaml "$ROOT/$(project_path "$1")")" 2>/dev/null
}

# a game with ui: hybrid (SDL + Tauri: native dialogs, tray, HTML windows; docs/tauri.md "Hybrid games")
is_hybrid() {
  [ -n "${1:-}" ] && grep -qE '^[[:space:]]*ui:[[:space:]]*["'"'"']?hybrid' "$(project_yaml "$ROOT/$(project_path "$1")")" 2>/dev/null
}

# run_game_hybrid <dir> <docker flags...>: what bin/jumphp run does for a hybrid game (its flags + the Tauri image)
run_game_hybrid() {
  local dir="$1"; shift
  ensure_tauri_lib
  local gdk=x11
  case " $* " in *SDL_VIDEODRIVER=wayland*) gdk=wayland ;; esac
  local xdg="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}" bus=()
  [ -S "$xdg/bus" ] && bus=(-v "$xdg/bus:/tmp/bus" -e DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/bus)
  tauri_run "$dir" "$@" -e "GDK_BACKEND=$gdk" ${bus[@]+"${bus[@]}"} -- php app.php
}

# export:standalone of a hybrid game: the games' micro + FFI, the Tauri library inside
hybrid_export_env() {
  ensure_tauri_lib
  export JUMPHP_STANDALONE_EXTENSIONS=tools/hybrid-extensions.txt JUMPHP_STANDALONE_SPC=spc-hybrid \
    JUMPHP_STANDALONE_NATIVE="$TAURI_LIB" JUMPHP_STANDALONE_INI=$'ffi.enable=1'
}

# The JumPHP IDE (docs/ide.md) on this computer, natively: the exported executable (built when missing or older than
# ide/ and games/ide/), started outside Docker so it has the user's git and Docker. `ide --install`: also a launcher in
# the desktop's menu (~/.local/share/applications) and the command jumphp-ide (~/.local/bin).
cmd_claude_connect() {
  # the web IDE (jumphp.net/ide) uses the Claude Code of this computer: the JumPHP IDE with "Claude for jumphp.net"
  # on (it signs in through the browser the first time: allow it there), answering while it is open
  command -v claude >/dev/null || [ -x "$HOME/.local/bin/claude" ] || die "Claude Code is not installed on this computer: https://claude.com/claude-code"
  say "Claude for jumphp.net: the JumPHP IDE answers jumphp.net/ide with this computer's Claude while it is open"
  JUMPHP_IDE_BRIDGE=1 cmd_ide "$@"
}

cmd_ide() {
  # The IDE runs natively, without Docker: the executable (built once, or with --build) is only its runtime (PHP,
  # Tauri); the IDE's sources of this checkout are copied into the folder it unpacked itself to, so a git pull or a
  # change of the IDE never asks for a new build (which takes a while, in Docker)
  local exe="games/ide/dist/standalone/ide-linux-x86_64" build=""
  if [ "${1:-}" = "--build" ]; then build=1; shift; fi
  if [ -n "$build" ] || [ ! -f "$ROOT/$exe" ]; then
    say "JumPHP IDE: building the executable ($exe), once (Docker, several minutes)"
    cmd_app_export ide
  fi
  if [ "${1:-}" = "--install" ]; then
    local bin="$HOME/.local/bin" apps="$HOME/.local/share/applications" icons="$HOME/.local/share/icons/hicolor/256x256/apps"
    mkdir -p "$bin" "$apps" "$icons"
    # the launcher goes through bin/jumphp ide: the sources of this checkout, always the current ones
    printf '#!/bin/sh\nexec %q ide "$@"\n' "$ROOT/bin/jumphp" > "$bin/jumphp-ide"
    chmod 755 "$bin/jumphp-ide"
    cp "$ROOT/games/ide/assets/icon.png" "$icons/jumphp-ide.png"
    cat > "$apps/jumphp-ide.desktop" <<DESKTOP
[Desktop Entry]
Type=Application
Name=JumPHP IDE
Comment=Edit your JumPHP games and apps
Exec=$bin/jumphp-ide
Icon=jumphp-ide
Terminal=false
Categories=Development;IDE;
StartupWMClass=jumphp-ide
DESKTOP
    say "installed: $bin/jumphp-ide and JumPHP IDE in the applications menu"
    return
  fi
  local cache="${XDG_CACHE_HOME:-$HOME/.cache}/jumphp" root
  root="$(ls -dt "$cache"/ide-*/ 2>/dev/null | head -1)"
  if [ -n "$root" ] && [ -f "$root/.unpacked" ] && command -v rsync >/dev/null; then
    root="${root%/}"
    rsync -a --delete "$ROOT/ide/src/" "$root/vendor/jumphp/ide/src/"
    rsync -a --delete --exclude=/vendor "$ROOT/ide/ui/" "$root/vendor/jumphp/ide/ui/"
    rsync -a --delete "$ROOT/engine/src/" "$root/vendor/jumphp/engine/src/"
    rsync -a --delete "$ROOT/games/ide/src/" "$root/src/"
    rsync -a --delete "$ROOT/games/ide/config/" "$root/config/"
    # the folders older builds unpacked to
    find "$cache" -maxdepth 1 -name 'ide-*' -type d ! -path "$root" -mtime +7 -exec rm -rf {} + 2>/dev/null || true
  fi
  say "JumPHP IDE"
  exec "$ROOT/$exe" "$@"
}
