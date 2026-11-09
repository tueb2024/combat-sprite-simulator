#!/usr/bin/env bash
# Fails when the repository contains a word that must never appear (brand names of other games...).
# The words are stored rot13-encoded so that this file does not contain them itself.
# Add one: echo "word" | tr 'A-Za-z' 'N-ZA-Mn-za-m'  -> append to WORDS.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORDS=(fbavp frtn ebobgavx rttzna xahpxyrf zvzvpung)
status=0
# in a git repository, only what git would publish: files ignored by .gitignore (.env, var/...) are private
in_git() { git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; }
search() {
  if in_git; then
    (cd "$ROOT" && git ls-files -z -co --exclude-standard | grep -zv -E '(^|/)(vendor|build|dist|node_modules)/' \
      | xargs -0 -r grep -niI -e "$1" -- 2>/dev/null)
  else
    (cd "$ROOT" && grep -rniI --exclude-dir={vendor,.git,.build,.cache,build,dist,node_modules} -e "$1" . 2>/dev/null)
  fi
}
for encoded in "${WORDS[@]}"; do
  word="$(echo "$encoded" | tr 'A-Za-z' 'N-ZA-Mn-za-m')"
  if hits="$(search "$word")"; then
    echo "forbidden word found ($encoded, rot13):"; echo "$hits" | head -20; status=1
  fi
  if in_git; then paths="$(cd "$ROOT" && git ls-files -co --exclude-standard | grep -i -e "$word" || true)"
  else paths="$(cd "$ROOT" && find . -path ./.git -prune -o \( -path '*/vendor' -o -path ./build -o -path ./.build -o -path ./.cache \) -prune -o -iname "*$word*" -print)"; fi
  if [ -n "$paths" ]; then
    echo "forbidden word in a file name ($encoded, rot13):"; echo "$paths"; status=1
  fi
done
[ $status -eq 0 ] && echo "no forbidden word"
exit $status
