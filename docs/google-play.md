# Publishing a game on Google Play, step by step

How to put a JumPHP game on the Play Store and keep it there: what to build, what to keep, what to check on a
phone, and the Play Console from the first upload to the review. It holds for both kinds of Android app:

| | The **web app** (Trusted Web Activity) | The **native app** |
|---|---|---|
| Command | `bin/jumphp twa <game> --url https://<the game online>` | `bin/jumphp android <game>` |
| What it is | the game's page in Chrome, full screen | PHP + SDL + the game inside the app, offline |
| The game updates | with the site, at once (no new app) | with a new version of the app |
| Out | `games/<game>/dist/android/` | `games/<game>/dist/android-native/` |
| Details | [android.md](android.md) | [android-native.md](android-native.md) |

Both make an `.aab` (for Google Play) and an `.apk` (for a phone), signed with the same kind of upload key: with the
same package and key, the native app can later replace the web app as a new version.

## 0. Before anything

- **A Google Play developer account**: [play.google.com/console](https://play.google.com/console), 25 $ once, an
  identity check. It is the user's own: an agent never creates it, never accepts Google's terms in it, never
  answers its questionnaires without telling the user what it answered.
- **A new personal account** must run a **closed test with at least 12 testers for 14 days** before it may publish
  in production. Start it early.
- **The package name** (`io.github.<github user>.<game id with _>`, e.g. `io.github.me.space_rocks`): the app's id
  on Google Play. It never changes. The command asks it the first time.
- **The Android SDK license**: downloaded the first time (~1 GB in `~/.jumphp/android-sdk`) once **the user**
  accepts Google's license, in their own terminal (`! bin/jumphp twa ...` in Claude Code). Never accept it for them.
- **A privacy policy at a public address** (Google asks for it even when the game collects nothing). A short page
  is enough: the game collects, sends and shares no personal data, no account, no ads, no tracking; settings and
  saves stay on the device (deleting the app or the site's data erases them); a contact address. Put it in the
  game's site, `games/<game>/site/privacy.html` (served at `https://<domain>/privacy.html` by `bin/jumphp deploy`,
  at `https://play.jumphp.net/<game>/privacy.html` on the playground), or link the `PRIVACY.md` of the public
  repository. **Open the address once** before giving it to Google: a 404 there blocks the review.

## 1. The keys: back them up first

The first build makes the **upload key**: `~/.jumphp/android/keys/<package>.keystore` and its password in
`<package>.env`.

- **Never in git**, never in a note, an issue or a chat. Back up both files somewhere else (a password manager, an
  encrypted disk). Without them, a new build makes a new key and Google Play refuses the update.
- With **Play App Signing** (the default), Google keeps its own key, the one that signs the app on the phones. The
  upload key only proves the uploads are yours; if it is lost, Google can replace it (Setup > App signing), but
  it takes days.
- Two SHA-256 fingerprints exist, both needed by the web app (§3): the **upload key's** (printed by the build) and
  **Google's app signing key's** (Play Console > Setup > App signing, after the first upload).

## 2. Build

```bash
bin/jumphp twa my-game --url https://jumphp.net/my-game      # the web app
bin/jumphp android my-game                                    # or the native app
```

- Each build is **version code + 1** (Google Play refuses a code it already has; a failed build still uses a
  number, which is harmless). `--same-version` (web app) rebuilds without changing it.
- Commit the version file at once: `games/<game>/android/twa-manifest.json` (web app) or
  `games/<game>/android/app.json` (native app). Never commit `dist/`.
- The name under the icon: project.yaml `pwa: {short_name: ...}` (12 characters show whole on most phones). The icon:
  `assets/icon.png`.

## 3. The web app only: the site vouches for the app

Android opens the page full screen only if the site lists the app: `https://<host>/.well-known/assetlinks.json`
with the package and **both fingerprints** (upload key + Google's). With only the upload key's, the version
installed from your `.apk` works and the one installed from Google Play opens with an address bar (or a "open
with" chooser).

- on the JumPHP playground: Studio > the game > Android app, or MCP `update_project` (`android_package`,
  `android_fingerprints`); it serves `https://jumphp.net/.well-known/assetlinks.json`;
- on one's own server: `dist/android/assetlinks.json` into `site/.well-known/` (`bin/jumphp deploy` sends it).

Check it: `curl -s https://<host>/.well-known/assetlinks.json` shows the package and the two fingerprints.

## 4. Try it on a real phone

```bash
adb devices -l                                                  # USB debugging on, the computer allowed
adb install -r games/<game>/dist/android/<game>-<version>.apk   # (dist/android-native/ for the native app)
adb shell pm get-app-links <package>                            # web app: "<host>: verified"
adb logcat -c; adb shell am start -n <package>/.LauncherActivity   # web app (the native app: from its icon)
adb logcat -d -v time | grep -E "TwaLauncher|ResolverActivity|Abort background|AndroidRuntime"
adb exec-out screencap -p > var/debug/phone.png                 # look at it; var/ is not in git
```

- The app must open on the game's title screen. Look at the screenshot.
- An `.apk` signed with the upload key does not install over the same app installed from Google Play
  (`INSTALL_FAILED_UPDATE_INCOMPATIBLE`: two different signing keys). Uninstall first, **after asking** (it erases
  the app's saves).
- **The web app opens then closes at once**, with an "Open with" chooser in the log (`ResolverActivity`, then
  `Abort background activity starts`): the phone has two Chrome, usually because of a **dual space / clone space /
  second space** (Xiaomi XSpace, Samsung Dual Messenger, user 999 in `adb shell pm list users`). JumPHP's web app
  asks for the Chrome of the main profile (`createTwaLauncher()` with `com.android.chrome`, added by
  `tools/twa.sh`), which fixes it for players. On a test phone, removing the clone space (`adb shell pm remove-user
  <id>`) erases every app in it: only with the user's explicit yes.
- The site and Chrome alone working (the game's address opens in Chrome) does not prove the app works: test the app.

## 5. The Play Console, the first time

The console asks the same things for every app; the dashboard's "Set up your app" list (n of 11) shows what is left,
and **Send for review stays greyed out until every item is done**.

1. **Create app**: the name, the default language, **Game**, **Free**, the declarations (policies, US export laws).
2. **App content** (Policy > App content), every declaration:
   - **Privacy policy**: the public address of §0;
   - **Ads**: no ads (JumPHP adds none);
   - **App access**: all functionality available without special access (no account);
   - **Target audience and content**: the ages the game is made for. Choosing ages under 13 puts the app in the
     Families programme (stricter rules, less visibility): do not tick young ages unless the game is really made
     for children and the user wants it; **ask the user**, never decide it;
   - **Content rating** (IARC questionnaire): category Game; answer for the game honestly (cartoon or fantasy
     violence, blood, fear, gambling, user-generated content, chat between players, location, purchases). A JumPHP
     game online with the relay has players interacting: say so if the game has online play;
   - **Data safety**: a JumPHP game without accounts or analytics collects and shares **no data** (saves stay on the
     device; the HTTP requests a game makes are its own: check the game's code);
   - **Advertising ID**: not used;
   - **Government, financial features, health**: no.
3. **Store settings** (Grow > Store presence > Store settings): **category** (Arcade, Action, Puzzle...) and the
   **contact e-mail**. Both are required: without them "Your app cannot be published yet". The e-mail is public:
   use the one the user gives, never guess.
4. **Main store listing**: title (30 characters), short description (80), full description (4000), icon 512 × 512
   PNG (`bin/jumphp build` makes `dist/web/icon-512.png`), feature graphic 1024 × 500, at least 2 phone screenshots
   (`bin/jumphp shot <game>`, scaled up by a whole factor, or `adb exec-out screencap`). Keep the pictures in the
   game (e.g. `games/<game>/store/`) to redo them for the next versions.
5. **Testing**: Test and release > Testing > Internal testing (or Closed testing for the 12 testers of a new account)
   > Create release > upload the `.aab` > release notes > Save > Review > Start rollout. Testers: an e-mail list;
   each tester opens the invitation link **signed in with that Google account** and taps **Become a tester**.
6. **Web app**: after this first upload, copy **Google's app signing fingerprint** (Setup > App signing) into the
   site's assetlinks (§3), then install from the test link and check it opens full screen.
7. **Production**: Production > Countries / regions > Add, select them, Save (**a release without countries is
   refused**), then Create release > the `.aab` > release notes > Next > Save.
8. **Publishing overview > Send for review**, confirm. The review takes from a few hours to 7 days; the console and
   the account's e-mail say when the app is live.

Sending to production, accepting terms, answering the content questionnaire: only with the user's explicit yes **for
that step**, after showing them what will be sent.

## 6. Release notes

One block per language, the tags on their own lines:

```
<fr-FR>
Première version.
</fr-FR>
<en-US>
First release.
</en-US>
```

## 7. The next versions

- **Web app**: the game updates with the site (push, publish): no new app. A new app only when the name, the icon,
  the colours, the start address or the package change: `bin/jumphp twa <game>` again.
- **Native app**: each change of the game is a new app: `bin/jumphp android <game> --version-name 1.1`.

Then: commit the version file, try the `.apk` on a phone (§4), Play Console > the same track > Create release > the
new `.aab` > notes > (countries already set) > Send for review. The declarations stay valid unless the game
changes what they describe (online play, purchases, data): update them first then.

## 8. Pitfalls met

| Symptom | Cause, fix |
|---|---|
| The app opens with an address bar | the site's assetlinks lacks a fingerprint (Google's after Play App Signing) or the package; §3 |
| The web app opens and closes at once | two Chrome on the phone (clone space); §4 |
| `INSTALL_FAILED_UPDATE_INCOMPATIBLE` | the installed app comes from Google Play (another signing key): uninstall first (ask) |
| Google Play: "version code already used" | build again (version + 1) |
| Google Play refuses the update's key | the upload key was lost or remade: restore the backup, or ask Google to reset it |
| "Your app cannot be published yet" | Store settings: category and contact e-mail |
| Send for review greyed out | an item of "Set up your app" is not done; a release without countries |
| A description is empty after filling it by automation | type it, then reload and check the text |
| "Upload at least 1 asset" | the picture is in the library but not attached to the listing: add it from the library panel |
| A tester's chip is not added | type the address then Enter (the chip), then Save and confirm |
| The privacy policy is refused | its address answers 404, or is not public: open it in a private window |
| The playground published nothing after a push | check `get_project` (`published_commit`) or the page itself; `sync_project` |

## 9. Never

- the keys, their passwords, `dist/`, `var/`, debug screenshots or tokens in git;
- accepting the Android SDK license, Google's terms or a questionnaire for the user without telling them;
- sending to production without the user's yes for that release;
- removing a phone's user / clone space, or uninstalling an app, without asking (data is lost);
- saying the game is "for children" unless the user wants it (it changes the rules and the visibility).
