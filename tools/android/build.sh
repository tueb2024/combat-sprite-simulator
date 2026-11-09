#!/usr/bin/env bash
# A game as a native Android app (bin/jumphp android <game>; the GitHub workflow android.yml), run in
# docker/android-native. No browser, no WebView:
#   1. the sources: PHP, SDL2 + SDL2_image / _mixer / _ttf, OpenAL Soft and the PHP extensions, at the versions of
#      the standalone registry (docker/standalone/registry/config), kept in /cache (~/.jumphp/android-native)
#   2. PHP 8.5 per ABI (arm64-v8a: phones; x86_64: emulators), cross-built with the NDK: the embed SAPI as a static
#      library, JumPHP's extensions inside (tools/android/ext/*.m4), kept in /cache per (sources, flags)
#   3. the app: SDL's Android project (tools/android/project) + jni/main.c (PHP embedded: unpacks the game,
#      boots it) + the game as assets/game.tar; Gradle builds SDL, its add-ons, OpenAL and libmain.so, signs
#   4. games/<game>/dist/android-native/<game>-<version>.aab (Google Play) and .apk (a phone)
# A desktop app (ui: webview) instead: no native PHP, a WebView showing its browser build (dist/web, made before by
# bin/jumphp build: PHP in WebAssembly, engine/web/jumphp-app.js) packed in the APK (tools/android/webview).
# Environment: GAME, ABIS (default "arm64-v8a x86_64"), PACKAGE, VERSION_CODE, VERSION_NAME, the signing key
# (KEYSTORE file, KEYSTORE_PASSWORD, KEY_ALIAS, KEY_PASSWORD: without them, a debug-signed APK only).
# /workspace: the project; /cache: downloads and builds; /opt/android-sdk: the SDK with its NDK.
set -euo pipefail

say() { printf '\033[1;36m==> %s\033[0m\n' "$*" >&2; }
die() { printf '\033[1;31m[x] %s\033[0m\n' "$*" >&2; exit 1; }

NDK_VERSION="${NDK_VERSION:-29.0.14206865}"
API=26
PHP_VERSION=8.5.12
LIBPNG_VERSION=1.6.59 # as StaticPHP's (GD's PNG)
OPENSSL_VERSION=3.6.5 # as StaticPHP's (HTTPS: https:// and wss://)
NDK="$ANDROID_HOME/ndk/$NDK_VERSION"
TOOLCHAIN="$NDK/toolchains/llvm/prebuilt/linux-x86_64"
ABIS="${ABIS:-arm64-v8a x86_64}"
HERE=/workspace/tools/android
REGISTRY=/workspace/docker/standalone/registry
SRC=/cache/src
mkdir -p /cache/downloads "$SRC"
[ -d "$NDK" ] || die "no NDK $NDK_VERSION in the Android SDK (sdkmanager --install \"ndk;$NDK_VERSION\")"

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
  file="/cache/downloads/$(basename "$url")"
  [ -s "$file" ] || { say "download $url"; curl -fsSL -A jumphp-android -o "$file.part" "$url" && mv "$file.part" "$file"; }
  mkdir -p "$SRC/$name.tmp"
  tar -xf "$file" --strip-components 1 -C "$SRC/$name.tmp"
  mv "$SRC/$name.tmp" "$SRC/$name"
}

