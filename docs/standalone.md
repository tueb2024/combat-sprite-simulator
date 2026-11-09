# The game as one executable (Linux, Windows)

`bin/jumphp export:standalone <game>` makes **one file** that runs the game on a computer with nothing installed:
no PHP, no Docker, no SDL. The player downloads it and runs it. About 23 MB (Pong, 3D Jumpball): PHP and its
libraries ~20 MB, the rest the game.

```
bin/jumphp export:standalone pong              # games/pong/dist/standalone/pong-linux-x86_64
bin/jumphp export:standalone pong --windows    # games/pong/dist/standalone/pong-windows-x86_64.exe
bin/jumphp export:standalone pong --linux --windows
```

## What is inside

| Part | What | Where it comes from |
|---|---|---|
| PHP | [StaticPHP](https://github.com/crazywhalecc/static-php-cli)'s **micro**: PHP 8.5 as one executable that runs the PHAR appended to it | built once per set of extensions (below) |
| the extensions | php-sdl, sdl_image, sdl_mixer, sdl_ttf, PHP-OpenGL (with JumPHP's patch), PHP-OpenAL, gd, zip, openssl, mbstring, bcmath, sockets… | JumPHP's StaticPHP registry: `docker/standalone/registry` |
| the libraries | SDL2, SDL2_image (PNG/JPG/GIF/BMP), SDL2_mixer (WAV, OGG, MP3, FLAC), SDL2_ttf (FreeType inside), OpenAL Soft: static | same |
| the game | `games/<game>` with its `vendor/` (the engine), as a PHAR; not `var/`, `dist/`, `tests/`, `site/` | `tools/standalone.sh` |

On Linux the executable needs glibc 2.17 or newer (any Linux since 2013: built with Zig's toolchain) and loads
the display (X11, or XWayland), OpenGL and the sound (PulseAudio/PipeWire, ALSA) from the player's system when
the game starts, as every Linux game does. On Windows nothing is needed (Windows 10 or newer).

HTTPS (`$engine->http()`, online play over `wss://`): PHP's openssl is inside; its CA certificates are the Linux
system's (Debian / Ubuntu / Arch, Fedora / openSUSE), else the game's own `cacert.pem` (Windows, the others), through
`SSL_CERT_FILE` (`tools/standalone-stub.php`).

### The game's own php.ini settings

`project.yaml` `php_ini: "my.setting=1\nother=2"` (one line, `\n` between settings) adds them to the executable's php.ini
section: `get_cfg_var('my.setting')` reads them. A tool can rewrite that section of a copy of the executable later
(jumphp2installer writes a server's address there); to keep every offset in place (Windows), rewrite it at the same
size, from room reserved by such a setting.

## When the player runs it

The first launch unpacks the game (its PHP, pictures, sounds, fonts: SDL reads real files) into the player's cache,
in a folder of its own version; later launches start at once. A new version of the game unpacks beside the old one.

| | Linux | Windows |
|---|---|---|
| the game, unpacked | `~/.cache/jumphp/<id>-<build>` (`$XDG_CACHE_HOME`) | `%LOCALAPPDATA%\JumPHP\<id>-<build>` |
| saves and options | `~/.local/share/<id>` (`$XDG_DATA_HOME`) | `%APPDATA%\<id>` |

`<id>` is project.yaml's `id`, `<build>` a hash of the game's files. `JUMPHP_STANDALONE_DIR` unpacks elsewhere,
`JUMPHP_VAR` puts the saves elsewhere (`tools/standalone-stub.php`).

## The micro binaries

The micro (PHP + the extensions) is the long part: ~15 minutes to build, the same for every game. It is kept in
`~/.jumphp/standalone/micro-<target>-<key>.sfx`, `<key>` being a hash of what makes it (`tools/standalone-key.sh`:
PHP's version, `tools/standalone-extensions.txt`, `docker/standalone` with StaticPHP's commit, the registry and
its patches). `export:standalone` looks for it, in order:

1. in `~/.jumphp/standalone`;
2. on JumPHP's GitHub releases: `standalone-<key>/micro-<target>.sfx` (`JUMPHP_STANDALONE_RELEASES`: another
   address);
3. Linux: built here, in Docker (`docker/standalone`: StaticPHP pinned, `SPC_TARGET=native-native-gnu.2.17`).
   `JUMPHP_STANDALONE_BUILD=1` builds even when a release has it. Its build files stay in
   `~/.jumphp/standalone/spc` (the next build only does what changed).

**Windows' micro is built on Windows only** (Visual Studio 2022): by the GitHub workflow
`.github/workflows/standalone.yml` (Actions > standalone > Run workflow). It builds both micros and publishes them
as the release `standalone-<key>`; run it after each change of `docker/standalone`, `tools/standalone-extensions.txt`
or `docker/ext/php-opengl.patch`. A fork of JumPHP runs it in its own repository and sets
`JUMPHP_STANDALONE_RELEASES=https://github.com/<you>/<repo>/releases/download`.

On Windows the extensions are built with JumPHP's `config.w32` (`registry/w32`); Windows' OpenGL library has only
OpenGL 1.1, so PHP-OpenGL's newer functions are asked to the driver on their first call (`registry/functions.php`,
`jumphp_wgl`).

## Limits

- x86_64 only (no ARM, no macOS yet: StaticPHP can, the registry has no macOS build of SDL yet).
- The executable is not signed: Windows SmartScreen asks "Run anyway" the first time; on Linux, `chmod +x` after
  a download.
- A game that runs other programs, or reads files outside its folder, does it on the player's computer.

## Le cache des micros : ne jamais copier un fichier d'une clé à l'autre

`~/.jumphp/standalone/micro-<système>-<clé>.sfx` : la clé est le hash de `tools/standalone-key.sh` (version de PHP, liste d'extensions, StaticPHP, `docker/standalone`,
patchs). Jeux et applications graphiques : `tools/standalone-extensions.txt` (SDL, OpenGL, OpenAL) ; applications de bureau Tauri : `tools/app-extensions.txt` (FFI, sans SDL) :
deux clés, deux fichiers. Copier ou renommer un micro sous une autre clé « pour éviter de reconstruire » donne des exécutables qui plantent chez les joueurs
(`Call to undefined function SDL_Init()`), c'est arrivé le 6 novembre 2026. `tools/standalone.sh` vérifie maintenant que le micro contient chaque extension de sa liste
(symboles `SDL_CreateWindow`, `IMG_Load`, `Mix_OpenAudio`, `TTF_OpenFont`, `glGenBuffers`, `alcOpenDevice`) : un mauvais fichier est retiré du cache et l'export s'arrête.
La clé a changé ? Reconstruire : Linux `JUMPHP_STANDALONE_BUILD=1 bin/jumphp export:standalone <id> --linux`, Windows / macOS par le workflow `standalone`.
Avant de publier un exécutable : le lancer (`SDL_VIDEODRIVER=dummy SDL_RENDER_DRIVER=software SDL_AUDIODRIVER=dummy timeout 10 ./fichier`).
