# The desktop runtime with PHP Debugger (https://php-debugger.dev: a DBGp debugger compatible with Xdebug, much lighter):
# what bin/jumphp uses when JUMPHP_XDEBUG is set and JUMPHP_DEBUGGER=php-debugger (the IDE's "Debug with..." menu, the
# `dbgp mcp` server). Built the first time, from the desktop image (or the Tauri image: desktop apps), which stays
# without it. PHP 8.2 to 8.5: not the async runtime (PHP 8.6), which keeps Xdebug.
ARG BASE=jumphp/desktop:dev
FROM ${BASE}
USER root
RUN set -eux; \
    apt-get update; apt-get install -y --no-install-recommends $PHPIZE_DEPS unzip libtool git ca-certificates curl; \
    curl -fsSL https://github.com/php/pie/releases/latest/download/pie.phar -o /usr/local/bin/pie; chmod +x /usr/local/bin/pie; \
    pie install php-debugger/php-debugger; \
    command -v cargo >/dev/null || apt-get purge -y --auto-remove $PHPIZE_DEPS; rm -rf /var/lib/apt/lists/* /tmp/pear /root/.pie; \
    { echo 'php_debugger.mode=debug'; \
      echo 'php_debugger.start_with_request=yes'; \
      echo 'php_debugger.client_host=127.0.0.1'; \
      echo 'php_debugger.client_port=9003'; \
      echo 'php_debugger.connect_timeout_ms=1000'; \
      echo 'php_debugger.report_xdebug_module=1'; \
      echo 'xdebug.max_nesting_level=1024'; } > /usr/local/etc/php/conf.d/zz-php-debugger.ini; \
    php -v; php -m | grep -i -E 'debugger|xdebug'
