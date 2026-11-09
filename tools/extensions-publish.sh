#!/usr/bin/env bash
# JumPHP's own engine extensions (extensions/<id>) as one repository each, so Packagist can serve them
# (composer.json at the root, versions = git tags): `git subtree split` of extensions/<id>, pushed to its repository.
#
#   tools/extensions-publish.sh [--push] [--ref <git ref>] [--tag vX.Y.Z] [id...]
#
# Without --push (the default): a dry run, each split goes to a local bare repository .build/extensions-split/<id>.git
# (look at it, `composer validate` it, require it through a "vcs" repository). With --push: to
# $JUMPHP_EXTENSIONS_REMOTE with {id} replaced (default https://git.jumphp.net/jumphp/jumphp-{id}.git; in GitHub
# Actions the token comes from the EXTENSIONS_SPLIT_TOKEN secret, see .github/workflows/extensions-split.yml).
# --ref: what to split (default HEAD); --tag: the version tag put on the split (default v<VERSION>).
# Never forces: a split of the same history only adds commits, so a rejected push means someone else pushed there.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

push=0 ref=HEAD tag="" ids=()
while [ $# -gt 0 ]; do
  case "$1" in
    --push) push=1 ;;
    --ref) ref="$2"; shift ;;
    --tag) tag="$2"; shift ;;
    -h|--help) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) ids+=("$1") ;;
  esac
  shift
done
[ -n "$tag" ] || tag="v$(tr -d '[:space:]' < VERSION)"
[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]] || { echo "[x] tag: vX.Y.Z (got $tag)" >&2; exit 1; }
if [ ${#ids[@]} -eq 0 ]; then
  for d in extensions/*/; do [ -f "$d/composer.json" ] && ids+=("$(basename "$d")"); done
fi
default_remote='https://git.jumphp.net/jumphp/jumphp-{id}.git'
remote_tpl="${JUMPHP_EXTENSIONS_REMOTE:-$default_remote}"

for id in "${ids[@]}"; do
  [ -f "extensions/$id/composer.json" ] || { echo "[x] no extensions/$id/composer.json" >&2; exit 1; }
  sha="$(git subtree split --quiet --prefix="extensions/$id" "$ref")"
  if [ "$push" = 1 ]; then
    target="${remote_tpl//\{id\}/$id}"
  else
    target="$ROOT/.build/extensions-split/$id.git"
    [ -d "$target" ] || git init --quiet --bare -b main "$target"
  fi
  shown="$(printf '%s' "$target" | sed -E 's#//[^/@]*@#//#')"   # never print a token
  # a new extension whose repository is not made yet must not stop the others: it is said, and skipped (make jumphp/jumphp-<id>, then Packagist)
  if [ "$push" = 1 ] && ! git ls-remote "$target" >/dev/null 2>&1; then
    echo "[!] $id: no repository at $shown yet: skipped (make it, then submit it on Packagist: docs/extensions.md)" >&2
    continue
  fi
  echo "==> $id: ${sha:0:12} -> $shown (main, $tag)" >&2
  git push --quiet "$target" "$sha:refs/heads/main"
  # an existing tag is left as it is (a version never moves)
  if git ls-remote --exit-code --tags "$target" "refs/tags/$tag" >/dev/null 2>&1; then
    echo "    $tag is already there: kept (never move a published tag: Packagist refuses a changed reference; tag a new version instead)" >&2
  else
    # GitHub sometimes answers 500 to a tag pushed to a repository that just got its first branch: its API then
    git push --quiet "$target" "$sha:refs/tags/$tag" \
      || { repo="$(printf '%s' "$shown" | sed -E 's#.*github\.com/##; s#\.git$##')"; command -v gh >/dev/null \
           && gh api "repos/$repo/git/refs" -f ref="refs/tags/$tag" -f sha="$sha" >/dev/null && echo "    $tag made through GitHub's API" >&2; }
  fi
done
[ "$push" = 1 ] || echo "dry run: .build/extensions-split/<id>.git (--push to publish)" >&2
