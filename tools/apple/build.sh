#!/usr/bin/env bash
# A game as a native Apple app, macOS and iPhone / iPad (bin/jumphp apple <game>; the GitHub workflow apple.yml).
# Runs on a Mac with Xcode (GitHub's macOS runners), no Docker. No browser, no WebView:
#   1. the sources: PHP, SDL2 + SDL2_image / _mixer / _ttf, OpenAL Soft and the PHP extensions, at the versions of
#      the standalone registry (docker/standalone/registry/config), as the Android app; kept in $CACHE
#   2. PHP 8.5 per target (macOS arm64 and x86_64, iPhone arm64, the iOS simulator arm64), built with Xcode's clang:
#      the embed SAPI as a static library, no JIT (Apple forbids it on iOS), JumPHP's extensions inside (their
#      config.m4: tools/android/ext, the same for every platform), kept in $CACHE per (sources, flags)
#   3. the app per target: tools/apple/project (CMake: SDL2, its add-ons, OpenAL Soft on CoreAudio, main.c with PHP),
#      then the bundle: the game in it (tools/stage-game.sh), the icon, Info.plist; macOS: one executable for Apple
#      silicon and Intel (lipo)
#   4. games/<game>/dist/apple/: <game>-<version>-macos.zip and .dmg (a Mac), <game>-<version>-ios.ipa (an iPhone /
#      iPad: to sign with an Apple developer account), <game>-<version>-ios-simulator.zip (Xcode's simulator)
# Without an Apple signing identity the apps are signed "ad hoc": the macOS app opens after a right click > Open (or
# xattr -dr com.apple.quarantine), the .ipa is signed for a device with an Apple account (docs/apple.md).
# A desktop app (ui: webview, AGENTS.md §16) is not built this way: its iPhone / iPad app is a WKWebView on its browser
# build (dist/web, made by bin/jumphp build before), tools/apple/webview/main.m; no PHP to build (PHP is the
# WebAssembly of the page). Its Mac app is Tauri's (bin/jumphp app:export --macos).
# Environment: GAME, PLATFORMS (default "macos ios"), BUNDLE_ID (default io.github.jumphp.<game>), VERSION (1.0),
# BUILD (1), JUMPHP_APPLE_CACHE (default ~/.jumphp/apple). Needs: Xcode, cmake, autoconf, php (8.2+, with gd) on PATH.
set -euo pipefail

say() { printf '\033[1;36m==> %s\033[0m\n' "$*" >&2; }
die() { printf '\033[1;31m[x] %s\033[0m\n' "$*" >&2; exit 1; }

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
HERE="$ROOT/tools/apple"
REGISTRY="$ROOT/docker/standalone/registry"
CACHE="${JUMPHP_APPLE_CACHE:-$HOME/.jumphp/apple}"
SRC="$CACHE/src"
PHP_VERSION=8.5.12
LIBPNG_VERSION=1.6.59 # as StaticPHP's (GD's PNG)
OPENSSL_VERSION=3.6.5 # as StaticPHP's (HTTPS: https:// and wss://)
MESA_VERSION=22.3.6   # the GL headers PHP-OpenGL includes (macOS has no GL/gl.h), as the Android build's (Debian's)
MACOS_MIN=11.0
IOS_MIN=14.0
JOBS="$(sysctl -n hw.ncpu 2>/dev/null || echo 4)"
[ "$(uname)" = Darwin ] || die "the Apple apps are built on a Mac (GitHub's macOS runners: bin/jumphp apple <game>)"
command -v xcrun >/dev/null || die "Xcode is needed (xcode-select --install is not enough: the full Xcode, for the iOS SDK)"
for tool in cmake autoconf php; do command -v "$tool" >/dev/null || die "$tool is needed (brew install $tool)"; done
mkdir -p "$CACHE/downloads" "$SRC"

sha() { shasum -a 256 "$@"; }

# --- the targets ----------------------------------------------------------------------------------------------

