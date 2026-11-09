#!/usr/bin/env bash
# End-to-end tests of the JumPHP runtime with real browsers (docs/runtime.md, apps/runtime/tests/e2e/README.md).
#   tools/e2e/run.sh [chrome|firefox|all]      (default: chrome)
# Starts the runtime daemon (Docker, Xvfb, throw-away HOME), a https test server (self-signed certificate) and headless
# browsers (puppeteer image), prints PASS / FAIL per check and cleans up. Exit code != 0 when a check fails.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
E2E="$ROOT/apps/runtime/tests/e2e"
WHICH="${1:-chrome}"
case "$WHICH" in chrome|firefox|all) ;; -h|--help) sed -n 2,6p "$0"; exit 0 ;; *) echo "usage: tools/e2e/run.sh [chrome|firefox|all]" >&2; exit 2 ;; esac

PUPPETEER_IMAGE="${JUMPHP_PUPPETEER_IMAGE:-ghcr.io/puppeteer/puppeteer:24.10.0}"
FIREFOX_IMAGE="${JUMPHP_E2E_FIREFOX_IMAGE:-jumphp-e2e-firefox:dev}"
TAURI_IMAGE="${JUMPHP_TAURI_IMAGE:-jumphp/tauri:dev}"
TAURI_LIB="build/tauri/linux-x86_64/libjumphp_tauri.so"
RUNTIME_PORT="${E2E_RUNTIME_PORT:-47823}"   # not 47820: proves the scan, and a runtime already running on this computer does not interfere
PORT_OK="${E2E_PORT_OK:-8443}"
PORT_BAD="${E2E_PORT_BAD:-8444}"
CACHE="${JUMPHP_E2E_CACHE:-$HOME/.cache/jumphp-e2e}"   # Firefox download (puppeteer browsers install firefox), kept between runs
UIDGID="$(id -u):$(id -g)"
PREFIX="jumphp-e2e-$$"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/jumphp-e2e.XXXXXX")"

say() { printf '\033[1m==> %s\033[0m\n' "$*"; }
die() { echo "e2e: $*" >&2; exit 2; }

