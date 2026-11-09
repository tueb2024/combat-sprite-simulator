#!/usr/bin/env bash
# The Android app of a game for Google Play (a Trusted Web Activity), run in docker/twa by `bin/jumphp twa`:
#   1. the Android SDK, the first time, once its license is accepted (in ~/.jumphp/android-sdk)
#   2. the upload key of the app, the first time (~/.jumphp/android/keys/<package>.keystore + .env, outside git)
#   3. games/<game>/android/twa-manifest.json from the game's web manifest (kept in git: the app's version)
#   4. bubblewrap update + build in build/android/<game> -> games/<game>/dist/android/: the .aab for Google Play,
#      the .apk to try on a phone, assetlinks.json (what the game's site must serve for the app to be full screen)
# Environment: GAME, URL (the game's page or web manifest), PACKAGE, NAME (optional), BUMP=0|1, ACCEPT_LICENSE=1
set -euo pipefail

say()  { printf '\033[1;36m==> %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31m[x] %s\033[0m\n' "$*" >&2; exit 1; }

CMDLINE_TOOLS=16111833
CMDLINE_SHA1=e025545c62a8e64c7559119566a569fb1dec5f60
BUILD_TOOLS=36.1.0
PLATFORM=android-36
SDK=/opt/android-sdk
GAME_DIR="/workspace/games/$GAME"; [ -d "$GAME_DIR" ] || GAME_DIR="/workspace/apps/$GAME"
TWA_JSON="$GAME_DIR/android/twa-manifest.json"
PROJECT="/workspace/build/android/$GAME"
OUT="$GAME_DIR/dist/android"
KEY="/keys/$PACKAGE.keystore"

# 1. the Android SDK
if [ ! -x "$SDK/build-tools/$BUILD_TOOLS/apksigner" ] || [ ! -d "$SDK/platforms/$PLATFORM" ]; then
  [ "${ACCEPT_LICENSE:-}" = 1 ] || die "the Android SDK's license was not accepted"
  if [ ! -x "$SDK/cmdline-tools/latest/bin/sdkmanager" ]; then
    say "Android command line tools ($CMDLINE_TOOLS)"
    curl -fsSL -A jumphp-twa -o /tmp/tools.zip "https://dl.google.com/android/repository/commandlinetools-linux-${CMDLINE_TOOLS}_latest.zip"
    echo "$CMDLINE_SHA1  /tmp/tools.zip" | sha1sum -c - >/dev/null || die "the Android tools download is not the expected file"
    rm -rf "$SDK/cmdline-tools" && mkdir -p "$SDK/cmdline-tools"
    unzip -q /tmp/tools.zip -d "$SDK/cmdline-tools" && mv "$SDK/cmdline-tools/cmdline-tools" "$SDK/cmdline-tools/latest" && rm /tmp/tools.zip
  fi
  say "Android SDK: build-tools $BUILD_TOOLS, $PLATFORM (license accepted by you)"
  # the new SDK CLI no longer needs --licenses; `yes` gets SIGPIPE under pipefail, so never fail on it
  { yes | "$SDK/cmdline-tools/latest/bin/sdkmanager" --sdk_root="$SDK" --licenses >/dev/null; } || true
  "$SDK/cmdline-tools/latest/bin/sdkmanager" --sdk_root="$SDK" "build-tools;$BUILD_TOOLS" "platforms;$PLATFORM" | grep -v '^\[=*' || true
fi
# bubblewrap looks for the SDK's bin/ (or tools/) folder, where the old layout had sdkmanager
[ -e "$SDK/bin" ] || ln -s cmdline-tools/latest/bin "$SDK/bin"
mkdir -p "$HOME/.bubblewrap"
printf '{"jdkPath": "/opt/jdk", "androidSdkPath": "%s"}\n' "$SDK" > "$HOME/.bubblewrap/config.json"

# 2. the upload key (Google Play signs the app with its own key; this one proves the uploads are yours)
if [ ! -f "$KEY" ]; then
  say "a new upload key: $PACKAGE"
  pass="$(node -e 'console.log(require("crypto").randomBytes(24).toString("base64url"))')"
  keytool -genkeypair -keystore "$KEY" -storetype PKCS12 -alias upload -keyalg RSA -keysize 2048 -validity 10000 \
    -dname "CN=$PACKAGE" -storepass "$pass" -keypass "$pass" >/dev/null 2>&1
  printf 'BUBBLEWRAP_KEYSTORE_PASSWORD=%s\nBUBBLEWRAP_KEY_PASSWORD=%s\n' "$pass" "$pass" > "/keys/$PACKAGE.env"
  chmod 600 "$KEY" "/keys/$PACKAGE.env"
fi
set -a; . "/keys/$PACKAGE.env"; set +a
FINGERPRINT="$(keytool -list -v -keystore "$KEY" -alias upload -storepass "$BUBBLEWRAP_KEYSTORE_PASSWORD" | sed -n 's/^\s*SHA256: //p')"

# 3. twa-manifest.json
isNew=0; [ -f "$TWA_JSON" ] || isNew=1
mkdir -p "$GAME_DIR/android"
say "the app's manifest from $URL"
node /workspace/tools/twa-manifest.cjs "$URL" "$TWA_JSON" "$PACKAGE" "$KEY" "${NAME:-}"

# 4. the Android project, built and signed
mkdir -p "$PROJECT" "$OUT"
cd "$PROJECT"
skip=(--skipVersionUpgrade)
[ "$isNew" = 0 ] && [ "${BUMP:-1}" = 1 ] && skip=()
say "Android project (bubblewrap update${skip[*]:+, same version})"
bubblewrap update --manifest="$TWA_JSON" ${skip[@]+"${skip[@]}"} </dev/null
# the app opens its page in Chrome of the main profile: without it, Android asks which browser (a second Chrome
# in a dual-space profile made the question, and the app closed when it was dismissed)
launcher="$PROJECT/app/src/main/java/$(echo "$PACKAGE" | tr . /)/LauncherActivity.java"
if [ -f "$launcher" ] && ! grep -q createTwaLauncher "$launcher"; then
  perl -0pi -e 's|(    \@Override\n    protected Uri getLaunchingUrl\(\))|    \@Override\n    protected com.google.androidbrowserhelper.trusted.TwaLauncher createTwaLauncher() {\n        return new com.google.androidbrowserhelper.trusted.TwaLauncher(this, "com.android.chrome");\n    }\n\n$1|' "$launcher"
fi
say "build (Gradle: a few minutes the first time)"
bubblewrap build --manifest="$TWA_JSON" --skipPwaValidation </dev/null
version="$(node -e 'const m = require(process.argv[1]); console.log(`${m.appVersionName}`)' "$TWA_JSON")"
cp app-release-bundle.aab "$OUT/$GAME-$version.aab"
cp app-release-signed.apk "$OUT/$GAME-$version.apk"
cat > "$OUT/assetlinks.json" <<EOF
[{
  "relation": ["delegate_permission/common.handle_all_urls"],
  "target": {"namespace": "android_app", "package_name": "$PACKAGE", "sha256_cert_fingerprints": ["$FINGERPRINT"]}
}]
EOF
echo "AAB=$OUT/$GAME-$version.aab"
echo "APK=$OUT/$GAME-$version.apk"
echo "FINGERPRINT=$FINGERPRINT"
