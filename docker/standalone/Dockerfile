# Standalone games (bin/jumphp export:standalone): static-php-cli (StaticPHP v3, pinned) builds PHP's micro SAPI
# with JumPHP's extensions (SDL2 and its add-ons, OpenGL, OpenAL: the registry in docker/standalone/registry);
# the game is appended to it as a PHAR: one executable. Linux: glibc 2.17 (Zig), the display and the sound loaded
# from the player's system at launch.
FROM php:8.4-cli-bookworm
ARG SPC_REF=39e0b8a
COPY spc-registries.php /tmp/
RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl unzip xz-utils make autoconf automake libtool pkg-config bison re2c patch file flex gperf autopoint \
        libx11-dev libxext-dev libxrandr-dev libxcursor-dev libxi-dev libxss-dev libxinerama-dev libxfixes-dev \
        libgl-dev libegl-dev libasound2-dev libpulse-dev \
    && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL -A jumphp-standalone -o /usr/local/bin/composer https://getcomposer.org/download/2.8.12/composer.phar \
    && chmod 755 /usr/local/bin/composer \
    && curl -fsSL -A jumphp-standalone https://github.com/Kitware/CMake/releases/download/v3.31.8/cmake-3.31.8-linux-x86_64.tar.gz \
       | tar -xz -C /opt && ln -sf /opt/cmake-3.31.8-linux-x86_64/bin/cmake /usr/local/bin/cmake \
    && git clone -q https://github.com/crazywhalecc/static-php-cli.git /opt/spc && cd /opt/spc && git checkout -q "$SPC_REF" \
    && composer install -q --no-dev --no-interaction && php /tmp/spc-registries.php \
    && ln -s /opt/spc/bin/spc /usr/local/bin/spc && spc --version && chmod -R a+rwX /opt/spc
WORKDIR /opt/spc