# target <name>: SDK, TRIPLE (clang's --target), HOST (PHP's --host), OSSL (OpenSSL's target), CMAKE_ARGS
target() {
  case "$1" in
    macos-arm64) SDK=macosx TRIPLE="arm64-apple-macos$MACOS_MIN" HOST=aarch64-apple-darwin OSSL=darwin64-arm64-cc
      CMAKE_ARGS="-DCMAKE_OSX_ARCHITECTURES=arm64 -DCMAKE_OSX_DEPLOYMENT_TARGET=$MACOS_MIN" ;;
    macos-x86_64) SDK=macosx TRIPLE="x86_64-apple-macos$MACOS_MIN" HOST=x86_64-apple-darwin OSSL=darwin64-x86_64-cc
      CMAKE_ARGS="-DCMAKE_OSX_ARCHITECTURES=x86_64 -DCMAKE_OSX_DEPLOYMENT_TARGET=$MACOS_MIN" ;;
    ios-arm64) SDK=iphoneos TRIPLE="arm64-apple-ios$IOS_MIN" HOST=aarch64-apple-darwin OSSL=ios64-xcrun
      CMAKE_ARGS="-DCMAKE_SYSTEM_NAME=iOS -DCMAKE_OSX_SYSROOT=iphoneos -DCMAKE_OSX_ARCHITECTURES=arm64 -DCMAKE_OSX_DEPLOYMENT_TARGET=$IOS_MIN" ;;
    iossim-arm64) SDK=iphonesimulator TRIPLE="arm64-apple-ios$IOS_MIN-simulator" HOST=aarch64-apple-darwin OSSL=iossimulator-arm64-xcrun
      CMAKE_ARGS="-DCMAKE_SYSTEM_NAME=iOS -DCMAKE_OSX_SYSROOT=iphonesimulator -DCMAKE_OSX_ARCHITECTURES=arm64 -DCMAKE_OSX_DEPLOYMENT_TARGET=$IOS_MIN" ;;
    *) die "unknown target $1" ;;
  esac
  SDKROOT="$(xcrun --sdk "$SDK" --show-sdk-path)"
  export SDKROOT
}

# --- 1. sources -----------------------------------------------------------------------------------------------

# the URL of a package of the standalone registry (one source of truth for the versions)
registry_url() {
  php -r '
    foreach (glob($argv[1] . "/config/*.yml") as $f) {
        $y = file_get_contents($f);
        if (preg_match("/^" . preg_quote($argv[2], "/") . ":\n(?:  .*\n|\n)*?\s+url: .(https:[^\x27]+)/m", $y, $m)) {
            echo $m[1];
            exit;
        }
    }
    exit(1);' "$REGISTRY" "$1"
}

# fetch <name> <url>: unpacked once in $SRC/<name>
fetch() {
  local name="$1" url="$2" file
  [ -d "$SRC/$name" ] && return 0
  file="$CACHE/downloads/$(basename "$url")"
  [ -s "$file" ] || { say "download $url"; curl -fsSL -A jumphp-apple -o "$file.part" "$url" && mv "$file.part" "$file"; }
  rm -rf "$SRC/$name.tmp" && mkdir -p "$SRC/$name.tmp"
  tar -xf "$file" --strip-components 1 -C "$SRC/$name.tmp"
  mv "$SRC/$name.tmp" "$SRC/$name"
}

