#!/usr/bin/env bash
# Tests of tools/wsl.sh (bin/jumphp inside WSL on Windows), with stubbed uname / docker / wsl.exe: bash tools/tests/wsl.test.sh
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$HERE/../wsl.sh"
fails=0
check() { if [ "$2" == "$3" ]; then echo "ok   $1"; else echo "FAIL $1: expected [$3], got [$2]"; fails=$((fails + 1)); fi; }

stubs="$(mktemp -d)"; trap 'rm -rf "$stubs"' EXIT
stub() { printf '#!/bin/sh\n%s\n' "$2" > "$stubs/$1"; chmod +x "$stubs/$1"; }
SAVED_PATH="$PATH"
with() { PATH="$stubs:$SAVED_PATH" "$@"; }

# paths
check 'C:\ path'          "$(jumphp_wsl_path 'C:\Users\x\Proj')" /mnt/c/Users/x/Proj
check 'C:/ path'          "$(jumphp_wsl_path 'C:/Users/x/My Game/')" '/mnt/c/Users/x/My Game'
check 'Git bash path'     "$(jumphp_wsl_path /d/work/game)" /mnt/d/work/game
check 'drive alone'       "$(jumphp_wsl_path 'E:')" /mnt/e
check 'wsl$ path'         "$(jumphp_wsl_path '\\wsl$\Ubuntu\home\x\proj')" /home/x/proj
check 'wsl.localhost'     "$(jumphp_wsl_path '//wsl.localhost/Ubuntu-24.04/home/x/')" /home/x
check 'distribution root' "$(jumphp_wsl_path '\\wsl$\Ubuntu')" /
check 'already Linux'     "$(jumphp_wsl_path /home/x/p)" /home/x/p
jumphp_wsl_path /tmp/x >/dev/null; check 'other path refused' "$?" 1

# the script: quoted arguments survive bash -lc as they were
script="$(jumphp_wsl_script 'C:\Users\x\Proj' 'C:\Users\x\Proj\games\my game' test "it's \"quoted\"" '$HOME' 'a;b')"
check 'script starts in the caller folder' "${script%% &&*}" "cd /mnt/c/Users/x/Proj/games/my\\ game"
stub bash-args 'for a in "$@"; do printf "<%s>" "$a"; done'
stub cd ':'
got="$(cd /tmp && PATH="$stubs:$SAVED_PATH" bash -c "$(printf '%s' "$script" | sed "s#^cd [^&]*&& JUMPHP_DOCKER=wsl exec [^ ]*/bin/jumphp#exec bash-args#")")"
check 'arguments arrive unchanged' "$got" "<test><it's \"quoted\"><\$HOME><a;b>"

# detection
stub uname 'echo Linux'
with jumphp_wsl_wanted; check 'Linux: never' "$?" 1
stub uname 'echo MINGW64_NT-10.0-22631'
stub docker 'exit 1'
stub wsl.exe '[ "$2" = docker ] && exit 0; exit 1'
with jumphp_wsl_wanted; check 'Windows, Docker only in WSL: WSL' "$?" 0
stub docker 'exit 0'
with jumphp_wsl_wanted; check 'Windows, Docker Desktop answers: not WSL' "$?" 1
stub docker 'exit 1'
stub wsl.exe 'exit 1'
with jumphp_wsl_wanted; check 'Windows, no Docker anywhere: not WSL (bin/jumphp says what to do)' "$?" 1
JUMPHP_DOCKER=wsl with jumphp_wsl_wanted; check 'JUMPHP_DOCKER=wsl forces it' "$?" 0
stub wsl.exe 'exit 0'
JUMPHP_DOCKER=native with jumphp_wsl_wanted; check 'JUMPHP_DOCKER=native never' "$?" 1

# the hop itself: wsl.exe gets bash -lc <script>, the JUMPHP_* variables in WSLENV
stub wsl.exe 'printf "%s|" "$@"; printf "WSLENV=%s" "$WSLENV"'
got="$(JUMPHP_SCENE='App\Scene\X' WSLENV= PATH="$stubs:$SAVED_PATH" bash -c ". '$HERE/../wsl.sh'; jumphp_wsl_exec 'C:\\p' 'C:\\p' shot pong")"
case "$got" in -e\|bash\|-lc\|cd\ /mnt/c/p\ \&\&\ JUMPHP_DOCKER=wsl\ exec\ /mnt/c/p/bin/jumphp\ shot\ pong\|WSLENV=*JUMPHP_SCENE*) check 'wsl.exe -e bash -lc + WSLENV' ok ok ;; *) check 'wsl.exe -e bash -lc + WSLENV' "$got" '(see the case)' ;; esac

[ "$fails" -eq 0 ] && echo "all passed" || { echo "$fails failed"; exit 1; }