sources() {
  fetch php "https://www.php.net/distributions/php-$PHP_VERSION.tar.xz"
  fetch libpng "https://github.com/pnggroup/libpng/archive/refs/tags/v$LIBPNG_VERSION.tar.gz"
  fetch openssl "https://github.com/openssl/openssl/releases/download/openssl-$OPENSSL_VERSION/openssl-$OPENSSL_VERSION.tar.gz"
  for lib in sdl2 sdl2_image sdl2_mixer sdl2_ttf openal; do fetch "$lib" "$(registry_url "$lib")"; done
  for ext in sdl sdl_image sdl_mixer sdl_ttf opengl openal; do fetch "ext-$ext" "$(registry_url "ext-$ext")"; done

  # the headers the extensions include: <SDL.h>, <SDL2/SDL_image.h>, <AL/al.h>, <GL/gl.h>
  local inc=/cache/include
  if [ ! -f "$inc/.done" ]; then
    rm -rf "$inc" && mkdir -p "$inc/SDL2" "$inc/AL"
    cp "$SRC"/sdl2/include/*.h "$inc/SDL2/"
    cp "$SRC/sdl2_image/include/SDL_image.h" "$SRC/sdl2_mixer/include/SDL_mixer.h" "$SRC/sdl2_ttf/SDL_ttf.h" "$inc/SDL2/"
    cp "$SRC"/openal/include/AL/*.h "$inc/AL/"
    cp -r /usr/include/GL /usr/include/KHR "$inc/"
    touch "$inc/.done"
  fi
}

# --- 2. PHP ---------------------------------------------------------------------------------------------------

# php-src with JumPHP's extensions in ext/ (their Android config.m4, the patches of the standalone registry)
php_tree() {
  local tree=/cache/php-tree
  [ -f "$tree/.done" ] && return 0
  say "PHP $PHP_VERSION + JumPHP's extensions"
  rm -rf "$tree" && cp -r "$SRC/php" "$tree"
  for ext in sdl sdl_image sdl_mixer sdl_ttf opengl openal; do
    rm -rf "$tree/ext/$ext" && cp -r "$SRC/ext-$ext" "$tree/ext/$ext"
    rm -f "$tree/ext/$ext/config.w32"
    cp "$HERE/ext/$ext.m4" "$tree/ext/$ext/config.m4"
  done
  # php-sdl: PHP 8.5 (zend_ce_exception), no SDLCALL before a call, its header in src/ (registry/src/Extension/sdl.php)
  sed -i 's/zend_exception_get_default()/zend_ce_exception/g; s/= SDLCALL SDL_/= SDL_/g' "$tree"/ext/sdl/src/*.[ch]
  printf '/* phpext_sdl_ptr */\n#include "src/php_sdl.h"\n' > "$tree/ext/sdl/php_sdl.h"
  (cd "$tree/ext/sdl" && git apply -p1 /workspace/docker/ext/php-sdl-events.patch)
  # PHP-OpenGL: JumPHP's patch, then OpenGL (ES on Android) from SDL at run time (registry/functions.php)
  (cd "$tree/ext/opengl" && git apply -p1 /workspace/docker/ext/php-opengl.patch)
  php -r 'require $argv[1]; jumphp_gl_loader($argv[2], false);' "$REGISTRY/functions.php" "$tree/ext/opengl"
  (cd "$tree" && ./buildconf --force >/dev/null)
  touch "$tree/.done"
}

