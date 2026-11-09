# OpenSSH's ssh client as WebAssembly (emscripten): the client the browser engine pipes to (JumPHP\Net\Ssh).
# bin/jumphp ssh:build -> build/ssh/ssh.js + ssh.wasm (stdin / stdout = the pipe, the network = a raw WebSocket to the TCP bridge).
FROM emscripten/emsdk:3.1.35 AS build
RUN apt-get update && apt-get --no-install-recommends -y install autoconf automake make perl git ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*
ARG OPENSSL_VERSION=3.0.15
ARG OPENSSH_VERSION=9.9p1
WORKDIR /src
RUN curl -fsSL https://github.com/openssl/openssl/releases/download/openssl-$OPENSSL_VERSION/openssl-$OPENSSL_VERSION.tar.gz | tar xz \
    && curl -fsSL https://cdn.openbsd.org/pub/OpenBSD/OpenSSH/portable/openssh-$OPENSSH_VERSION.tar.gz | tar xz
# libcrypto only (no assembly, threads, sockets, shared libraries)
RUN cd openssl-$OPENSSL_VERSION && CC=emcc AR=emar RANLIB=emranlib ./Configure linux-generic32 no-asm no-threads no-shared no-dso no-engine no-afalgeng \
        no-tests no-ui-console no-sock --prefix=/opt/openssl -DOPENSSL_NO_SECURE_MEMORY -D__STDC_NO_ATOMICS__ \
    && make -j$(nproc) build_libs && mkdir -p /opt/openssl/lib /opt/openssl/include \
    && cp -r include/openssl /opt/openssl/include/ && cp libcrypto.a /opt/openssl/lib/
COPY patch-openssh.sh stubs.c poll.js link.sh build.sh /src/
RUN embuilder build zlib >/dev/null 2>&1; cd openssh-$OPENSSH_VERSION && sh /src/patch-openssh.sh && cp /src/stubs.c /src/poll.js /src/link.sh /src/build.sh . \
    && emconfigure ./configure --host=wasm32-unknown-emscripten --with-ssl-dir=/opt/openssl --without-openssl-header-check --without-pam \
        --without-hardening --disable-security-key --disable-pkcs11 --without-zlib-version-check --without-stackprotect --disable-strip \
        --without-sandbox --with-privsep-user=nobody CFLAGS="-O2 -sUSE_ZLIB=1 -Wno-implicit-function-declaration -Wno-int-conversion" \
        LDFLAGS="-sUSE_ZLIB=1 -L/opt/openssl/lib" >/dev/null \
    && sed -i "s/-mretpoline//; s/-Wl,-z,retpolineplt//" Makefile && sh build.sh \
    && mkdir /out && cp ssh.js ssh.wasm /out/

FROM scratch AS out
COPY --from=build /out/ /
