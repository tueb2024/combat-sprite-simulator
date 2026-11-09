# shellcheck shell=bash
# Plugins of the JumPHP IDE (config/game.yaml kind: plugin, skeleton-plugin/, docs/ide-plugins.md): PHP that runs
# inside the IDE. Sourced by bin/jumphp: plugin:new, plugin:pack, and test on a plugin's folder come here.

is_plugin() {
  [ -n "${1:-}" ] && grep -qE '^[[:space:]]*kind:[[:space:]]*["'"'"']?plugin' "$(project_yaml "$ROOT/$(project_path "$1")")" 2>/dev/null
}

cmd_plugin_new() {
  local name="${1:-}" title="${2:-}"
  [[ "$name" =~ ^[a-z][a-z0-9-]*$ ]] || die "plugin name: lower case letters, digits and dashes (e.g. todo-finder)"
  [ -z "$(project_path "$name")" ] || die "$(project_path "$name") already exists"
  [ -n "$title" ] || title="$(echo "$name" | tr '-' ' ' | awk '{for (i = 1; i <= NF; i++) $i = toupper(substr($i, 1, 1)) substr($i, 2)} 1')"
  mkdir -p apps
  cp -r skeleton-plugin "apps/$name"
  php_run "apps/$name" -- php /workspace/tools/new-plugin.php "$name" "$title"
  say "apps/$name created. Next: bin/jumphp install $name && bin/jumphp test $name"
}

cmd_plugin_test() {
  local dir; dir="$(project_dir "${1:-}")"
  ensure_vendor "$dir"
  ensure_engine_tools
  if [ -n "${2:-}" ]; then
    local only="${2#"$dir"/}"
    [ -f "$ROOT/$dir/$only" ] || die "no test file $dir/$only"
    php_run "$dir" -- /workspace/engine/vendor/bin/phpunit --bootstrap vendor/autoload.php --testdox "$only"
    return
  fi
  say "$dir: php -l"
  php_run "$dir" -- sh -c 'find src -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null'
  if [ -f "$ROOT/$dir/phpstan.neon" ] && [ -x "$ROOT/engine/vendor/bin/phpstan" ]; then
    say "$dir: phpstan"
    php_run "$dir" -- /workspace/engine/vendor/bin/phpstan analyse --no-progress --memory-limit=1G --autoload-file=vendor/autoload.php
  fi
  say "$dir: the plugin loads in the IDE's core (no PHP warning)"
  php_run "$dir" -- php -d display_errors=stderr -d error_reporting=-1 /workspace/tools/plugin-check.php . || die "the plugin does not load"
  if [ -d "$ROOT/$dir/tests" ]; then
    say "$dir: phpunit (PluginTestCase: the IDE's core, no window)"
    php_run "$dir" -- /workspace/engine/vendor/bin/phpunit --bootstrap vendor/autoload.php --testdox tests
  fi
  say "$dir: all checks passed"
}

# bin/jumphp plugin:pack <id>: dist/<id>-<version>.zip, what the IDE installs (Install from a File) and jumphp.net publishes
cmd_plugin_pack() {
  local dir; dir="$(project_dir "${1:-}")"
  is_plugin "${1:-}" || die "$dir is not an IDE plugin (config/project.yaml kind: plugin)"
  ensure_vendor "$dir"
  php_run "$dir" -- php /workspace/tools/plugin-pack.php . || die "packaging failed"
}

cmd_plugin_run() {
  die "an IDE plugin runs inside the JumPHP IDE: Settings > Plugins > Install from the project (docs/ide-plugins.md); bin/jumphp test ${1:-} plays it without a window"
}