# php <abi>: /cache/php/<abi>/lib/libphp.a and include/php
build_php() {
  local abi="$1" target
  case "$abi" in
    arm64-v8a) target=aarch64-linux-android ;;
    x86_64) target=x86_64-linux-android ;;
    *) die "unknown ABI $abi" ;;
  esac
  # the key: what makes PHP (versions, the extensions' config.m4, the functions php_tree and build_php themselves)
  local key; key="$( { echo "$PHP_VERSION $NDK_VERSION $API $LIBPNG_VERSION $OPENSSL_VERSION"; cat "$HERE"/ext/*.m4 /workspace/docker/ext/php-opengl.patch /workspace/docker/ext/php-sdl-events.patch \
    "$REGISTRY/functions.php"; declare -f php_tree build_php; } | sha256sum | cut -c1-12)"
  local out="/cache/php/$abi-$key"
  [ -f "$out/lib/libphp.a" ] && { ln -sfn "$(basename "$out")" "/cache/php/$abi"; return 0; }
  php_tree
  say "PHP for $abi (~5 min)"
  local build="/cache/php-build-$abi"
  rm -rf "$build" && cp -r /cache/php-tree "$build"
  # libpng (GD's PNG), static, into PHP's prefix: libmain.so links it with libphp.a
  cmake -S "$SRC/libpng" -B "$build/libpng" -G Ninja -DCMAKE_TOOLCHAIN_FILE="$NDK/build/cmake/android.toolchain.cmake" \
    -DANDROID_ABI="$abi" -DANDROID_PLATFORM="android-$API" -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX="$out" \
    -DPNG_SHARED=OFF -DPNG_STATIC=ON -DPNG_TESTS=OFF -DPNG_TOOLS=OFF -DPNG_FRAMEWORK=OFF -DCMAKE_POSITION_INDEPENDENT_CODE=ON \
    > "$build/libpng.out" 2>&1 && cmake --build "$build/libpng" --target install >> "$build/libpng.out" 2>&1 \
    || { tail -30 "$build/libpng.out"; die "libpng failed to build for $abi"; }
  # OpenSSL (PHP's openssl: https:// and wss://), static, into PHP's prefix as libpng
  local ossl_target=android-arm64; [ "$abi" = x86_64 ] && ossl_target=android-x86_64
  rm -rf "$build/openssl" && cp -r "$SRC/openssl" "$build/openssl"
  (
    cd "$build/openssl"
    export ANDROID_NDK_ROOT="$NDK" PATH="$TOOLCHAIN/bin:$PATH"
    ./Configure "$ossl_target" -D__ANDROID_API__="$API" --prefix="$out" --libdir=lib no-shared no-tests no-docs \
      no-apps no-module && make -j"$(nproc)" build_libs && make install_dev
  ) > "$build/openssl.out" 2>&1 || { tail -30 "$build/openssl.out"; die "OpenSSL failed to build for $abi"; }
  (
    cd "$build"
    export CC="$TOOLCHAIN/bin/$target$API-clang" CXX="$TOOLCHAIN/bin/$target$API-clang++"
    export AR="$TOOLCHAIN/bin/llvm-ar" RANLIB="$TOOLCHAIN/bin/llvm-ranlib" STRIP="$TOOLCHAIN/bin/llvm-strip"
    export CFLAGS="-O2 -fPIC" CXXFLAGS="-O2 -fPIC"
    # what bionic's headers lack, given to every file: getdtablesize() (php://fd/)
    printf '#ifndef __ASSEMBLER__\n#include <unistd.h>\n#define getdtablesize() ((int)sysconf(_SC_OPEN_MAX))\n#endif\n' > "$build/jumphp-android.h"
    export CPPFLAGS="-I/cache/include -I/cache/include/SDL2 -DGL_GLEXT_PROTOTYPES -include $build/jumphp-android.h"
    # zlib: the NDK's (no .pc); pkg-config never sees the image's own (Linux) libraries
    export ZLIB_CFLAGS="-I$TOOLCHAIN/sysroot/usr/include" ZLIB_LIBS="-lz" PKG_CONFIG_LIBDIR=/nonexistent
    export PNG_CFLAGS="-I$out/include" PNG_LIBS="-L$out/lib -lpng16 -lz"
    export OPENSSL_CFLAGS="-I$out/include" OPENSSL_LIBS="-L$out/lib -lssl -lcrypto"
    ./configure --host="$target" --build=x86_64-pc-linux-gnu --prefix="$out" \
      --enable-embed=static --disable-cli --disable-cgi --disable-phpdbg --disable-fpm --without-pear \
      --disable-all --disable-opcache-jit --with-zlib --enable-ctype --enable-tokenizer --enable-filter \
      --enable-mbstring --disable-mbregex --enable-sockets --enable-phar --enable-gd --enable-bcmath --with-openssl \
      --enable-sdl --enable-sdl_image --enable-sdl_mixer --enable-sdl_ttf --enable-opengl --enable-openal \
      > "$build/configure.out" 2>&1 || { tail -30 "$build/configure.out"; die "PHP's configure failed for $abi"; }
    # bionic has the resolver's res_n* / dn_* in libc but no header for them: PHP's DNS functions (dns_get_record...)
    # without them; gethostbyname / getaddrinfo stay
    sed -i -E 's/^#define (HAVE_RES_NSEARCH|HAVE_RES_SEARCH|HAVE_DN_EXPAND|HAVE_DN_SKIPNAME|HAVE_RES_NDESTROY) 1$/\/* #undef \1 *\//' main/php_config.h
    # symlink() / readlink(): bionic has them (not found by the cross-compiled test)
    sed -i 's|^/\* #undef HAVE_SYMLINK \*/$|#define HAVE_SYMLINK 1|' main/php_config.h
    make -j"$(nproc)" > "$build/make.out" 2>&1 || { grep -E "error|Error" "$build/make.out" | head -30; die "PHP failed to build for $abi"; }
    make install-headers install-sapi > /dev/null 2>&1 || true
    mkdir -p "$out/lib" && cp libs/libphp.a "$out/lib/"
  )
  [ -f "$out/lib/libphp.a" ] || die "no libphp.a for $abi"
  rm -rf "$build"
  ln -sfn "$(basename "$out")" "/cache/php/$abi"
}

