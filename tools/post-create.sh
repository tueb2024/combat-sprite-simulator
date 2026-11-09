#!/usr/bin/env bash
# Run by `composer create-project jumphp/jumphp <dir>` inside the new project (post-create-project-cmd):
# 1. turns the folder into a git clone of JumPHP (remote "jumphp": `bin/jumphp update` merges new versions of
#    the framework into the project later),
#    fetch only: nothing can be pushed to the framework; the project's own repository is "origin" (the wizard, or
#    the JumPHP IDE, creates it),
# 2. starts the set-up wizard (bin/jumphp init) on the terminal.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
REPO="${JUMPHP_REPO:-https://git.jumphp.net/jumphp/jumPhp.git}"

say()  { printf '\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[!] %s\033[0m\n' "$*"; }

# a zip archive can lose the executable bits
chmod +x bin/jumphp tools/*.sh docker/ext/jumphp-xvfb 2>/dev/null || true

if [ ! -d .git ] && command -v git >/dev/null 2>&1; then
  say "linking the project to the JumPHP repository ($REPO)"
  git init -q -b main 2>/dev/null || { git init -q && git symbolic-ref HEAD refs/heads/main; }
  git remote add jumphp "$REPO"
  linked=0
  # the commit these files come from: the tag of this version, else the newest main
  for ref in "refs/tags/v$(cat VERSION 2>/dev/null)" refs/heads/main; do
    git fetch -q --no-tags jumphp "$ref" 2>/dev/null || continue
    git reset -q FETCH_HEAD
    git ls-files -s | awk '$1 == "100755" { print $4 }' | xargs chmod +x 2>/dev/null || true
    if [ -z "$(git status --porcelain)" ]; then linked=1; break; fi
  done
  git remote set-url --push jumphp no-push://the-jumphp-framework-only-gives-updates
  if [ "$linked" = 1 ]; then
    say "git: branch main, on top of JumPHP $(git log -1 --format=%h)"
  else
    warn "git: could not match these files with a JumPHP commit (offline?): the project starts without history"
    rm -rf .git
    git init -q -b main 2>/dev/null || git init -q
    git remote add jumphp "$REPO"
    git remote set-url --push jumphp no-push://the-jumphp-framework-only-gives-updates
  fi
elif ! command -v git >/dev/null 2>&1; then
  warn "git is not installed: the wizard will offer to install it"
fi

# a project of yours, not a copy of JumPHP's development tree: its own extensions, examples and notes go (tools/project-clean.sh)
bash tools/project-clean.sh --new >/dev/null 2>&1 && say "tidied: JumPHP's examples and development files are not in your project (bin/jumphp tidy lists what it does)" || true

# the wizard needs a real terminal (and docker): not inside the composer Docker image or a CI job
if [ -f /.dockerenv ] || [ -n "${CI:-}" ] || ! { : </dev/tty; } 2>/dev/null; then
  say "project ready. Next: cd $(basename "$PWD") && bin/jumphp init (it creates the project's own GitHub repository)"
  exit 0
fi
exec bash bin/jumphp init </dev/tty >/dev/tty 2>&1
