# JumPHP WebAssembly engine: PHP 8.5 (embed SAPI) + SDL2 / SDL_image / SDL_mixer / SDL_ttf / GD / zlib
# + wasmnet (php-wasm-network) compiled with emscripten. Output: php-web.mjs + php-web.wasm.
# The engine is shared by every game: build it once (`bin/jumphp engine:build`, ~25 min cold, cached after).
#
# The extension refs MUST match docker/desktop/Dockerfile (same SDL / OpenGL / OpenAL API on desktop and in the browser),
# The browser bridge php-wasm-network.js is shipped WITH the engine (same NETWORK_REF as the wasmnet extension),
# so the JS side always matches the C side; engine/composer.json pins the same commit for the PHP classes.
FROM emscripten/emsdk:3.1.35 AS build_tool

RUN apt-get update && apt-get --no-install-recommends -y install \
        build-essential automake autoconf libtool pkgconf python3 bison flex make re2c git zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

FROM build_tool AS php_src
ARG PHP_BRANCH=php-8.5.12
ARG PHP_SDL_REF=89855c8f4f63b77f9a548007b9c4afe366785b4b
ARG SDL_IMAGE_REF=fd798eb2c52b7be5874a30334d1d255436aa686e
ARG SDL_MIXER_REF=f75a31826f4484bb4af31c1a1a4cdf1463aae8b2
ARG SDL_TTF_REF=d2c634c509aa0b14168f55128435d9519d34d0e3
ARG NETWORK_REF=d273712204a378f656fd3e73cc0e06994249c464
ARG PHP_OPENGL_REF=438798d5f0a5347a59d13252611556792cc2a1b8
ARG PHP_OPENAL_REF=6f78e95120ffe2cd30813a78ca66eaedde310314

COPY --from=ext php-sdl-events.patch /tmp/php-sdl-events.patch
RUN git clone https://github.com/php/php-src.git /src/php-src --branch $PHP_BRANCH --single-branch --depth 1