sources() {
  fetch php "https://www.php.net/distributions/php-$PHP_VERSION.tar.xz"
  fetch libpng "https://github.com/pnggroup/libpng/archive/refs/tags/v$LIBPNG_VERSION.tar.gz"
  fetch openssl "https://github.com/openssl/openssl/releases/download/openssl-$OPENSSL_VERSION/openssl-$OPENSSL_VERSION.tar.gz"
  local lib ext
  for lib in sdl2 sdl2_image sdl2_mixer sdl2_ttf openal; do fetch "$lib" "$(registry_url "$lib")"; done
  for ext in sdl sdl_image sdl_mixer sdl_ttf opengl openal; do fetch "ext-$ext" "$(registry_url "ext-$ext")"; done

  # the headers the extensions include: <SDL2/SDL.h> (php-sdl on Apple), <SDL2/SDL_image.h>, <AL/al.h>, <GL/gl.h>
  local inc="$CACHE/include" f
  if [ ! -f "$inc/.done" ]; then
    rm -rf "$inc" && mkdir -p "$inc/SDL2" "$inc/AL" "$inc/GL" "$inc/KHR"
    cp "$SRC"/sdl2/include/*.h "$inc/SDL2/"
    cp "$SRC/sdl2_image/include/SDL_image.h" "$SRC/sdl2_mixer/include/SDL_mixer.h" "$SRC/sdl2_ttf/SDL_ttf.h" "$inc/SDL2/"
    cp "$SRC"/openal/include/AL/*.h "$inc/AL/"
    for f in GL/gl.h GL/glext.h KHR/khrplatform.h; do
      curl -fsSL -A jumphp-apple -o "$inc/$f" "https://gitlab.freedesktop.org/mesa/mesa/-/raw/mesa-$MESA_VERSION/include/$f"
    done
    touch "$inc/.done"
  fi
}

# --- 2. PHP ---------------------------------------------------------------------------------------------------

# php-src with JumPHP's extensions in ext/ (their config.m4, the patches of the standalone registry)
php_tree() {
  local tree="$CACHE/php-tree" ext
  [ -f "$tree/.done" ] && return 0
  say "PHP $PHP_VERSION + JumPHP's extensions"
  rm -rf "$tree" && cp -R "$SRC/php" "$tree"
  for ext in sdl sdl_image sdl_mixer sdl_ttf opengl openal; do
    rm -rf "$tree/ext/$ext" && cp -R "$SRC/ext-$ext" "$tree/ext/$ext"
    rm -f "$tree/ext/$ext/config.w32"
    cp "$ROOT/tools/android/ext/$ext.m4" "$tree/ext/$ext/config.m4"
  done
  # php-sdl: PHP 8.5 (zend_ce_exception), no SDLCALL before a call, its header in src/ (registry/src/Extension/sdl.php)
  perl -pi -e 's/zend_exception_get_default\(\)/zend_ce_exception/g; s/= SDLCALL SDL_/= SDL_/g' "$tree"/ext/sdl/src/*.[ch]
  printf '/* phpext_sdl_ptr */\n#include "src/php_sdl.h"\n' > "$tree/ext/sdl/php_sdl.h"
  (cd "$tree/ext/sdl" && git apply -p1 "$ROOT/docker/ext/php-sdl-events.patch")
  # PHP-OpenGL: JumPHP's patch, then OpenGL (ES on iOS) from SDL at run time (registry/functions.php)
  (cd "$tree/ext/opengl" && git apply -p1 "$ROOT/docker/ext/php-opengl.patch")
  php -r 'require $argv[1]; jumphp_gl_loader($argv[2], false);' "$REGISTRY/functions.php" "$tree/ext/opengl"
  (cd "$tree" && ./buildconf --force >/dev/null)
  touch "$tree/.done"
}

# what iOS (device and simulator) needs more than macOS, in PHP's configure: no PCRE JIT (no executable memory)
ios_configure() {
  case "$SDK" in iphoneos|iphonesimulator) echo "--without-pcre-jit" ;; esac
}

# what iOS lacks that configure found in its SDK (marked unavailable on iOS): php_config.h without it
ios_config_h() {
  case "$SDK" in iphoneos|iphonesimulator) ;; *) return 0 ;; esac
  # posix_spawn's chdir: proc_open() falls back to fork / exec (an app may start no process anyway)
  perl -pi -e 's{^#define (HAVE_POSIX_SPAWN_FILE_ACTIONS_ADDCHDIR(?:_NP)?) 1$}{/* #undef $1 */}' main/php_config.h
}