# --- 3. the app -----------------------------------------------------------------------------------------------

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
    echo json_encode(["name" => (string)$name, "title" => (string)($g["title"] ?? $name), "id" => (string)($g["id"] ?? $argv[2]),
        "width" => (int)($g["width"] ?? 320), "height" => (int)($g["height"] ?? 180), "background" => $bg]);
  ' "$1" "$GAME"
}

# icons <game dir> <res dir> <background> <title>: mipmap-*/ic_launcher.png, as the PWA's (tools/render-web.php):
# assets/icon.png scaled up in whole pixels inside the safe zone, else the title's initial
icons() {
  local game="$1" res="$2" bg="$3" title="$4" dpi size source
  source="$game/assets/icon.png"
  if [ ! -f "$source" ]; then
    source=/tmp/initial.png
    convert -size 16x16 "xc:$bg" -fill '#ffd23f' -font DejaVu-Sans-Mono-Bold -pointsize 14 -gravity center \
      -annotate +0+0 "$(printf '%s' "${title:-J}" | cut -c1 | tr '[:lower:]' '[:upper:]')" "$source"
  fi
  for dpi in mdpi:48 hdpi:72 xhdpi:96 xxhdpi:144 xxxhdpi:192; do
    size="${dpi#*:}"
    mkdir -p "$res/mipmap-${dpi%%:*}"
    local w h scale
    w="$(identify -format '%w' "$source")"
    h="$(identify -format '%h' "$source")"
    scale=$(( size * 7 / 10 / (w > h ? w : h) ))
    [ "$scale" -ge 1 ] || scale=1
    convert "$source" -filter point -resize "$((w * scale))x$((h * scale))" -background "$bg" -gravity center \
      -extent "${size}x${size}" "$res/mipmap-${dpi%%:*}/ic_launcher.png"
  done
}

