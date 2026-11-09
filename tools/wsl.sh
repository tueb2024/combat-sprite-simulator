# Windows with Docker Engine inside a WSL distribution (no Docker Desktop), sourced by bin/jumphp: started from Git for
# Windows' bash, bin/jumphp runs itself again inside WSL when only WSL's Docker answers (docs/windows-wsl.md).
#   JUMPHP_DOCKER=wsl     always inside WSL (the IDE sets it once it knows; also in .env)
#   JUMPHP_DOCKER=native  never (Docker Desktop)
# Functions only: tools/tests/wsl.test.sh tries them with stubbed commands.

jumphp_on_windows() {
  case "$(uname -s)" in MINGW*|MSYS*|CYGWIN*) return 0 ;; *) return 1 ;; esac
}

# a folder as WSL's Linux sees it: C:\x, C:/x, /c/x -> /mnt/c/x; \\wsl$\Distro\home\x, //wsl.localhost/Distro/home/x -> /home/x
jumphp_wsl_path() {
  local p="${1//\\//}" rest
  case "$p" in
    //wsl\$/*|//wsl.localhost/*|//WSL\$/*)
      rest="${p#//*/}"; rest="${rest#*/}"
      [ "$rest" = "${p#//*/}" ] && rest=""      # no folder after the distribution's name
      rest="/${rest%/}"; echo "${rest%/}" | sed 's#^$#/#' ;;
    [A-Za-z]:|[A-Za-z]:/*)
      rest="${p:2}"; echo "/mnt/$(printf '%s' "${p:0:1}" | tr 'A-Z' 'a-z')${rest%/}" ;;
    /mnt/*|/home/*) echo "${p%/}" ;;
    /[A-Za-z]|/[A-Za-z]/*)
      rest="${p:2}"; echo "/mnt/$(printf '%s' "${p:1:1}" | tr 'A-Z' 'a-z')${rest%/}" ;;
    *) return 1 ;;
  esac
}

# does WSL's Docker answer (and not Windows' own)? JUMPHP_DOCKER decides when it is set
jumphp_wsl_wanted() {
  jumphp_on_windows || return 1
  case "${JUMPHP_DOCKER:-}" in wsl) return 0 ;; native) return 1 ;; esac
  command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1 && return 1
  command -v wsl.exe >/dev/null 2>&1 && wsl.exe -e docker info >/dev/null 2>&1
}

# the bash -lc script: cd to where the command was typed, then bin/jumphp with its arguments, each quoted
jumphp_wsl_script() {
  local root caller arg out
  root="$(jumphp_wsl_path "$1")" || return 1
  caller="$(jumphp_wsl_path "$2")" || caller="$root"
  shift 2
  printf -v out 'cd %q && JUMPHP_DOCKER=wsl exec %q/bin/jumphp' "$caller" "$root"
  for arg in "$@"; do printf -v out '%s %q' "$out" "$arg"; done
  printf '%s\n' "$out"
}

# the JUMPHP_* variables of this run carried into WSL (WSLENV), then bin/jumphp again over there
jumphp_wsl_exec() {
  local root="$1" caller="$2" script names
  shift 2
  script="$(jumphp_wsl_script "$root" "$caller" "$@")" || { echo "[x] $caller cannot be reached from WSL" >&2; exit 1; }
  names="$(compgen -e | grep '^JUMPHP_' | tr '\n' ':')"
  export WSLENV="${WSLENV:+$WSLENV:}${names%:}"
  # Git for Windows' bash would rewrite arguments that look like paths for a Windows program: not these
  MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' exec wsl.exe -e bash -lc "$script"
}