# build_php <target>: $CACHE/php/<target>/lib/libphp.a (+ libpng16.a, libssl.a, libcrypto.a) and include/php
build_php() {
  local t="$1"
  target "$t"
  # the key: what makes PHP (versions, the extensions' config.m4, the functions php_tree, target and build_php)
  local key; key="$( { echo "$PHP_VERSION $LIBPNG_VERSION $OPENSSL_VERSION $MESA_VERSION $(xcrun --sdk "$SDK" --show-sdk-version)";
    cat "$ROOT"/tools/android/ext/*.m4 "$ROOT/docker/ext/php-opengl.patch" "$ROOT/docker/ext/php-sdl-events.patch" "$REGISTRY/functions.php";
    declare -f php_tree target build_php; if [ "${SDK#iphone}" != "$SDK" ]; then declare -f ios_configure ios_config_h; fi; } | sha | cut -c1-12)"
  local out="$CACHE/php/$t-$key"
  [ -f "$out/lib/libphp.a" ] && { ln -sfn "$(basename "$out")" "$CACHE/php/$t"; return 0; }
  php_tree
  say "PHP for $t (~5 min)"
  local build="$CACHE/php-build-$t" cc
  cc="$(xcrun --sdk "$SDK" -f clang)"
  rm -rf "$build" && cp -R "$CACHE/php-tree" "$build"
  # libpng (GD's PNG), static, into PHP's prefix: the app links it with libphp.a
  cmake -S "$SRC/libpng" -B "$build/libpng" $CMAKE_ARGS -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX="$out" \
    -DPNG_SHARED=OFF -DPNG_STATIC=ON -DPNG_TESTS=OFF -DPNG_TOOLS=OFF -DPNG_FRAMEWORK=OFF \
    > "$build/libpng.out" 2>&1 && cmake --build "$build/libpng" --target install -j "$JOBS" >> "$build/libpng.out" 2>&1 \
    || { tail -40 "$build/libpng.out"; die "libpng failed to build for $t"; }
  # OpenSSL (PHP's openssl: https:// and wss://), static, into PHP's prefix as libpng
  rm -rf "$build/openssl" && cp -R "$SRC/openssl" "$build/openssl"
  (
    cd "$build/openssl"
    CC="$cc --target=$TRIPLE" ./Configure "$OSSL" --prefix="$out" --libdir=lib no-shared no-tests no-docs no-apps no-module \
      && make -j"$JOBS" build_libs && make install_dev
  ) > "$build/openssl.out" 2>&1 || { tail -40 "$build/openssl.out"; die "OpenSSL failed to build for $t"; }
  (
    cd "$build"
    export CC="$cc --target=$TRIPLE" CXX="$(xcrun --sdk "$SDK" -f clang++) --target=$TRIPLE"
    export AR="$(xcrun --sdk "$SDK" -f ar)" RANLIB="$(xcrun --sdk "$SDK" -f ranlib)"
    export CFLAGS="-O2" CXXFLAGS="-O2"
    export CPPFLAGS="-I$CACHE/include -I$CACHE/include/SDL2 -DGL_GLEXT_PROTOTYPES -DGL_SILENCE_DEPRECATION"
    # zlib: the SDK's (no .pc); pkg-config never sees Homebrew's libraries
    export ZLIB_CFLAGS=" " ZLIB_LIBS="-lz" PKG_CONFIG_LIBDIR=/nonexistent PKG_CONFIG_PATH=
    export PNG_CFLAGS="-I$out/include" PNG_LIBS="-L$out/lib -lpng16 -lz"
    export OPENSSL_CFLAGS="-I$out/include" OPENSSL_LIBS="-L$out/lib -lssl -lcrypto"
    ./configure --host="$HOST" --prefix="$out" \
      --enable-embed=static --disable-cli --disable-cgi --disable-phpdbg --disable-fpm --without-pear --without-iconv \
      --disable-all --disable-opcache-jit --with-zlib --enable-ctype --enable-tokenizer --enable-filter \
      --enable-mbstring --disable-mbregex --enable-sockets --enable-phar --enable-gd --enable-bcmath --with-openssl \
      --enable-sdl --enable-sdl_image --enable-sdl_mixer --enable-sdl_ttf --enable-opengl --enable-openal $(ios_configure) \
      > "$build/configure.out" 2>&1 || { tail -40 "$build/configure.out"; die "PHP's configure failed for $t"; }
    ios_config_h
    make -j"$JOBS" > "$build/make.out" 2>&1 || { grep -E "error|Error" "$build/make.out" | head -40; die "PHP failed to build for $t"; }
    make install-headers install-sapi > /dev/null 2>&1 || true
    mkdir -p "$out/lib" && cp libs/libphp.a "$out/lib/"
  )
  [ -f "$out/lib/libphp.a" ] || die "no libphp.a for $t"
  rm -rf "$build"
  ln -sfn "$(basename "$out")" "$CACHE/php/$t"
}

# --- 3. the apps ----------------------------------------------------------------------------------------------

# the game's settings from its game.yaml (with its own symfony/yaml): name, size, background
game_config() {
  php -r '
    require $argv[1] . "/vendor/autoload.php";
    $f = $argv[1] . "/config/project.yaml";
    $c = Symfony\Component\Yaml\Yaml::parseFile(is_file($f) ? $f : $argv[1] . "/config/game.yaml");
    $g = $c["project"] ?? $c["game"] ?? $c;
    $bg = $g["pwa"]["background_color"] ?? $g["background"] ?? [0, 0, 0];
    $bg = is_string($bg) ? $bg : vsprintf("#%02x%02x%02x", array_slice(array_map("intval", $bg), 0, 3));
    $name = $g["pwa"]["short_name"] ?? $g["title"] ?? $argv[2];
    $get = fn ($k) => match ($k) {
        "name" => (string)$name, "title" => (string)($g["title"] ?? $name), "id" => (string)($g["id"] ?? $argv[2]),
        "width" => (int)($g["width"] ?? 320), "height" => (int)($g["height"] ?? 180), "background" => $bg,
    };
    echo $get($argv[3]);
  ' "$1" "$GAME" "$2"
}

xml() { printf '%s' "$1" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g'; }

# the CMake build of a target: $CACHE/app/<game>/<target>/<id>.app
build_app() {
  local t="$1" build="$CACHE/app/$GAME/$1"
  target "$t"
  say "the app for $t"
  rm -rf "$build" && mkdir -p "$(dirname "$build")"
  cmake -S "$HERE/project" -B "$build" $CMAKE_ARGS -DCMAKE_BUILD_TYPE=Release -DCMAKE_POLICY_VERSION_MINIMUM=3.5 -DJUMPHP_SRC="$SRC" \
    -DJUMPHP_PHP="$CACHE/php/$t" -DJUMPHP_GAME_ID="$ID" > "$build.cmake.out" 2>&1 \
    || { tail -40 "$build.cmake.out"; die "CMake failed for $t"; }
  cmake --build "$build" -j "$JOBS" > "$build.build.out" 2>&1 \
    || { grep -E "error|Error|undefined" "$build.build.out" | head -60; die "the app failed to build for $t"; }
  [ -d "$build/$ID.app" ] || die "no $ID.app for $t"
}

# icons <out folder> <size>...: icon-<size>.png
icons() {
  local out="$1" size; shift
  mkdir -p "$out"
  for size in "$@"; do php "$HERE/icon.php" "$GAME_DIR" "$BG" "$NAME" "$size" "$out/icon-$size.png"; done
}

# the macOS app: <Name>.app, one executable for Apple silicon and Intel
macos_app() {
  build_php macos-arm64
  build_php macos-x86_64
  build_app macos-arm64
  build_app macos-x86_64
  local app="$OUT/macos/$NAME.app" res
  rm -rf "$OUT/macos" && mkdir -p "$OUT/macos"
  cp -R "$CACHE/app/$GAME/macos-arm64/$ID.app" "$app"
  lipo -create "$CACHE/app/$GAME/macos-arm64/$ID.app/Contents/MacOS/$ID" "$CACHE/app/$GAME/macos-x86_64/$ID.app/Contents/MacOS/$ID" \
    -output "$app/Contents/MacOS/$ID"
  res="$app/Contents/Resources"
  mkdir -p "$res"
  cp -R "$GAME_STAGE" "$res/game"
  # the icon: AppIcon.icns from an iconset (iconutil)
  local set="$CACHE/app/$GAME/AppIcon.iconset" s
  rm -rf "$set" && icons "$CACHE/app/$GAME/icons" 16 32 64 128 256 512 1024 && mkdir -p "$set"
  for s in 16 32 128 256 512; do
    cp "$CACHE/app/$GAME/icons/icon-$s.png" "$set/icon_${s}x${s}.png"
    cp "$CACHE/app/$GAME/icons/icon-$((s * 2)).png" "$set/icon_${s}x${s}@2x.png"
  done
  iconutil -c icns "$set" -o "$res/AppIcon.icns"
  cat > "$app/Contents/Info.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CFBundleDevelopmentRegion</key><string>en</string>
  <key>CFBundleExecutable</key><string>$ID</string>
  <key>CFBundleIdentifier</key><string>$BUNDLE_ID</string>
  <key>CFBundleInfoDictionaryVersion</key><string>6.0</string>
  <key>CFBundleName</key><string>$(xml "$NAME")</string>
  <key>CFBundleDisplayName</key><string>$(xml "$NAME")</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleShortVersionString</key><string>$VERSION</string>
  <key>CFBundleVersion</key><string>$BUILD</string>
  <key>CFBundleIconFile</key><string>AppIcon</string>
  <key>LSMinimumSystemVersion</key><string>$MACOS_MIN</string>
  <key>LSApplicationCategoryType</key><string>public.app-category.games</string>
  <key>NSHighResolutionCapable</key><true/>
  <key>NSPrincipalClass</key><string>NSApplication</string>
</dict>
</plist>
EOF
  printf 'APPL????' > "$app/Contents/PkgInfo"
  codesign --force --deep --sign - "$app" >/dev/null
  rm -f "$OUT/$GAME-$VERSION-macos.zip" "$OUT/$GAME-$VERSION-macos.dmg"
  ditto -c -k --keepParent "$app" "$OUT/$GAME-$VERSION-macos.zip"
  hdiutil create -quiet -volname "$NAME" -srcfolder "$app" -ov -format UDZO "$OUT/$GAME-$VERSION-macos.dmg"
  echo "MACOS_APP=$app"
}

# ios_bundle <target> <folder>: <id>.app for an iPhone / iPad (or the simulator) in <folder>
ios_bundle() {
  local t="$1" dest="$2" platform
  build_php "$t"
  build_app "$t"
  target "$t"
  platform=iPhoneOS; [ "$SDK" = iphonesimulator ] && platform=iPhoneSimulator
  local app="$dest/$ID.app"
  rm -rf "$dest" && mkdir -p "$dest"
  cp -R "$CACHE/app/$GAME/$t/$ID.app" "$app"
  cp -R "$GAME_STAGE" "$app/game"
  # the orientation: the game's shape
  local orientations='<string>UIInterfaceOrientationLandscapeLeft</string><string>UIInterfaceOrientationLandscapeRight</string>'
  [ "$WIDTH" -ge "$HEIGHT" ] || orientations='<string>UIInterfaceOrientationPortrait</string><string>UIInterfaceOrientationPortraitUpsideDown</string>'
  cat > "$app/Info.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CFBundleDevelopmentRegion</key><string>en</string>
  <key>CFBundleExecutable</key><string>$ID</string>
  <key>CFBundleIdentifier</key><string>$BUNDLE_ID</string>
  <key>CFBundleInfoDictionaryVersion</key><string>6.0</string>
  <key>CFBundleName</key><string>$(xml "$NAME")</string>
  <key>CFBundleDisplayName</key><string>$(xml "$NAME")</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleShortVersionString</key><string>$VERSION</string>
  <key>CFBundleVersion</key><string>$BUILD</string>
  <key>CFBundleSupportedPlatforms</key><array><string>$platform</string></array>
  <key>DTPlatformName</key><string>$SDK</string>
  <key>DTSDKName</key><string>$SDK$(xcrun --sdk "$SDK" --show-sdk-version)</string>
  <key>MinimumOSVersion</key><string>$IOS_MIN</string>
  <key>LSRequiresIPhoneOS</key><true/>
  <key>UIDeviceFamily</key><array><integer>1</integer><integer>2</integer></array>
  <key>UILaunchScreen</key><dict/>
  <key>UIRequiresFullScreen</key><true/>
  <key>UIStatusBarHidden</key><true/>
  <key>UISupportedInterfaceOrientations</key><array>$orientations</array>
  <key>UISupportedInterfaceOrientations~ipad</key><array>$orientations</array>
  <key>GCSupportsControllerUserInteraction</key><true/>
  <key>UIApplicationSupportsIndirectInputEvents</key><true/>
  <key>ITSAppUsesNonExemptEncryption</key><false/>
</dict>
</plist>
EOF
  # the icon: an asset catalog with one 1024 x 1024 picture, compiled by actool (Assets.car + the icon's keys)
  local assets="$CACHE/app/$GAME/Assets-$t.xcassets"
  rm -rf "$assets" && mkdir -p "$assets/AppIcon.appiconset"
  php "$HERE/icon.php" "$GAME_DIR" "$BG" "$NAME" 1024 "$assets/AppIcon.appiconset/icon-1024.png"
  printf '{"info":{"author":"xcode","version":1}}\n' > "$assets/Contents.json"
  printf '{"images":[{"filename":"icon-1024.png","idiom":"universal","platform":"ios","size":"1024x1024"}],"info":{"author":"xcode","version":1}}\n' \
    > "$assets/AppIcon.appiconset/Contents.json"
  xcrun actool "$assets" --compile "$app" --platform "$SDK" --minimum-deployment-target "$IOS_MIN" --app-icon AppIcon \
    --target-device iphone --target-device ipad --output-partial-info-plist "$CACHE/app/$GAME/partial-$t.plist" \
    --output-format human-readable-text > "$CACHE/app/$GAME/actool-$t.out" 2>&1 || { cat "$CACHE/app/$GAME/actool-$t.out"; die "actool failed"; }
  /usr/libexec/PlistBuddy -c "Merge $CACHE/app/$GAME/partial-$t.plist" "$app/Info.plist" >/dev/null
  printf 'APPL????' > "$app/PkgInfo"
  codesign --force --sign - --timestamp=none "$app" >/dev/null
  IOS_APP="$app"
}

ios_apps() {
  # the iPhone / iPad: an .ipa (Payload/<id>.app), to sign with an Apple developer account
  ios_bundle ios-arm64 "$OUT/ios"
  rm -rf "$OUT/ios/Payload" && mkdir -p "$OUT/ios/Payload" && cp -R "$IOS_APP" "$OUT/ios/Payload/"
  rm -f "$OUT/$GAME-$VERSION-ios.ipa"
  (cd "$OUT/ios" && zip -qry "../$GAME-$VERSION-ios.ipa" Payload) && rm -rf "$OUT/ios/Payload"
  echo "IOS_APP=$IOS_APP"
  # the simulator (Xcode): the .app, zipped
  ios_bundle iossim-arm64 "$OUT/ios-simulator"
  rm -f "$OUT/$GAME-$VERSION-ios-simulator.zip"
  ditto -c -k --keepParent "$IOS_APP" "$OUT/$GAME-$VERSION-ios-simulator.zip"
  echo "IOS_SIMULATOR_APP=$IOS_APP"
}

# webview_bundle <target> <folder>: a desktop app (ui: webview) on an iPhone / iPad (or the simulator):
# tools/apple/webview/main.m (a WKWebView) and the app's browser build in <id>.app/web/
webview_bundle() {
  local t="$1" dest="$2" platform
  target "$t"
  platform=iPhoneOS; [ "$SDK" = iphonesimulator ] && platform=iPhoneSimulator
  local app="$dest/$ID.app"
  say "the app for $t (a WKWebView on dist/web)"
  rm -rf "${dest:?}" && mkdir -p "$app"
  xcrun --sdk "$SDK" clang -fobjc-arc -O2 -target "$TRIPLE" -isysroot "$(xcrun --sdk "$SDK" --show-sdk-path)" \
    -framework UIKit -framework WebKit -framework UserNotifications -framework Foundation -framework CoreGraphics \
    "$HERE/webview/main.m" -o "$app/$ID" || die "the WebView app failed to build for $t"
  cp -R "$GAME_DIR/dist/web" "$app/web"
  cat > "$app/Info.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CFBundleDevelopmentRegion</key><string>en</string>
  <key>CFBundleExecutable</key><string>$ID</string>
  <key>CFBundleIdentifier</key><string>$BUNDLE_ID</string>
  <key>CFBundleInfoDictionaryVersion</key><string>6.0</string>
  <key>CFBundleName</key><string>$(xml "$NAME")</string>
  <key>CFBundleDisplayName</key><string>$(xml "$NAME")</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleShortVersionString</key><string>$VERSION</string>
  <key>CFBundleVersion</key><string>$BUILD</string>
  <key>CFBundleSupportedPlatforms</key><array><string>$platform</string></array>
  <key>DTPlatformName</key><string>$SDK</string>
  <key>DTSDKName</key><string>$SDK$(xcrun --sdk "$SDK" --show-sdk-version)</string>
  <key>MinimumOSVersion</key><string>$IOS_MIN</string>
  <key>LSRequiresIPhoneOS</key><true/>
  <key>UIDeviceFamily</key><array><integer>1</integer><integer>2</integer></array>
  <key>UILaunchScreen</key><dict/>
  <key>UISupportedInterfaceOrientations</key><array><string>UIInterfaceOrientationPortrait</string><string>UIInterfaceOrientationLandscapeLeft</string><string>UIInterfaceOrientationLandscapeRight</string></array>
  <key>UISupportedInterfaceOrientations~ipad</key><array><string>UIInterfaceOrientationPortrait</string><string>UIInterfaceOrientationPortraitUpsideDown</string><string>UIInterfaceOrientationLandscapeLeft</string><string>UIInterfaceOrientationLandscapeRight</string></array>
  <key>ITSAppUsesNonExemptEncryption</key><false/>
</dict>
</plist>
EOF
  local assets="$CACHE/app/$GAME/Assets-$t.xcassets"
  rm -rf "${assets:?}" && mkdir -p "$assets/AppIcon.appiconset"
  php "$HERE/icon.php" "$GAME_DIR" "$BG" "$NAME" 1024 "$assets/AppIcon.appiconset/icon-1024.png"
  printf '{"info":{"author":"xcode","version":1}}\n' > "$assets/Contents.json"
  printf '{"images":[{"filename":"icon-1024.png","idiom":"universal","platform":"ios","size":"1024x1024"}],"info":{"author":"xcode","version":1}}\n' \
    > "$assets/AppIcon.appiconset/Contents.json"
  xcrun actool "$assets" --compile "$app" --platform "$SDK" --minimum-deployment-target "$IOS_MIN" --app-icon AppIcon \
    --target-device iphone --target-device ipad --output-partial-info-plist "$CACHE/app/$GAME/partial-$t.plist" \
    --output-format human-readable-text > "$CACHE/app/$GAME/actool-$t.out" 2>&1 || { cat "$CACHE/app/$GAME/actool-$t.out"; die "actool failed"; }
  /usr/libexec/PlistBuddy -c "Merge $CACHE/app/$GAME/partial-$t.plist" "$app/Info.plist" >/dev/null
  printf 'APPL????' > "$app/PkgInfo"
  codesign --force --sign - --timestamp=none "$app" >/dev/null
  IOS_APP="$app"
}

# webview_ios: the .ipa and the simulator's app of a desktop app, as ios_apps does for a game
webview_ios() {
  [ -f "$GAME_DIR/dist/web/index.html" ] || die "no $GAME_DIR/dist/web: bin/jumphp build $GAME first (the app's browser build)"
  mkdir -p "$CACHE/app/$GAME"
  webview_bundle ios-arm64 "$OUT/ios"
  rm -rf "${OUT:?}/ios/Payload" && mkdir -p "$OUT/ios/Payload" && cp -R "$IOS_APP" "$OUT/ios/Payload/"
  rm -f "${OUT:?}/${GAME:?}-${VERSION:?}-ios.ipa"
  (cd "$OUT/ios" && zip -qry "../$GAME-$VERSION-ios.ipa" Payload) && rm -rf "${OUT:?}/ios/Payload"
  echo "IOS_APP=$IOS_APP"
  webview_bundle iossim-arm64 "$OUT/ios-simulator"
  rm -f "${OUT:?}/${GAME:?}-${VERSION:?}-ios-simulator.zip"
  ditto -c -k --keepParent "$IOS_APP" "$OUT/$GAME-$VERSION-ios-simulator.zip"
  echo "IOS_SIMULATOR_APP=$IOS_APP"
}

[ -n "${GAME:-}" ] || die "GAME: a folder of games/ or apps/"
GAME_DIR="$ROOT/games/$GAME"; [ -d "$GAME_DIR" ] || GAME_DIR="$ROOT/apps/$GAME"
[ -d "$GAME_DIR" ] || die "no games/$GAME nor apps/$GAME"
[ -f "$GAME_DIR/vendor/autoload.php" ] || die "$GAME_DIR has no vendor/ (composer install)"
PLATFORMS="${PLATFORMS:-macos ios}"
BUNDLE_ID="${BUNDLE_ID:-io.github.jumphp.$GAME}"
VERSION="${VERSION:-1.0}"
BUILD="${BUILD:-1}"
NAME="$(game_config "$GAME_DIR" name)"
ID="$(game_config "$GAME_DIR" id | tr -c 'a-zA-Z0-9-\n' '-')"
WIDTH="$(game_config "$GAME_DIR" width)"
HEIGHT="$(game_config "$GAME_DIR" height)"
BG="$(game_config "$GAME_DIR" background)"
OUT="$GAME_DIR/dist/apple"
mkdir -p "$OUT"

# a desktop app (ui: webview): the WKWebView app, nothing else to build
yaml="$GAME_DIR/config/project.yaml"; [ -f "$yaml" ] || yaml="$GAME_DIR/config/game.yaml"
if grep -qE '^[[:space:]]*ui:[[:space:]]*["'"'"']?webview' "$yaml"; then
  for p in $PLATFORMS; do
    case "$p" in
      ios) say "$NAME ($BUNDLE_ID $VERSION, build $BUILD): a desktop app on iOS"; webview_ios ;;
      macos) say "macOS: a desktop app's Mac app is Tauri's: bin/jumphp app:export $GAME --macos" ;;
      *) die "PLATFORMS: macos, ios" ;;
    esac
  done
  say "done: $(cd "$OUT" && ls -1 ./*.zip ./*.ipa 2>/dev/null | tr '\n' ' ')"
  exit 0
fi

sources
case "${STAGE:-all}" in
  php)
    for p in $PLATFORMS; do
      case "$p" in macos) build_php macos-arm64; build_php macos-x86_64 ;; ios) build_php ios-arm64; build_php iossim-arm64 ;; esac
    done ;;
  all)
    say "$NAME ($BUNDLE_ID $VERSION, build $BUILD): $PLATFORMS"
    GAME_STAGE="$CACHE/stage/$GAME"
    bash "$ROOT/tools/stage-game.sh" "$GAME_DIR" "$GAME_STAGE" > /dev/null
    for p in $PLATFORMS; do
      case "$p" in
        macos) macos_app ;;
        ios) ios_apps ;;
        *) die "PLATFORMS: macos, ios" ;;
      esac
    done
    say "done: $(cd "$OUT" && ls -1 ./*.zip ./*.dmg ./*.ipa 2>/dev/null | tr '\n' ' ')" ;;
  *) die "STAGE: php, all" ;;
esac
