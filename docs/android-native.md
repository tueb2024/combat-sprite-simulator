# The game as a native Android app

`bin/jumphp android <game>` makes a **real Android app** of a game: no browser, no WebView. PHP 8.5, SDL2,
OpenGL ES and OpenAL run inside the app, as on the desktop; the on-screen controls (d-pad or joystick, A / B /
START) are drawn by the engine. Phones (arm64-v8a) and emulators / Chromebooks (x86_64), Android 8.0 or newer.

**A desktop app** (`ui: webview`, [tauri.md](tauri.md)): the same command makes an app of its browser build (`bin/jumphp
build`, done by the command) shown by Android's WebView from `https://appassets.androidplatform.net/` (inside the app,
no network), with a bridge for the system's save dialog, notifications, links, sharing and the clipboard
(`tools/android/webview`); no NDK, a few minutes. Output: `dist/android-native/<app>-<version>.apk` / `.aab`, the same
package, key and Google Play steps as a game. Tried in an emulator with `games/hello-desktop`.

(The other way, `bin/jumphp twa`, puts the game's web page in Chrome: [docs/android.md](android.md). With the
same package and key, the native app replaces the TWA on Google Play as a new version.)

## One command

```
bin/jumphp android pong
```

1. the first time it asks the app's **package** (`io.github.<you>.<game>`: it never changes on Google Play; the
   TWA's if the game has one) and makes the **upload key** in `~/.jumphp/android/keys/<package>.keystore` (+ `.env`,
   its password). Back them up; they are never in git (the TWA's key if there is one);
2. it puts the workflow `.github/workflows/android.yml` in the project and, **after your yes**, sends the key to
   the repository's secrets (`ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_PASSWORD`);
3. it writes the version in `games/<game>/android/app.json` (in git: package, versionCode + 1, versionName), commits
   it and, **after your yes**, pushes;
4. GitHub builds the app (first time ~25 min, then ~8: PHP and the libraries are kept in the Actions cache), and
   publishes the release `android-<game>-<version>-<code>` with the **`.apk`** (a phone) and the **`.aab`**
   (Google Play), signed with your key; the command downloads them into `games/<game>/dist/android-native/`.

Options: `--package`, `--version-name 1.1` (the version code goes up by one at each build), `--local` (built on this
computer in Docker: the Android SDK and its NDK in `~/.jumphp/android-sdk`, installed after your yes to Google's
license by `bin/jumphp twa`).

On a phone: `adb install games/<game>/dist/android-native/<game>-1.0.apk` (or send the .apk to the phone and open it,
"install unknown apps" allowed).

## What is in the app

| Part | Where |
|---|---|
| PHP 8.5 (embed SAPI, static) with php-sdl, sdl_image, sdl_mixer, sdl_ttf, PHP-OpenGL, PHP-OpenAL, gd (PNG), bcmath, openssl, mbstring, phar, sockets, zlib | cross-built with the NDK: `tools/android/build.sh`, the extensions' `tools/android/ext/*.m4` |
| SDL2 (its Java activity, OpenGL ES, touch, sound), SDL2_image / _mixer / _ttf, OpenAL Soft (OpenSL ES) | SDL's Android project, `tools/android/project` (Gradle, CMake) |
| `libmain.so`: unpacks the game on its first launch (each new version once, from the APK into the private storage), runs it | `tools/android/project/app/jni/src/main.c` |
| the game: `assets/game.tar` (as `export:standalone` packs it: `tools/stage-game.sh`) | |
| name, icon (the PWA's: `assets/icon.png`), orientation (the game's shape) | project.yaml |

The saves and options are in the app's private storage (`JUMPHP_VAR`), kept across updates. PHP's output and errors
go to logcat: `adb logcat -s JumPHP`. Android's back button is Escape.

The engine knows it is on a phone: `JUMPHP_MOBILE` (Platform::isMobile()), `JUMPHP_ANDROID`, and the on-screen
controls are on (`JUMPHP_TOUCH_CONTROLS`; project.yaml `touch:` as in the browser: `pad: stick`, `a`, `b`, `start`,
`select`, `labels`). On the desktop, `JUMPHP_TOUCH_CONTROLS=1 bin/jumphp run <game>` shows them (with the mouse).

HTTPS (`$engine->http()`, online play over `wss://`): PHP's openssl (OpenSSL 3, built with the NDK) with the game's CA
certificates (`cacert.pem`, put in by `tools/stage-game.sh`; `openssl.cafile` set by main.c).

## On Google Play

In short below; the whole procedure (keys, phone checks, every Play Console declaration, review, pitfalls) is in
[google-play.md](google-play.md).

1. **A developer account**: [play.google.com/console](https://play.google.com/console) (25 $ once, an identity
   check; a new personal account must have 12 testers for 14 days before going public).
2. **Create the app**: name, default language, "Game", free; the declarations (privacy policy: a page saying the
   game collects nothing, e.g. on the game's site; content rating questionnaire; target audience; ads: none).
3. **Play App Signing**: Google keeps the key that signs the app for the phones; your key only proves the uploads are
   yours (if you lose it, Google can reset it: Setup > App signing).
4. **The first upload by hand**: Test and release > Testing > Internal testing > Create a new release > upload the
   `.aab` (`games/<game>/dist/android-native/`, or the GitHub release) > Save > Review > Start rollout. Add testers
   (their Google accounts); they install from the link Google gives.
5. **The store listing**: short and full description, the icon 512 × 512 (`dist/web/icon-512.png` of
   `bin/jumphp build`), a feature graphic 1024 × 500, at least 2 screenshots (`bin/jumphp shot <game>`, or on a phone).
6. **Production**: when the tests are done, promote the release (Production > Create a new release, or "Promote
   release"). Google reviews it (a few hours to a few days).
7. **The next versions**: `bin/jumphp android <game> --version-name 1.1` (the version code goes up by one; Google
   Play refuses a code it already has), then upload the new `.aab` the same way.

## Build it elsewhere

The workflow is plain GitHub Actions on the repository (`ubuntu-latest`, its Android SDK; the NDK r29 installed by
`sdkmanager`): Actions > android > Run workflow (game, package, version code, version name) works without the
command. The build itself, anywhere with Docker: `docker build -t jumphp/android-native docker/android-native`,
then `tools/android/build.sh` in it with `/workspace` (the project), `/cache`, `/opt/android-sdk` (with the NDK) and
`GAME`, `PACKAGE`, `VERSION_CODE`, `VERSION_NAME`, `KEYSTORE` + `KEYSTORE_PASSWORD` + `KEY_ALIAS` + `KEY_PASSWORD`.
