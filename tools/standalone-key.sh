#!/usr/bin/env bash
# The identity of a standalone micro (PHP + JumPHP's extensions): PHP's version, the extensions, StaticPHP's commit
# and JumPHP's registry and patches. The same in bin/jumphp export:standalone and in the GitHub workflow that
# publishes the micro binaries (release standalone-<key>). Run from the project's root.
set -euo pipefail
echo "${JUMPHP_STANDALONE_PHP:-8.5}"
# JUMPHP_STANDALONE_EXTENSIONS: another list (desktop apps: tools/app-extensions.txt); unset, the games' one
cat "${JUMPHP_STANDALONE_EXTENSIONS:-tools/standalone-extensions.txt}"
find docker/standalone docker/ext/php-opengl.patch docker/ext/php-sdl-events.patch -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum
