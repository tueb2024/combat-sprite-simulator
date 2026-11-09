# The async runtime (PHP 8.6 ZTS + TrueAsync) with Xdebug: what bin/jumphp uses when JUMPHP_XDEBUG is set for a game
# with runtime: async. Built from the async image of this computer the first time something is debugged; the async
# image itself stays without it. Xdebug is built from its sources (the image has phpize and PHP's headers, no compiler):
# its development branch, at a fixed commit (the 3.5 releases stop below PHP 8.6).
ARG BASE=jumphp/desktop-async:dev
FROM ${BASE}
ARG XDEBUG_REF=64007df3a0925808022fb87b6c6f06febf058ee2
USER root
RUN set -eux; \
    apt-get update; apt-get install -y --no-install-recommends build-essential autoconf ca-certificates curl; \
    mkdir -p /usr/src/xdebug; \
    curl -fsSL "https://github.com/xdebug/xdebug/archive/${XDEBUG_REF}.tar.gz" | tar -xz --strip-components=1 -C /usr/src/xdebug; \
    cd /usr/src/xdebug; phpize; ./configure --enable-xdebug; make -j"$(nproc)"; make install; \
    cd /; rm -rf /usr/src/xdebug; \
    apt-get purge -y --auto-remove build-essential autoconf; rm -rf /var/lib/apt/lists/*; \
    { echo 'zend_extension=xdebug'; \
      echo 'xdebug.mode=debug'; \
      echo 'xdebug.start_with_request=yes'; \
      echo 'xdebug.client_host=127.0.0.1'; \
      echo 'xdebug.client_port=9003'; \
      echo 'xdebug.connect_timeout_ms=1000'; \
      echo 'xdebug.max_nesting_level=1024'; \
      echo 'xdebug.log_level=0'; } > /usr/local/etc/php/conf.d/zz-xdebug.ini; \
    php -m | grep -i xdebug
