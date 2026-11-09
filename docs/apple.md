# The game as native Apple apps (macOS, iPhone / iPad)

`bin/jumphp apple <game>` makes **real Apple apps** of a game: no browser, no WebView. PHP 8.5, SDL2, OpenGL (ES on
iOS) and OpenAL run inside the app, as on the desktop and in the native Android app ([android-native.md](android-native.md)).

- **macOS**: one `.app` for Apple silicon and Intel Macs (macOS 11 or newer), as a `.dmg` and a `.zip`;
- **iPhone / iPad**: an `.ipa` (iOS 14 or newer), the on-screen controls (d-pad or joystick, A / B / START) drawn
  by the engine, the game's orientation; and the same app for **Xcode's iOS simulator**.

**A desktop app** (`ui: webview`, [tauri.md](tauri.md)): `bin/jumphp ios <app>` makes an iPhone / iPad app of its
browser build (made first on GitHub's Linux machines, `bin/jumphp build`) shown by a `WKWebView` from a scheme of its own
(`jumphp://app/`, inside the app), with a bridge for "save to Files", notifications, links, sharing and the clipboard
(`tools/apple/webview/main.m`); no PHP to build, so a few minutes. Its Mac app is Tauri's (`bin/jumphp app:export
<app> --macos`). **Not tried yet** on a Mac: the first run may need fixes.

Building them needs Xcode, so a Mac: the command has **GitHub build them on one of its Macs** (free for a public
repository), from any computer.

## One command

```
bin/jumphp apple pong            # macOS + iOS
bin/jumphp macos pong            # only the Mac app
bin/jumphp ios pong              # only the iPhone / iPad app (and the simulator's)
```

1. the first time it asks the apps' **bundle identifier** (`io.github.<you>.<game>`: it never changes on the App
   Store; letters, digits, hyphens and dots);
2. it writes the version in `games/<game>/apple/app.json` (in git: bundle identifier, build + 1, version), puts the
   workflow `.github/workflows/apple.yml` in the project, commits them and, **after your yes**, pushes;
3. GitHub builds the apps on a Mac (first time ~40 min, then ~10: PHP and the libraries are kept in the Actions
   cache), **tries them there** (the Mac app runs the game headless and saves its picture; the iPhone app is
   installed in an iPhone simulator, launched, photographed), and publishes the release
   `apple-<game>-<version>-<build>` with the apps and the two screenshots; the command downloads them into
   `games/<game>/dist/apple/` (the screenshots in `check/`).

**Files that are not in git** (the data of an original game, kept out of a public repository): the command finds
the game's git-ignored files (not `vendor/`, `var/`, `dist/`...) and, after your yes (`JUMPHP_PRIVATE_FILES=1` without a
terminal), sends them as `private.tar.gz` in the **draft release** `apple-private-<game>`: a draft is only seen by the
repository's writers, never published; the workflow puts the files back into the game before the build. Delete it
with `gh release delete apple-private-<game>` when you no longer build there. The apps contain these files, so their
release is then made as a **draft** too: you decide whether to publish it (don't publish apps of a game whose data you
may not distribute).

Options: `--bundle-id`, `--version 1.1` (the build number goes up by one at each build), `--macos` / `--ios`,
`--local` (on a Mac with Xcode, cmake, autoconf and PHP with GD: built and tried there).

| File | What |
|---|---|
| `<game>-<v>-macos.dmg`, `-macos.zip` | the Mac app |
| `<game>-<v>-ios.ipa` | the iPhone / iPad app, to sign for a device (below) |
| `<game>-<v>-ios-simulator.zip` | the app for Xcode's simulator: unzip, drag the `.app` onto a simulator |
| `check/macos.png`, `check/ios.png` | the game running on GitHub's Mac (headless) and in the iPhone simulator |

## Without an Apple developer account (now)

The apps are signed **ad hoc** (no identity):

- **Mac**: the app opens after **right click > Open** the first time (macOS asks once), or
  `xattr -dr com.apple.quarantine "/Applications/<Game>.app"`. Fine for friends and testers, not for everyone.
- **iPhone / iPad**: an iPhone only installs an app signed for it. The `.ipa` waits for an account (below). In the
  meantime: the simulator's app (on a Mac with Xcode), and the web version installs from Safari (Share > Add to
  Home Screen).

## With an Apple developer account (later)

Each developer uses **their own** account of the [Apple Developer Program](https://developer.apple.com/programs/)
(99 $ a year): the apps are published under their name, with their certificates, in their repository's secrets;
JumPHP never holds an account for anyone. It is needed to:

- put a Mac app online without the right click: **Developer ID** signing + **notarization** by Apple;
- install the iPhone app on a device (more than 7 days) and send it to **TestFlight** / the **App Store**.

What it will take (the next step of this work): a signing certificate and, for iOS, a provisioning profile in the
repository's secrets, and an App Store Connect API key; the workflow then signs (`codesign`), notarizes the Mac app
(`notarytool`) and uploads the iPhone app to TestFlight (`altool` / App Store Connect API). The App Store accepts a
PHP game: the code runs inside the app and ships with it (guideline 2.5.2); nothing is downloaded later.

## What is in the apps

| Part | Where |
|---|---|
| PHP 8.5 (embed SAPI, static, no JIT: Apple forbids it on iOS) with php-sdl, sdl_image, sdl_mixer, sdl_ttf, PHP-OpenGL, PHP-OpenAL, gd (PNG), bcmath, openssl, mbstring, phar, sockets, zlib | built with Xcode's clang per target (macOS arm64 / x86_64, iPhone arm64, simulator arm64): `tools/apple/build.sh`, the extensions' `tools/android/ext/*.m4` (the same as Android's) |
| SDL2 (UIKit on iOS, Cocoa on macOS), SDL2_image / _mixer / _ttf, OpenAL Soft (CoreAudio) | static, `tools/apple/project/CMakeLists.txt` |
| the launcher: finds the game in the bundle, runs it | `tools/apple/project/main.c` |
| the game, as the desktop runs it (`tools/stage-game.sh`) | `<Game>.app/Contents/Resources/game` (macOS), `<Game>.app/game` (iOS) |
| name, icon (`assets/icon.png`, else the title's initial), orientation (the game's shape), version | project.yaml, `games/<game>/apple/app.json` |

The saves and options are in the app's Application Support folder (`JUMPHP_VAR`), kept across updates. PHP's output
and errors go to stdout / stderr: Xcode's console, `xcrun simctl launch --console booted <bundle id>`, or the terminal
(`"/Applications/<Game>.app/Contents/MacOS/<game>"`).

The engine knows where it runs: `JUMPHP_IOS` (Platform::isPhoneApp(): full screen, the game's orientation),
`JUMPHP_MOBILE` and the on-screen controls (`JUMPHP_TOUCH_CONTROLS`, project.yaml `touch:` as in the browser) on iOS;
`JUMPHP_MACOS` on the Mac. SDL draws with OpenGL ES 2 on iOS (as on Android) and OpenGL on macOS (as on Linux), so a
game's own GL (`gl()`, `opengl()`) shares SDL's context; macOS' OpenGL takes GLSL 1.20, not GLSL ES 1.00: the engine
turns the games' shaders into it (OpenGl::versioned()).

HTTPS (`$engine->http()`, online play over `wss://`): PHP's openssl with the game's CA certificates (`cacert.pem`,
from the build Mac's `/etc/ssl/cert.pem`).

## Build it elsewhere

The workflow is plain GitHub Actions (`macos-15`): Actions > apple > Run workflow (game, bundle identifier, version,
build, platforms) works without the command. On any Mac: `GAME=pong BUNDLE_ID=io.github.me.pong bash tools/apple/build.sh`
then `bash tools/apple/check.sh pong` (needs Xcode, `brew install cmake autoconf`, PHP 8.2+ with GD and composer, and
the game's `vendor/`).