app() {
  local project_dir="/workspace/games/$GAME"; [ -d "$project_dir" ] || project_dir="/workspace/apps/$GAME"
  [ -d "$project_dir" ] || die "no games/$GAME nor apps/$GAME"
  local cfg; cfg="$(game_config "$project_dir")"
  local get; get() { printf '%s' "$cfg" | php -r '$c = json_decode(stream_get_contents(STDIN), true); echo $c[$argv[1]];' "$1"; }
  local name width height bg id
  name="$(get name)"; width="$(get width)"; height="$(get height)"; bg="$(get background)"; id="$(get id)"
  local project="/cache/app/$GAME"
  say "the app: $name ($PACKAGE $VERSION_NAME, version $VERSION_CODE; ${ABIS// /, })"
  rm -rf "$project" && mkdir -p "$project"
  cp -r "$HERE/project/." "$project/"
  # SDL's Java part and Gradle's wrapper, from SDL's own Android project
  mkdir -p "$project/app/src/main/java/org/libsdl/app" "$project/gradle/wrapper"
  cp "$SRC"/sdl2/android-project/app/src/main/java/org/libsdl/app/*.java "$project/app/src/main/java/org/libsdl/app/"
  cp "$SRC/sdl2/android-project/gradlew" "$project/" && chmod +x "$project/gradlew"
  cp "$SRC/sdl2/android-project/gradle/wrapper/gradle-wrapper.jar" "$project/gradle/wrapper/"
  printf 'distributionUrl=https\\://services.gradle.org/distributions/gradle-8.13-bin.zip\ndistributionBase=GRADLE_USER_HOME\ndistributionPath=wrapper/dists\nzipStoreBase=GRADLE_USER_HOME\nzipStorePath=wrapper/dists\n' \
    > "$project/gradle/wrapper/gradle-wrapper.properties"
  printf 'sdk.dir=%s\ncmake.dir=%s\n' "$ANDROID_HOME" "$(dirname "$(dirname "$(readlink -f "$(command -v cmake)")")")" > "$project/local.properties"

  # the name, the orientation (the game's shape), the icons
  local orientation=sensorLandscape
  [ "$width" -ge "$height" ] || orientation=sensorPortrait
  local escaped; escaped="$(printf '%s' "$name" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e "s/'/\\\\'/g")"
  sed -i "s|@NAME@|$escaped|" "$project/app/src/main/res/values/strings.xml"
  sed -i "s|@ORIENTATION@|$orientation|" "$project/app/src/main/AndroidManifest.xml"
  icons "$project_dir" "$project/app/src/main/res" "$bg" "$name"

  # the game: assets/game.tar + game.id (its build)
  local stage=/tmp/stage/game build
  build="$(bash /workspace/tools/stage-game.sh "$project_dir" "$stage")"
  mkdir -p "$project/app/src/main/assets"
  tar --format=gnu -cf "$project/app/src/main/assets/game.tar" -C "$stage" .
  printf '%s' "$build" > "$project/app/src/main/assets/game.id"

  # Gradle: SDL, its add-ons, OpenAL and libmain.so per ABI, the APK and the AAB, signed
  local tasks="assembleRelease"
  [ -n "${KEYSTORE:-}" ] && tasks="assembleRelease bundleRelease"
  (
    cd "$project"
    # ANDROID_USER_HOME: the debug key (builds without a release key) the same from one build to the next
    export GRADLE_USER_HOME=/cache/gradle ANDROID_USER_HOME=/cache/android-user JUMPHP_PACKAGE="$PACKAGE" JUMPHP_VERSION_CODE="$VERSION_CODE" \
      JUMPHP_VERSION_NAME="$VERSION_NAME" JUMPHP_ABIS="$ABIS" JUMPHP_PHP=/cache/php JUMPHP_SRC="$SRC" JUMPHP_NDK="$NDK_VERSION" \
      JUMPHP_KEYSTORE="${KEYSTORE:-}" JUMPHP_KEYSTORE_PASSWORD="${KEYSTORE_PASSWORD:-}" JUMPHP_KEY_ALIAS="${KEY_ALIAS:-}" \
      JUMPHP_KEY_PASSWORD="${KEY_PASSWORD:-}"
    ./gradlew --no-daemon -q $tasks > /tmp/gradle.out 2>&1 || { grep -vE "^\s*at |^\s*$" /tmp/gradle.out | tail -60; die "Gradle failed"; }
  )

  local out="$project_dir/dist/android-native" file="$GAME-$VERSION_NAME"
  mkdir -p "$out"
  cp "$project/app/build/outputs/apk/release/app-release.apk" "$out/$file.apk"
  echo "APK=$out/$file.apk"
  if [ -n "${KEYSTORE:-}" ]; then
    cp "$project/app/build/outputs/bundle/release/app-release.aab" "$out/$file.aab"
    echo "AAB=$out/$file.aab"
  fi
}

# a desktop app (ui: webview): its browser build in a WebView (tools/android/webview), no PHP built here
webview_app() {
  local project_dir="/workspace/games/$GAME"; [ -d "$project_dir" ] || project_dir="/workspace/apps/$GAME"
  [ -f "$project_dir/dist/web/index.html" ] || die "no browser build of $GAME: bin/jumphp build $GAME first"
  fetch sdl2 "$(registry_url sdl2)"   # Gradle's wrapper, from SDL's Android project (as the games' apps)
  local cfg; cfg="$(game_config "$project_dir")"
  local get; get() { printf '%s' "$cfg" | php -r '$c = json_decode(stream_get_contents(STDIN), true); echo $c[$argv[1]];' "$1"; }
  local name bg
  name="$(get name)"; bg="$(get background)"
  local project="/cache/webapp/$GAME"
  say "the app: $name ($PACKAGE $VERSION_NAME, version $VERSION_CODE; a WebView on its browser build)"
  rm -rf "$project" && mkdir -p "$project/gradle/wrapper"
  cp -r "$HERE/webview/." "$project/"
  cp "$SRC/sdl2/android-project/gradlew" "$project/" && chmod +x "$project/gradlew"
  cp "$SRC/sdl2/android-project/gradle/wrapper/gradle-wrapper.jar" "$project/gradle/wrapper/"
  printf 'distributionUrl=https\\://services.gradle.org/distributions/gradle-8.13-bin.zip\ndistributionBase=GRADLE_USER_HOME\ndistributionPath=wrapper/dists\nzipStoreBase=GRADLE_USER_HOME\nzipStorePath=wrapper/dists\n' \
    > "$project/gradle/wrapper/gradle-wrapper.properties"
  printf 'sdk.dir=%s\n' "$ANDROID_HOME" > "$project/local.properties"
  local escaped; escaped="$(printf '%s' "$name" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e "s/'/\\\\'/g")"
  sed -i "s|@NAME@|$escaped|" "$project/app/src/main/res/values/strings.xml"
  icons "$project_dir" "$project/app/src/main/res" "$bg" "$name"
  mkdir -p "$project/app/src/main/assets/web"
  cp -rL "$project_dir/dist/web/." "$project/app/src/main/assets/web/"

  local tasks="assembleRelease"
  [ -n "${KEYSTORE:-}" ] && tasks="assembleRelease bundleRelease"
  (
    cd "$project"
    export GRADLE_USER_HOME=/cache/gradle ANDROID_USER_HOME=/cache/android-user JUMPHP_PACKAGE="$PACKAGE" JUMPHP_VERSION_CODE="$VERSION_CODE" \
      JUMPHP_VERSION_NAME="$VERSION_NAME" JUMPHP_KEYSTORE="${KEYSTORE:-}" JUMPHP_KEYSTORE_PASSWORD="${KEYSTORE_PASSWORD:-}" \
      JUMPHP_KEY_ALIAS="${KEY_ALIAS:-}" JUMPHP_KEY_PASSWORD="${KEY_PASSWORD:-}"
    ./gradlew --no-daemon -q $tasks > /tmp/gradle.out 2>&1 || { grep -vE "^\s*at |^\s*$" /tmp/gradle.out | tail -60; die "Gradle failed"; }
  )
  local out="$project_dir/dist/android-native" file="$GAME-$VERSION_NAME"
  mkdir -p "$out"
  cp "$project/app/build/outputs/apk/release/app-release.apk" "$out/$file.apk"
  echo "APK=$out/$file.apk"
  if [ -n "${KEYSTORE:-}" ]; then
    cp "$project/app/build/outputs/bundle/release/app-release.aab" "$out/$file.aab"
    echo "AAB=$out/$file.aab"
  fi
}

# a desktop app (project.yaml / game.yaml ui: webview)?
is_webview() {
  local d="/workspace/games/$GAME"; [ -d "$d" ] || d="/workspace/apps/$GAME"
  local y="$d/config/project.yaml"; [ -f "$y" ] || y="$d/config/game.yaml"
  grep -qE '^[[:space:]]*ui:[[:space:]]*["'"'"']?webview' "$y" 2>/dev/null
}

PACKAGE="${PACKAGE:-net.jumphp.${GAME//-/_}}"
VERSION_CODE="${VERSION_CODE:-1}"
VERSION_NAME="${VERSION_NAME:-1.0}"

case "${STAGE:-all}" in
  sources) sources ;;
  php) sources; for abi in $ABIS; do build_php "$abi"; done ;;
  all) if is_webview; then webview_app; else sources; for abi in $ABIS; do build_php "$abi"; done; app; fi ;;
  *) die "STAGE: sources, php, all" ;;
esac
