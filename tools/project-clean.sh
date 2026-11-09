#!/usr/bin/env bash
# Tidies a project of JumPHP's own development files (docs: "A clean project"): the list is tools/project-clean.list, part of JumPHP
# itself, so each version says what is not a project's business. Called by composer create-project (post-create.sh), by the IDE's and
# jumphp.net's New Project, and by `bin/jumphp tidy` on an existing project. What it removes is written in .jumphp/excluded, so that
# `bin/jumphp update` does not bring it back.
#
#   tools/project-clean.sh [--new] [--examples] [--dry-run] [--reapply]
#     --new        a project that has just been made: every example of games/ and apps/ goes too
#     --examples   also JumPHP's example projects (the `example` lines)
#     --dry-run    only say what would be removed
#     --reapply    remove again what .jumphp/excluded lists (after `bin/jumphp update` brought files back)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
new=0 examples=0 dry=0 reapply=0
for a in "$@"; do
  case "$a" in
    --new) new=1; examples=1 ;;
    --examples) examples=1 ;;
    --dry-run) dry=1 ;;
    --reapply) reapply=1 ;;
    *) echo "usage: project-clean.sh [--new] [--examples] [--dry-run] [--reapply]" >&2; exit 2 ;;
  esac
done
LIST="tools/project-clean.list"
EXCLUDED=".jumphp/excluded"
targets=()

if [ "$reapply" = 1 ]; then
  [ -f "$EXCLUDED" ] && while IFS= read -r p; do [ -n "$p" ] && [ -e "$p" ] && targets+=("$p"); done < "$EXCLUDED"
else
  [ -f "$LIST" ] || { echo "no $LIST: nothing to tidy" >&2; exit 0; }
  while read -r kind path _; do
    case "$kind" in
      rm) ;;
      example) [ "$examples" = 1 ] || continue ;;
      *) continue ;;
    esac
    [ -n "$path" ] && [ -e "$path" ] && targets+=("$path")
  done < <(grep -vE '^\s*(#|$)' "$LIST")
  if [ "$new" = 1 ]; then
    # a new project: nothing of games/ and apps/ is yours yet
    for d in games/*/ apps/*/; do [ -d "$d" ] && targets+=("${d%/}"); done
  fi
fi
# no duplicates, never the things a project cannot do without
mapfile -t targets < <(printf '%s\n' "${targets[@]+"${targets[@]}"}" | awk 'NF && !seen[$0]++')
for p in "${targets[@]+"${targets[@]}"}"; do
  case "$p" in
    .|..|/*|*..*|.git|.git/*|bin|bin/*|engine|engine/*|tools|tools/*|docker|docker/*|AGENTS.md|CLAUDE.md|VERSION|composer.json) echo "refusing to remove $p" >&2; exit 1 ;;
  esac
done

if [ ${#targets[@]} -eq 0 ]; then echo "nothing to tidy"; exit 0; fi
printf '%s\n' "${targets[@]}"
[ "$dry" = 1 ] && exit 0

for p in "${targets[@]}"; do
  if [ -d .git ] && git ls-files --error-unmatch -- "$p" >/dev/null 2>&1; then git rm -r -q --ignore-unmatch -- "$p" >/dev/null 2>&1 || true; fi
  rm -rf -- "$p"
done
# an extensions/ folder left empty is not kept
rmdir extensions 2>/dev/null || true
if [ "$reapply" = 0 ]; then
  mkdir -p .jumphp
  { cat "$EXCLUDED" 2>/dev/null || true; printf '%s\n' "${targets[@]}"; } | awk 'NF && !seen[$0]++' > "$EXCLUDED.tmp" && mv "$EXCLUDED.tmp" "$EXCLUDED"
fi
echo "tidied ${#targets[@]} entr$([ ${#targets[@]} -eq 1 ] && echo y || echo ies) (listed in $EXCLUDED: \`bin/jumphp update\` does not bring them back)"
