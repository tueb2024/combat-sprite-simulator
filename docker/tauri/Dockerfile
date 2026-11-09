# JumPHP desktop apps (Tauri: an HTML / CSS / JS window driven by PHP): the desktop runtime (same PHP 8.5, same
# extensions) + PHP's FFI + Rust + the WebKitGTK / GTK headers that Tauri needs on Linux. Used by `bin/jumphp tauri:build`
# (compiles tauri/ -> build/tauri/linux-x86_64/libjumphp_tauri.so), `app:run`, `app:test`, `app:shot` and `app:export`.
# The other images (desktop, desktop-async, engine-wasm...) are not touched: games never need this one.
ARG BASE=jumphp/desktop:dev
FROM ${BASE}

ARG RUST_VERSION=1.95.0

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential curl file libffi-dev \
        libwebkit2gtk-4.1-dev libgtk-3-dev librsvg2-dev libsoup-3.0-dev libjavascriptcoregtk-4.1-dev \
        libxdo-dev libssl-dev libayatana-appindicator3-dev \
        xauth x11-utils xdotool imagemagick dbus dbus-x11 \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install ffi \
    && php -m | grep -i '^ffi$'

# Rust, for everyone (the runs use --user <host uid>): toolchain read-only, CARGO_HOME (registry) mounted at run time
ENV RUSTUP_HOME=/opt/rust/rustup \
    CARGO_HOME=/opt/rust/cargo \
    PATH=/opt/rust/cargo/bin:$PATH
RUN curl -fsSL https://sh.rustup.rs | sh -s -- -y --no-modify-path --profile minimal --default-toolchain "$RUST_VERSION" \
    && chmod -R a+rX /opt/rust && rustc --version && cargo --version

# FFI for scripts run by the php command (CLI), which is what an app is: ffi.enable=preload is PHP's default
RUN echo 'ffi.enable=preload' > /usr/local/etc/php/conf.d/zz-jumphp-ffi.ini

ENV JUMPHP_TAURI_IMAGE=1
WORKDIR /workspace
