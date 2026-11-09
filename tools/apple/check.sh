#!/usr/bin/env bash
# check.sh <game> [platforms]: the Apple apps of tools/apple/build.sh, tried on the Mac that built them (the workflow
# apple.yml runs it): pictures in games/<game>/dist/apple/check/
#   macOS: the app's own executable runs the game headless (JUMPHP_SCREENSHOT, as bin/jumphp shot): PHP, its
#          extensions, SDL and the game inside the Mac binary, 120 frames, then the last one as a PNG
#   iOS:   the simulator's app installed in an iPhone simulator, launched; a screenshot after 20 s and its output
# Fails on a PHP error in the output, or when the game does not run.
set -euo pipefail

say() { printf '\033[1;36m==> %s\033[0m\n' "$*" >&2; }
die() { printf '\033[1;31m[x] %s\033[0m\n' "$*" >&2; exit 1; }

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GAME="${1:?the game}"
PLATFORMS="${2:-macos ios}"
OUT="$ROOT/games/$GAME/dist/apple"; [ -d "$ROOT/games/$GAME" ] || OUT="$ROOT/apps/$GAME/dist/apple"
CHECK="$OUT/check"
rm -rf "$CHECK" && mkdir -p "$CHECK"
# macOS has no timeout(1)
limit() { local seconds="$1"; shift; perl -e 'alarm shift; exec { $ARGV[0] } @ARGV or die "$!"' "$seconds" "$@"; }
errors() { grep -E "PHP (Fatal|Parse|Warning)|Uncaught|Fatal error|the boot script did not run" "$@" || true; }

for p in $PLATFORMS; do
  case "$p" in
    macos)
      app="$(ls -d "$OUT"/macos/*.app | head -1)"
      exe="$app/Contents/MacOS/$(/usr/libexec/PlistBuddy -c 'Print CFBundleExecutable' "$app/Contents/Info.plist")"
      say "macOS: $(lipo -archs "$exe") in $(basename "$app"), 120 frames headless"
      status=0
      SDL_VIDEODRIVER=dummy SDL_AUDIODRIVER=dummy JUMPHP_VAR="$(mktemp -d)" JUMPHP_MAX_FRAMES=120 \
        JUMPHP_SCREENSHOT="$CHECK/macos.png" limit 180 "$exe" > "$CHECK/macos.log" 2>&1 || status=$?
      cat "$CHECK/macos.log"
      [ -z "$(errors "$CHECK/macos.log")" ] || die "macOS: PHP errors (above)"
      [ "$status" = 0 ] || die "macOS: the game exited with $status"
      [ -s "$CHECK/macos.png" ] || die "macOS: no screenshot"
      say "macOS: $CHECK/macos.png"
      ;;
    ios)
      app="$(ls -d "$OUT"/ios-simulator/*.app | head -1)"
      bundle="$(/usr/libexec/PlistBuddy -c 'Print CFBundleIdentifier' "$app/Info.plist")"
      udid="$(xcrun simctl list devices available | grep -E '^\s+iPhone' | head -1 | sed -E 's/.*\(([0-9A-F-]{36})\).*/\1/')"
      [ -n "$udid" ] || die "no iPhone simulator on this Mac"
      say "iOS: $(xcrun simctl list devices available | grep "$udid" | sed -E 's/^ +//; s/ \(.*//'), $bundle"
      xcrun simctl boot "$udid" 2>/dev/null || true
      xcrun simctl bootstatus "$udid" -b > /dev/null
      xcrun simctl install "$udid" "$app"
      xcrun simctl launch --terminate-running-process --stdout="$CHECK/ios.out" --stderr="$CHECK/ios.err" "$udid" "$bundle"
      sleep 20
      xcrun simctl io "$udid" screenshot "$CHECK/ios.png" > /dev/null 2>&1
      running=0
      xcrun simctl spawn "$udid" launchctl list 2>/dev/null | grep -q "$bundle" && running=1
      xcrun simctl terminate "$udid" "$bundle" 2>/dev/null || true
      cat "$CHECK/ios.out" "$CHECK/ios.err" > "$CHECK/ios.log" 2>/dev/null || true
      cat "$CHECK/ios.log"
      xcrun simctl shutdown "$udid" 2>/dev/null || true
      [ -z "$(errors "$CHECK/ios.log")" ] || die "iOS: PHP errors (above)"
      [ "$running" = 1 ] || die "iOS: the app is not running any more after 20 s (it crashed or quit)"
      say "iOS: $CHECK/ios.png"
      ;;
  esac
done
