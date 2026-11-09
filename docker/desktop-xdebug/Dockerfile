# The desktop runtime with Xdebug (step debugging): what bin/jumphp uses when JUMPHP_XDEBUG is set (the IDE's
# debugger, bin/jumphp debug:mcp). Built the first time something is debugged, from the desktop image; the desktop
# image itself stays without it (Xdebug slows PHP down). Also made from the Tauri image (desktop apps): there the
# compilers stay (Rust needs them).
ARG BASE=jumphp/desktop:dev
FROM ${BASE}
USER root
RUN set -eux; \
    apt-get update; apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install xdebug-3.5.3; \
    command -v cargo >/dev/null || apt-get purge -y --auto-remove $PHPIZE_DEPS; rm -rf /var/lib/apt/lists/* /tmp/pear; \
    { echo 'zend_extension=xdebug'; \
      echo 'xdebug.mode=debug'; \
      echo 'xdebug.start_with_request=yes'; \
      echo 'xdebug.client_host=127.0.0.1'; \
      echo 'xdebug.client_port=9003'; \
      echo 'xdebug.connect_timeout_ms=1000'; \
      echo 'xdebug.max_nesting_level=1024'; \
      echo 'xdebug.log_level=0'; } > /usr/local/etc/php/conf.d/zz-xdebug.ini; \
    php -m | grep -i xdebug
