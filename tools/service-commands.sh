# shellcheck shell=bash
# service:install / uninstall / status / logs / start / stop / print <id>: the project's exported executable as a service of
# this computer (systemd, launchd, WinSW: tools/service.sh, docs/services.md), from project.yaml `service:`.
# Sourced by bin/jumphp.

cmd_service() {
  local action="$1" id="${2:-}"; shift 2 || true
  local dir; dir="$(project_dir "$id")"
  local extra=() exe=""
  while [ $# -gt 0 ]; do
    case "$1" in
      --system|--follow|-f) extra+=("$1") ;;
      --exe) exe="$2"; shift ;;
      *) die "unknown option $1 (--system, --exe <file>, --follow)" ;;
    esac
    shift
  done
  local SVC_ID SVC_NAME SVC_DESCRIPTION SVC_RESTART SVC_SYSTEM SVC_DEFINED SVC_ARGS
  eval "$(php_run . -- php tools/service-config.php "$dir")"
  [ "$SVC_DEFINED" = 1 ] || [ "$action" != install ] || warn "$dir has no service: section in its project.yaml (name, args, restart, user): the program runs without arguments"
  [ "$SVC_SYSTEM" = 1 ] && extra+=(--system)
  if [ -z "$exe" ]; then
    local target
    case "$(uname -s)/$(uname -m)" in
      Linux/*) target=linux-x86_64 ;;
      Darwin/arm64) target=macos-arm64 ;;
      Darwin/*) target=macos-x86_64 ;;
      *) target=windows-x86_64.exe ;;
    esac
    exe="$ROOT/$dir/dist/standalone/$id-$target"
  fi
  if [ "$action" = install ] && [ ! -f "$exe" ]; then
    die "no $exe: bin/jumphp export:standalone $id first (or --exe <file>)"
  fi
  local a args=()
  for a in "${SVC_ARGS[@]}"; do args+=(--arg "$a"); done
  bash "$ROOT/tools/service.sh" "$action" --name "$SVC_NAME" --exec "$exe" --description "$SVC_DESCRIPTION" \
    --restart "$SVC_RESTART" "${args[@]}" "${extra[@]}"
}
