# The JumPHP runtime: use your own network from the browser

## What it is

A JumPHP application that runs **in the browser** (jumphp.net, `play.jumphp.net`) has no sockets: its TCP, SSH, UDP and ping connections go
through the **server's bridge** on jumphp.net, which only sees what the server sees. The **JumPHP runtime** is a small program installed on
*your* computer (an icon in the notification area). Once installed, the application connects **from your IP address and your network**: local
network, VPN, a server that only opens its port to your address.

## Language

The runtime speaks the computer's language (14 languages: fr en es de it pt nl pl ru uk tr ja zh ko; English otherwise). Order: the choice made in the window
("Language" list), the environment variable `JUMPHP_LANG`, the `install.json` file of the data folder (`{"lang": "fr"}`: an installer can write it; otherwise the runtime
writes it itself the first time a JumPHP page talks to it, with the page's language: `ws://127.0.0.1:47820/v1?lang=fr`), then the system's language (`LC_ALL` / `LC_MESSAGES` /
`LANGUAGE` / `LANG` on Linux, Windows' display language, `AppleLanguages` on macOS). The texts: `apps/runtime/config/lang.yaml` (tray menu, dialogs, the permission question)
and `apps/runtime/ui/lang.js` (the pages); `tests/LangTest.php` checks that they are complete.

## Why

- reach a database on your local network (`192.168.x.x`) that the server will never see;
- go through your company VPN;
- connect to a server whose firewall only accepts your IP;
- no "database ports only" limit of the server's bridge (with your consent, target by target).

## Is it safe?

Yes, by design: the runtime does not accept just anyone.

- it only listens on `127.0.0.1` (your machine), never on the network;
- it only accepts pages of the known JumPHP sites (`jumphp.net`, `play.jumphp.net`); another site, or a local program without an origin, is refused and logged;
- **two doors**: the administrator of jumphp.net approved the application *for this exact version of the code* (the ticket), **and** you allowed the application on your computer (a system window, never inside the page);
- one more confirmation the first time an application aims at a private address or an unusual port;
- your permissions can be revoked one by one or all at once; a refusal is remembered for 10 minutes;
- no local command, no access to your files, nothing listens for pages; traffic is never recorded (only origin, app, target, time and volume are);
- updates are signed.

Details: `docs/runtime-protocol.md` (French).

## Install

1. **Download** for your system at https://jumphp.net/runtime or in the releases of https://git.jumphp.net/jumphp/jumPhp (tag `runtime-v<version>`):
   Linux `runtime-setup-linux-x86_64`, Windows `runtime-setup.exe` (macOS: coming soon). Bare binaries: `runtime-linux-x86_64`, `runtime-windows-x86_64.exe`.
2. **Install**: Linux `chmod +x runtime-setup-linux-x86_64 && ./runtime-setup-linux-x86_64`; Windows: double-click `runtime-setup.exe`.
3. **Start it**: an icon appears in the notification area; it then starts with your session.
4. **Allow the application**: the first time, a runtime window asks "the site play.jumphp.net wants to use your network for the application X. Allow?".
   Chrome may also ask for "Access devices on the local network": accept. Safari blocks this content: the app stays on the server's bridge.

The application guides you itself (the badge, below) and its help window turns green by itself.

## Use it in an app, step by step

1. Nothing to install: the client is part of the engine (since 0.5.27); update JumPHP (`bin/jumphp update`).
2. Your connections do not change:

   ```php
   $c = $this->engine->tcp()->connect('192.168.1.10', 3306);   // or ssh(), udp(), icmp()
   $c->on('open', fn () => ...);
   $c->on('error', fn (string $why) => $this->status->text = $why);
   ```
3. In the form, the badge next to the connection fields:

   ```yaml
   - {class: JumPHP\Runtime\RuntimeBadge, id: runtime}
   ```
4. The setting (project.yaml):

   ```yaml
   project:
     net_mode: auto        # auto | server | local
   ```
5. To react in code: `$this->engine->runtime()->state()`, `->check()`, `->pair($id, $title)`, `->on('change', fn (string $state) => ...)`, `->bridgeUrl()`.
6. Tests: `$engine->setRuntime(new JumPHP\Runtime\ManualRuntime(RuntimeLink::READY))` gives the state you want, no browser needed.

## The badge

| Colour | State | Text | On click |
|---|---|---|---|
| green | `ready`: installed and allowed | Local network active | nothing |
| orange | `running` (installed, not allowed) or `refused` | Allow this app on your network | asks the permission (`pair`) |
| red | `missing`: not installed or not started | Local network off - install the runtime | opens the install guide (`RuntimeGuide`) |
| grey | `unsupported`, `net_mode: server`, or still looking | Server network | nothing: the server's bridge is enough |

`RuntimeGuide` is the dialog: why, the 4 steps (download for your system, install, start, allow), the links to jumphp.net/runtime and the
releases. It checks again every 2 s. Open it by hand with `$this->engine->scenes->push(RuntimeGuide::class)`. The system is read from the browser
(`jumphp-runtime.js` module) or given by the runtime; unknown, all systems are shown. The proposed version is a setting: `jumphp_runtime: {version: 0.1.0}`.

## Modes

| `net_mode` | Behaviour |
|---|---|
| `auto` (default) | the runtime when its state is `ready`, else the server's bridge |
| `server` | always the server's bridge |
| `local` | the runtime (`running` or `ready`); otherwise the error `the JumPHP runtime is not available (state: missing)` |

Also `JUMPHP_NET_MODE` (page address `?net_mode=local`, or an environment variable), which wins over project.yaml. The ticket is always asked from
the server; only the address opened afterwards changes. On the desktop none of this applies: the app already has its network.

## Troubleshooting

| Symptom | What to do |
|---|---|
| Red badge although installed | is it running (icon)? A firewall blocking `127.0.0.1:47820-47829`? Chrome: accept "local network devices" |
| Only detected after fully closing Chrome | fixed: the page waits for the answer to Chrome's "local network" question without closing the connection (closing it left the question pending), and looks again as soon as it is back (tab, install finished, network): at once, then at 0.5 s, 1.5 s, 4 s |
| Grey badge in Safari | Safari blocks `ws://127.0.0.1` from an https page: the server's bridge is used |
| Orange stays orange | click the badge and answer "Allow" in the runtime window (30 s without an answer = refusal; a refusal lasts 10 min) |
| `the JumPHP runtime is not available (state: ...)` | `local` mode without a runtime: install it, or switch to `auto` |
| Origin refused (403) in the runtime's log | the site is not in its allow-list (runtime window) |
| Connection refused at the ticket | the administrator did not approve this version of the app (`docs/tcp.md`): both doors must be open |

## End-to-end tests

`tools/e2e/run.sh [chrome|firefox|all]` starts the real daemon (Docker, Xvfb, temporary `HOME`) and drives headless Chrome and Firefox: an **https** page
opens `ws://127.0.0.1:<port>/v1` and receives `hello`; an origin outside the allow-list is refused (403), and so is a wrong `Host` (421); the real
`jumphp-runtime.js` module finds the runtime on 47820..47829 and reports the system and the version; a forged ticket gets `error ticket`; an origin added in `config.json` is accepted.
It does **not** cover a valid ticket + the consent dialog (a person is needed; that is PHPUnit's `ServerTest`, with a fake dialog), nor Safari
(mixed content blocked, fallback to the server's bridge), nor Chrome's "Local Network Access" prompt. Details: `apps/runtime/tests/e2e/README.md`.

## For AI agents

- **When to use it**: a JumPHP application *for the browser* that must reach a machine of the user's network (local database, VPN, IP-filtered server) over TCP / SSH / UDP / ping. Not for a game, not for a desktop app (it already has its network).
- **How**: update the engine (0.5.27 or later), a `RuntimeBadge` in the connection form, `net_mode: auto` (the default). Keep the connection code unchanged (`$engine->tcp()` etc.): no special "local" code.
- **Never**: bypass the consent (talk to the runtime only through `pair()` and the connections, never write `grants.json`, never open a WebSocket to `127.0.0.1:47820` by hand); ask the user to disable a security (firewall, Local Network Access, mixed content, the origin allow-list); force `net_mode: local` by default in a published app (a user without the runtime would only get errors); put a secret or a private address in the repository.
- Tests: `ManualRuntime`; never assume a real runtime. Check the badge with `bin/jumphp shot` and look at the picture.
