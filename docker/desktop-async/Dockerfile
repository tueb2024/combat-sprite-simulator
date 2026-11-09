# JumPHP desktop runtime, "async" flavour: PHP 8.6 ZTS from the TrueAsync fork (coroutines, threads,
# thread pools, channels: https://true-async.github.io) + the same SDL extensions as docker/desktop.
# Used by games whose config/game.yaml says `runtime: async`. Everything else is identical to the default
# runtime (composer, phpunit, phpstan, headless smoke tests with the SDL dummy drivers).
FROM debian:bookworm-slim AS build

# TrueAsync release (true-async/releases build-config.json): php-src branch + ext/async tag + libuv
ARG PHP_SRC_BRANCH=php-8.6.0-trueasync-0.11.3
ARG ASYNC_REF=v0.11.3
ARG LIBUV_VERSION=1.52.1
ARG PHP_SDL_REF=89855c8f4f63b77f9a548007b9c4afe366785b4b
ARG SDL_IMAGE_REF=fd798eb2c52b7be5874a30334d1d255436aa686e
ARG SDL_MIXER_REF=f75a31826f4484bb4af31c1a1a4cdf1463aae8b2
ARG SDL_TTF_REF=d2c634c509aa0b14168f55128435d9519d34d0e3
ARG PHP_OPENGL_REF=438798d5f0a5347a59d13252611556792cc2a1b8
ARG PHP_OPENAL_REF=6f78e95120ffe2cd30813a78ca66eaedde310314

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates git curl build-essential autoconf automake libtool bison re2c pkg-config cmake ninja-build \
        libxml2-dev libsqlite3-dev libonig-dev libzip-dev libpng-dev libfreetype-dev libssl-dev zlib1g-dev \
        libsdl2-dev libsdl2-image-dev libsdl2-ttf-dev libsdl2-mixer-dev \
    && rm -rf /var/lib/apt/lists/*

# libuv >= 1.45 (bookworm ships 1.44)
RUN curl -fsSL "https://github.com/libuv/libuv/archive/v${LIBUV_VERSION}.tar.gz" | tar -xz -C /usr/src \
    && cmake -S /usr/src/libuv-${LIBUV_VERSION} -B /usr/src/libuv-build -G Ninja \
        -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/usr/local -DLIBUV_BUILD_TESTS=OFF \
    && ninja -C /usr/src/libuv-build install && ldconfig

RUN git clone -q --depth 1 --branch "$PHP_SRC_BRANCH" https://github.com/true-async/php-src.git /usr/src/php \
    && git clone -q --depth 1 --branch "$ASYNC_REF" https://github.com/true-async/php-async.git /usr/src/php/ext/async

RUN cd /usr/src/php && ./buildconf --force \
    && PKG_CONFIG_PATH=/usr/local/lib/pkgconfig ./configure \
        --prefix=/usr/local --with-config-file-path=/usr/local/etc/php --with-config-file-scan-dir=/usr/local/etc/php/conf.d \
        --enable-zts --enable-async \
        --disable-cgi --disable-phpdbg --without-pear \
        --enable-mbstring --enable-bcmath --enable-pcntl --enable-sockets \
        --enable-gd --with-freetype --with-zip --with-openssl --with-zlib \
    && make -j"$(nproc)" && make install

FROM build AS sdl
ARG PHP_SDL_REF
ARG SDL_IMAGE_REF
ARG SDL_MIXER_REF
ARG SDL_TTF_REF
COPY php86-compat.h /usr/src/php86-compat.h
COPY --from=ext php-sdl-events.patch /usr/src/php-sdl-events.patch
# fetch <repo> <ref> <dir>: one pinned commit, no history (same SDL commits as docker/desktop)
RUN set -eux; \
    fetch() { mkdir -p "$3" && cd "$3" && git init -q && git remote add origin "https://$1.git" \
              && git fetch -q --depth 1 origin "$2" && git checkout -q FETCH_HEAD; }; \
    fetch git.jumphp.net/jumphp/php-sdl   "$PHP_SDL_REF"   /usr/src/ext-sdl; \
    (cd /usr/src/ext-sdl && git apply /usr/src/php-sdl-events.patch); \
    fetch github.com/kea/php-sdl-image     "$SDL_IMAGE_REF" /usr/src/ext-sdl_image; \
    fetch github.com/kea/php-sdl-mixer     "$SDL_MIXER_REF" /usr/src/ext-sdl_mixer; \
    fetch github.com/kea/php-sdl-ttf       "$SDL_TTF_REF"   /usr/src/ext-sdl_ttf; \
    # PHP 8.6: unregistering the manually registered SDL_Rect functions in MSHUTDOWN leaves a dangling
    # entry in the function table (segfault at exit); the engine destroys them itself
    sed -i 's/^\tzend_unregister_functions(ext_functions, -1, NULL);//' /usr/src/ext-sdl/src/rect.c; \
    build() { cd "$1" && phpize && CFLAGS="-O2 -include /usr/src/php86-compat.h" ./configure $2 && make -j"$(nproc)" && make install; }; \
    build /usr/src/ext-sdl       --with-sdl; \
    build /usr/src/ext-sdl_image --with-sdl_image; \
    build /usr/src/ext-sdl_mixer --with-sdl_mixer; \
    build /usr/src/ext-sdl_ttf   --enable-sdl_ttf

# OpenGL / OpenAL (same commits and patch as docker/desktop and the wasm engine); docker/ext = build context "ext"
ARG PHP_OPENGL_REF
ARG PHP_OPENAL_REF
RUN apt-get update && apt-get install -y --no-install-recommends libgl-dev libopenal-dev && rm -rf /var/lib/apt/lists/*
COPY --from=ext php-opengl.patch /usr/src/php-opengl.patch
RUN set -eux; \
    fetch() { mkdir -p "$3" && cd "$3" && git init -q && git remote add origin "https://$1.git" \
              && git fetch -q --depth 1 origin "$2" && git checkout -q FETCH_HEAD; }; \
    fetch github.com/Ponup/php-opengl "$PHP_OPENGL_REF" /usr/src/ext-opengl; \
    fetch github.com/Ponup/php-openal "$PHP_OPENAL_REF" /usr/src/ext-openal; \
    cd /usr/src/ext-opengl && git apply /usr/src/php-opengl.patch; \
    build() { cd "$1" && phpize && CFLAGS="-O2 -include /usr/src/php86-compat.h" ./configure $2 && make -j"$(nproc)" && make install; }; \
    build /usr/src/ext-opengl --with-opengl; \
    build /usr/src/ext-openal --with-openal

FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates git unzip \
        libxml2 libsqlite3-0 libonig5 libzip4 libpng16-16 libfreetype6 libssl3 zlib1g \
        libsdl2-2.0-0 libsdl2-image-2.0-0 libsdl2-ttf-2.0-0 libsdl2-mixer-2.0-0 \
        libgl1 libglx-mesa0 libgl1-mesa-dri libegl1 libgles2 libpulse0 libopenal1 xvfb \
    && rm -rf /var/lib/apt/lists/*
COPY --from=ext jumphp-xvfb /usr/local/bin/jumphp-xvfb

COPY --from=sdl /usr/local /usr/local
RUN ldconfig && mkdir -p /usr/local/etc/php/conf.d \
    && { for e in sdl sdl_image sdl_mixer sdl_ttf opengl openal; do echo "extension=$e"; done; } > /usr/local/etc/php/conf.d/sdl.ini \
    && { echo 'memory_limit=512M'; echo 'error_reporting=E_ALL'; echo 'display_errors=stderr'; } \
        > /usr/local/etc/php/conf.d/zz-jumphp.ini \
    && php -v && php -m | grep -iE '^(true_async|sdl|sdl_image|sdl_mixer|sdl_ttf|gd|opengl|openal)$'

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    JUMPHP_PLATFORM=desktop \
    JUMPHP_RUNTIME=async

WORKDIR /game
CMD ["php", "app.php"]