cleanup() {
  docker rm -f "$PREFIX-runtime" "$PREFIX-web" >/dev/null 2>&1
  rm -rf "$TMP"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

command -v docker >/dev/null || die "docker is needed"
docker image inspect "$PUPPETEER_IMAGE" >/dev/null 2>&1 || { say "pulling $PUPPETEER_IMAGE"; docker pull "$PUPPETEER_IMAGE" >/dev/null || die "cannot pull $PUPPETEER_IMAGE"; }
docker image inspect "$TAURI_IMAGE" >/dev/null 2>&1 || die "image $TAURI_IMAGE missing: run bin/jumphp app:test runtime once (builds it)"
[ -f "$ROOT/$TAURI_LIB" ] || die "$TAURI_LIB missing: run bin/jumphp app:test runtime once"
[ -d "$ROOT/apps/runtime/vendor" ] || die "apps/runtime/vendor missing: run bin/jumphp install runtime"

# the daemon's data folder: isolated HOME, config.json adds the test origin and pins the port
mkdir -p "$TMP/home/.config/jumphp-runtime" "$TMP/certs" "$TMP/pphome"
cat > "$TMP/home/.config/jumphp-runtime/config.json" <<JSON
{"port": $RUNTIME_PORT, "origins": ["https://localhost:$PORT_OK"]}
JSON
chmod -R a+rwX "$TMP"

say "starting the runtime daemon (Xvfb, HOME=$TMP/home, port $RUNTIME_PORT)"
docker run -d --name "$PREFIX-runtime" --network host --user "$UIDGID" -v "$ROOT:/workspace" -w /workspace/apps/runtime \
  -v "$TMP/home:/e2ehome" -e HOME=/e2ehome -e XDG_RUNTIME_DIR=/tmp -e "JUMPHP_TAURI_LIB=/workspace/$TAURI_LIB" \
  "$TAURI_IMAGE" jumphp-xvfb php app.php >/dev/null || die "cannot start the daemon"
for _ in $(seq 1 120); do
  (exec 3<>"/dev/tcp/127.0.0.1/$RUNTIME_PORT") 2>/dev/null && break
  docker ps -q --filter "name=$PREFIX-runtime" | grep -q . || { docker logs "$PREFIX-runtime" 2>&1 | tail -20; die "the daemon stopped"; }
  sleep 0.5
done
(exec 3<>"/dev/tcp/127.0.0.1/$RUNTIME_PORT") 2>/dev/null || { docker logs "$PREFIX-runtime" 2>&1 | tail -20; die "the daemon does not listen on $RUNTIME_PORT"; }

say "starting the https test server (self-signed certificate)"
docker run -d --name "$PREFIX-web" --network host --user "$UIDGID" -e HOME=/tmp -e CERT_DIR=/certs -e PORT_OK="$PORT_OK" -e PORT_BAD="$PORT_BAD" \
  -v "$TMP/certs:/certs" -v "$E2E:/e2e:ro" -v "$ROOT/engine/web/jumphp-runtime.js:/engine/jumphp-runtime.js:ro" \
  "$PUPPETEER_IMAGE" node /e2e/serve.cjs >/dev/null || die "cannot start the web server"
for _ in $(seq 1 60); do
  (exec 3<>"/dev/tcp/127.0.0.1/$PORT_BAD") 2>/dev/null && break
  sleep 0.25
done

run_browser() {   # run_browser <chrome|firefox|node ...>
  local env=(-e PUPPETEER_CACHE_DIR=/home/pptruser/.cache/puppeteer) mounts=() image="$PUPPETEER_IMAGE"
  if [[ " $* " == *" firefox "* ]]; then env=(-e PUPPETEER_CACHE_DIR=/cache); mounts=(-v "$CACHE:/cache"); image="$FIREFOX_IMAGE"; fi
  docker run --rm --network host --init --user "$UIDGID" -e HOME=/tmp/pphome -v "$TMP/pphome:/tmp/pphome" -e NODE_PATH=/home/pptruser/node_modules \
    -e E2E_DEBUG="${E2E_DEBUG:-}" -e RUNTIME_PORT="$RUNTIME_PORT" -e PORT_OK="$PORT_OK" -e PORT_BAD="$PORT_BAD" ${env[@]+"${env[@]}"} ${mounts[@]+"${mounts[@]}"} \
    -v "$E2E:/e2e:ro" -w /home/pptruser "$image" node /e2e/run.cjs "$@"
}

status=0
targets=(node)
case "$WHICH" in chrome) targets+=(chrome) ;; firefox) targets+=(firefox) ;; all) targets+=(chrome firefox) ;; esac
if [[ " ${targets[*]} " == *" firefox "* ]]; then
  mkdir -p "$CACHE"; chmod a+rwx "$CACHE"
  if ! docker image inspect "$FIREFOX_IMAGE" >/dev/null 2>&1; then
    say "building $FIREFOX_IMAGE (the puppeteer image + Firefox's system libraries, once)"
    docker build -q --build-arg "BASE=$PUPPETEER_IMAGE" -f "$ROOT/tools/e2e/Dockerfile.firefox" -t "$FIREFOX_IMAGE" "$ROOT/tools/e2e" >/dev/null || die "cannot build $FIREFOX_IMAGE"
  fi
  if ! ls "$CACHE"/firefox >/dev/null 2>&1; then
    say "downloading Firefox for puppeteer (once, kept in $CACHE)"
    docker run --rm --network host --user "$UIDGID" -e HOME=/tmp -e PUPPETEER_CACHE_DIR=/cache -v "$CACHE:/cache" -w /home/pptruser "$PUPPETEER_IMAGE" \
      npx --no-install puppeteer browsers install firefox || { echo "FAIL  [firefox] cannot download Firefox"; targets=("${targets[@]/firefox}"); status=1; }
  fi
fi
say "checks: ${targets[*]}"
for t in "${targets[@]}"; do [ -n "$t" ] && { run_browser "$t" || status=1; }; done

echo
if [ "$status" -eq 0 ]; then echo "E2E: PASS"; else echo "E2E: FAIL (daemon log: $PREFIX-runtime removed; re-run with E2E_KEEP=1 to keep it)"; fi
[ -n "${E2E_KEEP:-}" ] && { trap - EXIT; echo "kept: $TMP and containers $PREFIX-*"; }
exit "$status"
