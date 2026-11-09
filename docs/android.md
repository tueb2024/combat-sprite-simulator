# The game on Google Play (Android app)

`bin/jumphp twa <game> --url https://<where the game is online>` turns a game that is installable in the browser
(a PWA: on the JumPHP playground every game is; on one's own server, project.yaml `pwa: true`) into an Android app
for Google Play: a **Trusted Web Activity**, the game's page in Chrome, full screen, no address bar, with its
icon and name. The game itself stays on the web: an update of the game is in the app at once.

It runs in Docker (`docker/twa`: Node, JDK 17, Google's [Bubblewrap](https://github.com/GoogleChromeLabs/bubblewrap)),
`tools/twa.sh`:

| Step | Where |
|---|---|
| the Android SDK, downloaded the first time once **the user accepts its license** | `~/.jumphp/android-sdk` |
| the upload key and its password, made the first time | `~/.jumphp/android/keys/<package>.keystore` + `.env` (back them up, never in git) |
| the app's description, from the game's web manifest (name, icons, colours, orientation, start page) | `games/<game>/android/twa-manifest.json` (in git: package, address, version) |
| the Android project, built by Gradle | `build/android/<game>` (Gradle's cache: `~/.jumphp/gradle`) |
| the app | `games/<game>/dist/android/<game>-<version>.aab` (Google Play), `.apk` (a phone), `assetlinks.json` |

Options: `--package io.github.me.my_game` (asked the first time; it never changes on Google Play), `--name`
(the name under the icon; default: the web manifest's `short_name`), `--same-version` (else each build is
version + 1, as Google Play wants).

## The site vouches for the app

Android shows the page full screen only if the site says the app is its own: `https://<host>/.well-known/assetlinks.json`
lists the app's package and the SHA-256 fingerprints of the keys that sign it, the upload key's (printed by
`bin/jumphp twa`) and, once on Google Play, Google's app signing key's (Play Console > Setup > App signing).

- on the JumPHP playground: Studio > the game > Android app, or MCP `update_project` (`android_package`,
  `android_fingerprints`);
- on one's own server: `dist/android/assetlinks.json` into `site/.well-known/` (`bin/jumphp build:site` and
  `deploy` send it), or the web server's root.

## Google Play

The developer account and the Play Console are the user's: create the app with the same package, upload the
`.aab` in internal testing, add Google's signing fingerprint to the site (above), then the store listing
(screenshots: `bin/jumphp shot`) and production. **The whole procedure, step by step (keys, phone checks, every
Play Console declaration, review, next versions, pitfalls): [google-play.md](google-play.md).** The playground's
guide: https://jumphp.net/docs/google-play