RUN set -eux; \
    fetch() { mkdir -p "$3" && cd "$3" && git init -q && git remote add origin "https://$1.git" \
              && git fetch -q --depth 1 origin "$2" && git checkout -q FETCH_HEAD; }; \
    fetch git.jumphp.net/jumphp/php-sdl           "$PHP_SDL_REF"   /tmp/php-sdl; \
    (cd /tmp/php-sdl && git apply /tmp/php-sdl-events.patch); \
    fetch github.com/kea/php-sdl-image             "$SDL_IMAGE_REF" /src/php-src/ext/sdl_image; \
    fetch github.com/kea/php-sdl-mixer             "$SDL_MIXER_REF" /src/php-src/ext/sdl_mixer; \
    fetch github.com/kea/php-sdl-ttf               "$SDL_TTF_REF"   /src/php-src/ext/sdl_ttf; \
    fetch git.jumphp.net/jumphp/php-wasm-network  "$NETWORK_REF"   /src/php-wasm-network; \
    fetch github.com/Ponup/php-opengl              "$PHP_OPENGL_REF" /src/php-src/ext/opengl; \
    fetch github.com/Ponup/php-openal              "$PHP_OPENAL_REF" /src/php-src/ext/openal; \
    mkdir -p /src/php-src/ext/sdl; \
    cp -r /tmp/php-sdl/src/* /src/php-src/ext/sdl/; \
    sed -i 's/zend_exception_get_default()/zend_ce_exception/g' /src/php-src/ext/sdl/*.c /src/php-src/ext/sdl/*.h; \
    # emscripten's libc declares reallocarray() without having it: PHP's own copy (glob), renamed
    sed -i 's/\breallocarray\b/php_glob_reallocarray/g' /src/php-src/main/php_glob.c; \
    cp -r /src/php-wasm-network/ext/wasmnet /src/php-src/ext/wasmnet

# config.m4 replacements: link the emscripten SDL ports (-sUSE_SDL*=2) instead of the system libraries
COPY config-sdl.m4       /src/php-src/ext/sdl/config.m4
COPY config-sdl_image.m4 /src/php-src/ext/sdl_image/config.m4
COPY config-sdl_mixer.m4 /src/php-src/ext/sdl_mixer/config.m4
COPY config-sdl_ttf.m4   /src/php-src/ext/sdl_ttf/config.m4
COPY config-opengl.m4    /src/php-src/ext/opengl/config.m4
COPY config-openal.m4    /src/php-src/ext/openal/config.m4
# docker/ext (named build context "ext"): the JumPHP additions to PHP-OpenGL (framebuffers, state queries,
# uniforms, binary pixels, ES / WebGL guards)
COPY --from=ext php-opengl.patch /tmp/php-opengl.patch
RUN cd /src/php-src/ext/opengl && git apply /tmp/php-opengl.patch

FROM php_src AS php-wasm
ARG OPTIMIZE=-O3
ARG INITIAL_MEMORY=256mb
ARG ASSERTIONS=0
ARG NETWORK_REF=d273712204a378f656fd3e73cc0e06994249c464
ENV PNG_LIBS="-lpng"

# system SDL headers + .pc files: only so that ./configure finds them; the emscripten ports are linked
RUN apt-get update && apt-get --no-install-recommends -y install \
        libsdl2-dev pkg-config libsdl2-image-dev libsdl2-ttf-dev libsdl2-mixer-dev zlib1g-dev libpng-dev \
    && rm -rf /var/lib/apt/lists/*
ENV PKG_CONFIG_PATH="/usr/lib/x86_64-linux-gnu/pkgconfig/"

# CFLAGS -O2 matters: when CFLAGS is given PHP drops its own -O2 (an -O0 engine runs 7-12x slower)
# emconfigure points PKG_CONFIG_PATH to the emscripten sysroot: the system one is passed as a configure variable
RUN --mount=type=cache,target=/emsdk/upstream/emscripten/cache cd /src/php-src && ./buildconf --force \
    && emconfigure ./configure \
        CFLAGS="-O2 -s USE_ZLIB=1 -s USE_LIBPNG=1" LDFLAGS="-s USE_ZLIB=1 -s USE_LIBPNG=1" \
        PKG_CONFIG_PATH="/usr/lib/x86_64-linux-gnu/pkgconfig/" \
        --enable-embed=static --with-layout=GNU \
        --enable-sdl --with-sdl_image --enable-sdl_ttf --with-sdl_mixer --enable-wasmnet --enable-opengl --enable-openal \
        --enable-gd --with-png \
        --disable-all --disable-cgi --disable-cli --disable-phpdbg --disable-opcache --disable-opcache-jit --disable-fiber-asm \
        --disable-rpath --without-pear --with-valgrind=no --without-pcre-jit --disable-mbregex \
        --enable-bcmath --enable-ctype --enable-mbstring --with-zlib --enable-tokenizer \
        --with-config-file-scan-dir=/src/php

RUN --mount=type=cache,target=/emsdk/upstream/emscripten/cache cd /src/php-src && emmake make -j"$(nproc)"

COPY source /src/source
RUN --mount=type=cache,target=/emsdk/upstream/emscripten/cache cd /src/php-src && emcc $OPTIMIZE \
        -I . -I Zend -I main -I TSRM/ -c /src/source/phpw.c -o /src/phpw.o -s ERROR_ON_UNDEFINED_SYMBOLS=0

RUN --mount=type=cache,target=/emsdk/upstream/emscripten/cache mkdir /build && cd /src/php-src && emcc $OPTIMIZE \
        -o /build/php-web.mjs \
        --llvm-lto 2 -flto -fno-inline \
        -s EXPORTED_FUNCTIONS='["_phpw_run","_phpw_restart","_chdir","_setenv","_php_embed_init","_php_embed_shutdown","_zend_eval_string"]' \
        -s EXTRA_EXPORTED_RUNTIME_METHODS='["ccall","UTF8ToString","lengthBytesUTF8","FS"]' \
        -s ENVIRONMENT=web \
        -s USE_SDL=2 -s USE_ZLIB=1 -s USE_LIBPNG=1 -s USE_SDL_IMAGE=2 -s USE_SDL_MIXER=2 -s USE_SDL_TTF=2 \
        -s SDL2_IMAGE_FORMATS='["bmp","png","jpg"]' \
        -s SDL2_MIXER_FORMATS='["ogg","wav","mp3"]' \
        -s FORCE_FILESYSTEM=1 \
        -s INITIAL_MEMORY=$INITIAL_MEMORY -s MAXIMUM_MEMORY=2gb -s ALLOW_MEMORY_GROWTH=1 \
        -s EXIT_RUNTIME=0 -s ASSERTIONS=$ASSERTIONS -s ERROR_ON_UNDEFINED_SYMBOLS=0 \
        -s MODULARIZE=1 -s EXPORT_ES6=1 -s EXPORT_NAME=createPhpModule -s INVOKE_RUN=0 -s LZ4=1 \
        -lidbfs.js -lopenal -lGL \
        --js-library /src/php-wasm-network/js/library_wasmnet.js \
        /src/phpw.o .libs/libphp.a \
    && cp /src/php-wasm-network/js/php-wasm-network.js /build/ \
    && PHP_MINOR="$(sed -nE 's/^#define PHP_VERSION "([0-9]+\.[0-9]+).*/\1/p' /src/php-src/main/php_version.h)" \
    && echo "{\"php\":\"$PHP_MINOR\",\"network\":\"$NETWORK_REF\",\"opengl\":true,\"openal\":true}" > /build/engine.json

FROM scratch
COPY --from=php-wasm /build/ .
