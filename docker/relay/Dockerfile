# Multiplayer relay / lobby / WebRTC signalling server of php-wasm-network (plain PHP, no dependency).
# Same commit as the engine's wasmnet extension (docker/engine-wasm NETWORK_REF).
FROM php:8.5-cli-alpine
ARG NETWORK_REF=d273712204a378f656fd3e73cc0e06994249c464
RUN apk add --no-cache git \
    && docker-php-ext-install pcntl \
    && mkdir /app && cd /app && git init -q && git remote add origin https://git.jumphp.net/jumphp/php-wasm-network.git \
    && git fetch -q --depth 1 origin "$NETWORK_REF" && git checkout -q FETCH_HEAD && rm -rf .git \
    && apk del git
ENV PHP_WASM_NETWORK_LISTEN=tcp://0.0.0.0:8090
EXPOSE 8090
USER nobody
CMD ["php", "-d", "memory_limit=256M", "/app/server/bin/server.php"]
