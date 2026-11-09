# shellcheck shell=bash
# CLI apps (config/game.yaml kind: cli, skeleton-cli/, docs/cli.md): applications that live in the terminal,
# commands (Symfony Console) and full-screen text screens (symfony/tui). Sourced by bin/jumphp: run / test / shot /
# export:standalone on a CLI app's folder come here.

is_cli() {
  [ -n "${1:-}" ] && grep -qE '^[[:space:]]*kind:[[:space:]]*["'"'"']?cli' "$(project_yaml "$ROOT/$(project_path "$1")")" 2>/dev/null
}

cmd_cli_new() {
  local name="${1:-}" title="${2:-}"
  [[ "$name" =~ ^[a-z][a-z0-9-]*$ ]] || die "app name: lower case letters, digits and dashes (e.g. todo)"
  [ -z "$(project_path "$name")" ] || die "$(project_path "$name") already exists"
  [ -n "$title" ] || title="$(echo "$name" | tr '-' ' ' | awk '{for (i = 1; i <= NF; i++) $i = toupper(substr($i, 1, 1)) substr($i, 2)} 1')"
  mkdir -p apps
  cp -r skeleton-cli "apps/$name"
  php_run "apps/$name" -- php /workspace/tools/new-app.php "$name" "$title"
  say "apps/$name created. Next: bin/jumphp install $name && bin/jumphp run $name"
}

# bin/jumphp run <app> [arguments...]: the app in this terminal (its arguments: a command and its options)
cmd_cli_run() {
  local dir; dir="$(project_dir "${1:-}")"; shift || true
  ensure_vendor "$dir"
  php_run "$dir" -e "TERM=${TERM:-xterm-256color}" -e "COLUMNS=$(tput cols 2>/dev/null || echo 80)" -e "LINES=$(tput lines 2>/dev/null || echo 24)" -- php app.php "$@"
}

cmd_cli_test() {
  local dir; dir="$(project_dir "${1:-}")"
  ensure_vendor "$dir"
  [ -d "$ROOT/$dir/tests" ] && ensure_engine_tools
  if [ -n "${2:-}" ]; then
    local only="${2#"$dir"/}"
    [ -f "$ROOT/$dir/$only" ] || die "no test file $dir/$only"
    say "$dir: phpunit $only"
    php_run "$dir" -- /workspace/engine/vendor/bin/phpunit --bootstrap vendor/autoload.php --testdox "$only"
    return
  fi
  say "$dir: php -l"
  php_run "$dir" -- sh -c 'find src app.php -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null'
  if [ -f "$ROOT/$dir/phpstan.neon" ] && [ -x "$ROOT/engine/vendor/bin/phpstan" ]; then
    say "$dir: phpstan"
    php_run "$dir" -- /workspace/engine/vendor/bin/phpstan analyse --no-progress --memory-limit=1G
  fi
  if [ -d "$ROOT/$dir/tests" ] && [ -x "$ROOT/engine/vendor/bin/phpunit" ]; then
    say "$dir: phpunit (commands, and the screens on a virtual terminal)"
    php_run "$dir" -- /workspace/engine/vendor/bin/phpunit --bootstrap vendor/autoload.php --testdox tests
  fi
  say "$dir: smoke test (php app.php list: every command loads, no PHP warning)"
  local out; out="$(php_run "$dir" -- php -d display_errors=stderr -d error_reporting=-1 app.php list --no-ansi 2>&1)" \
    || { echo "$out"; die "php app.php list failed"; }
  if grep -qE '(Warning|Notice|Deprecated|Fatal error):' <<<"$out"; then echo "$out"; die "PHP warnings (above)"; fi
  say "$dir: all checks passed"
}

# bin/jumphp shot <app> [seconds] [out.txt]: the app in a pseudo-terminal (JUMPHP_COLUMNS x JUMPHP_ROWS, 100 x 30),
# JUMPHP_KEYS='[[1,"a"],[1.5,"\r"]]' typed at those seconds, JUMPHP_ARGS its arguments; the screen as text
# (a fresh var/ each time, as the headless runs of games: JUMPHP_VAR=<dir> to give one)
cmd_cli_shot() {
  local dir; dir="$(project_dir "${1:-}")"
  local seconds="${2:-2}" out="${3:-var/screenshots/screen.txt}"
  ensure_vendor "$dir"
  local args=()
  [ -n "${JUMPHP_ARGS:-}" ] && read -r -a args <<<"$JUMPHP_ARGS"
  php_run "$dir" -e TERM=xterm-256color -e "JUMPHP_VAR=${JUMPHP_VAR:-/tmp/cli-var}" -- php /workspace/tools/cli-shot.php "$out" "${JUMPHP_COLUMNS:-100}" "${JUMPHP_ROWS:-30}" \
    "$seconds" "${JUMPHP_KEYS:-[]}" -- php app.php ${args[@]+"${args[@]}"} >/dev/null || true
  [ -f "$ROOT/$dir/$out" ] || die "no screen written"
  cat "$ROOT/$dir/$out"
  echo "$ROOT/$dir/$out"
}

# export:standalone of a CLI app: the apps' PHP (no SDL), the app's Main, a console kept on Windows
cmd_cli_export() {
  local app="${1:-}"; shift || true
  local flags=() macos=""
  while [ $# -gt 0 ]; do
    case "$1" in
      --macos) macos=1 ;;
      *) flags+=("$1") ;;
    esac
    shift
  done
  [ ${#flags[@]} -gt 0 ] || [ -n "$macos" ] || flags=(--linux)
  [ -n "$macos" ] && flags+=(--macos-arm64 --macos-x86_64)
  JUMPHP_STANDALONE_EXTENSIONS=tools/app-extensions.txt JUMPHP_STANDALONE_SPC=spc-app \
  JUMPHP_STANDALONE_BOOT='App\Main' JUMPHP_STANDALONE_CONSOLE=1 JUMPHP_STANDALONE_INI=$'ffi.enable=1\nmemory_limit=512M' \
    cmd_export_standalone "$app" "${flags[@]}"
  echo "  A CLI app: run it in a terminal (<file> --help). macOS: <app>-macos-arm64 (Apple silicon) / -x86_64 (Intel)." >&2
}
