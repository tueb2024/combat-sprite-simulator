# JumPHP tutorial, from A to Z

From an empty machine to your game online on your own domain, over HTTPS. Allow about 30 minutes, most of it
waiting for Docker. Version française : [tutoriel.md](tutoriel.md).

In order, this tutorial covers:

- A. creating the project;
- B. the wizard;
- C. Claude Code writes the game;
- D. playing and testing;
- E. changing it yourself;
- F. in the browser;
- G. online on your own server;
- H. on Git;
- I. updating JumPHP.

---

## A. Create the project

**Requirements**: a Linux machine, a Mac, or Windows with WSL2, and a terminal. The wizard offers to install
the rest (git, Docker...).

```bash
composer create-project jumphp/jumphp my-game
```

If you have no composer (or no PHP), two other ways give the same result:

```bash
# with Docker
docker run --rm -it -v "$PWD":/app -w /app composer create-project jumphp/jumphp my-game
cd my-game && bin/jumphp

# with git
git clone https://git.jumphp.net/jumphp/jumPhp.git my-game && cd my-game && bin/jumphp
```

The `my-game` folder holds all of JumPHP:

| Folder | What it holds |
|---|---|
| `engine/` | the engine |
| `skeleton/` | the template of a game |
| `games/` | the example games: pong, jumpball in 3D, breakout... |
| `bin/jumphp` | the CLI |
| `AGENTS.md` | the complete guide, for humans and AIs alike |

The folder is linked to the JumPHP repository with git, as the remote `jumphp`.

## B. The wizard

With composer, the wizard starts by itself. Otherwise, run `bin/jumphp`. It asks one question at a time and
always asks before doing anything. Enter takes the answer in brackets, Ctrl+C stops the wizard, and
`bin/jumphp init` resumes it.

1. **Tools.** The wizard checks git, curl and Docker, and offers to install whatever is missing. On Linux it
   also adds you to the `docker` group. PHP, composer, emscripten and the web server all run in Docker:
   there is nothing else to install.
2. **Your game.** It asks for:
   - the **name** shown (e.g. `Space Race`);
   - the **id**: the folder `games/space-race`, which is also the web address;
   - a one-line **description**;
   - the **resolution**: 320×180 by default. These are the game's pixels, scaled up to the window;
   - the default **language**.

   The wizard then builds the runtime's Docker image (about 5 min the first time) and creates the game from
   the skeleton, a tiny platformer.
3. **Browser engine.** Pick "download it": about 10 MB instead of a 25-minute build. Then accept the first
   test.
4. **Server.** Now or later: see part G.
5. **GitHub.** Now or later: see part H.
6. **Claude Code.** Describe what your game should do in a few lines, then enter an empty line. For example:

   ```
   A vertical shoot'em up: the ship shoots waves of aliens, a boss every 3 levels, power-ups (double
   shot, shield), 3 lives, best score saved. Playable with the keyboard, a gamepad and on phones.
   ```

The wizard writes `games/space-race/BRIEF.md`: your text, plus everything Claude needs to know (read
AGENTS.md, test, look at the screenshots, publish nothing without asking you). It then starts Claude Code
with that prompt, offering to install it first if it is missing.

## C. Claude writes the game

Claude reads `AGENTS.md`, replaces the skeleton with your game, then checks its own work:

- `bin/jumphp test space-race`: lint, static analysis, and 600 frames played headless;
- `bin/jumphp shot space-race`: PNG screenshots that it looks at;
- the browser build, with `bin/jumphp build` then `bin/jumphp webshot`.

Ask for changes the way you would ask a colleague: "faster enemies in level 2", "a 2-player mode",
"translate it to French"... To start it again later: `claude "$(cat games/space-race/BRIEF.md)"`, or just
`claude` in the folder.

## D. Play and test

```bash
bin/jumphp run space-race        # in a window, with sound
bin/jumphp test space-race       # every check
bin/jumphp shot space-race 120   # screenshot after 2 s: games/space-race/var/screenshots/shot.png
```

The basic keys:

| Key | Action |
|---|---|
| arrows + Enter | menus |
| Escape | pause / back |
| F11 | fullscreen |
| F3 | frames per second |

A plugged-in gamepad just works.

## E. Change it yourself

A game is made of:

| File | What it holds |
|---|---|
| `config/project.yaml` | title, resolution, first scene, the demo that `test` plays |
| `config/input.yaml` | the actions (`jump`, `fire`...) mapped to keys, gamepad and touch |
| `config/lang.yaml` | the translations |
| `src/Scene/*.php` | the screens: title, game, pause, game over |
| `src/Entity/*.php` | the things that move |

A minimal scene:

```php
<?php

namespace App\Scene;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

final class PlayScene extends AbstractScene
{
    private float $x = 0;

    public function enter(array $params = []): void
    {
        $this->x = 0;                                  // scenes are reused: reset everything
    }

    public function update(float $dt): void            // $dt in seconds
    {
        $this->x += $this->actions()->axis('left', 'right') * 100 * $dt;
    }

    public function render(Renderer $r): void
    {
        $r->clear([0, 0, 40]);
        $r->fillRect($this->x, 80, 16, 16, '#ffcc00');
        $this->font()->draw($r, 'HELLO', $this->width() / 2, 20, 2, [255, 255, 255], 'center');
    }
}
```

Three rules to remember: never block a frame (no `sleep()`), go through `bin/jumphp` for everything, and draw
only in `render()`. The rest is in [AGENTS.md](../AGENTS.md). The games in `games/` are models: pong for menus,
AI and online play, jumpball for 3D with WebGL.

## F. In the browser

```bash
bin/jumphp build space-race      # -> games/space-race/dist/web: static files
bin/jumphp serve space-race      # http://localhost:8080
```

The same PHP code runs in the page: PHP is compiled to WebAssembly, OpenGL becomes WebGL there and OpenAL
becomes Web Audio. Phones get an on-screen gamepad.

## G. Online on your own server

You need:

- a Linux server you can reach over SSH. A small VPS is enough, since the game is static;
- a domain name whose DNS **A** record (and **AAAA** for IPv6) points to that server, for example
  `space-race.example.com`.

```bash
bin/jumphp deploy:setup
```

The questions:

1. **SSH access** (`user@host`). If the connection asks for a password, the wizard offers to create an SSH
   key and install it on the server (`ssh-copy-id`).
2. **Domain**: `space-race.example.com`.
3. **Web server**:
   - **FrankenPHP alone**: the simplest. Ports 80 and 443 must be free on the server, and the HTTPS
     certificate is automatic;
   - **Traefik + FrankenPHP**: Traefik handles HTTPS, and other sites can later go behind the same Traefik;
   - **behind a Traefik already installed**: the wizard asks for its Docker network, entry point and
     certificate resolver.
4. **Let's Encrypt e-mail**: used for expiry notices.
5. **Folder on the server**: `/opt/jumphp/space-race` by default.

If Docker is missing on the server, the wizard installs it (or later: `bin/jumphp server:setup`). Then, and
again for every new version of the game:

```bash
bin/jumphp deploy
```

The command builds the game, sends only what changed, and runs `docker compose up -d` on the server. The
game is then at `https://space-race.example.com/`. The very first visit can take a few seconds while the
certificate is issued. To see the web server's logs: `bin/jumphp server:logs`. To put several games on one
domain (a home page plus one folder per game): `bin/jumphp deploy game1 game2`.

## H. On Git

```bash
bin/jumphp github
```

The wizard makes the first commit, installs `gh` and signs you in (`gh auth login`), then creates the
repository, private or public. With a public repository you can also publish the game for free on Git
Pages: `bin/jumphp pages space-race` puts it at `https://<account>.github.io/<repo>/`.

## I. Update JumPHP

```bash
bin/jumphp update            # merges the newest JumPHP (your commits are kept)
bin/jumphp setup             # Docker images, if the runtime changed
bin/jumphp engine:download   # the browser engine of the new version
```

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `docker: permission denied` | You just joined the `docker` group: log out and back in, or run `newgrp docker`, then `bin/jumphp init`. |
| The wizard did not start after composer | `cd my-game && bin/jumphp` |
| No window with `bin/jumphp run` | It needs a display (X11 or Wayland). Without one, use `bin/jumphp test` and `bin/jumphp shot`. |
| No sound | It needs PulseAudio or PipeWire. The game still runs, silently. |
| `engine:download` fails | `bin/jumphp engine:build` (about 25 min, once) |
| The site does not answer over HTTPS | Check that the DNS points to the server and that ports 80 and 443 are open, then run `bin/jumphp server:logs`. |
| Unknown system | Install Docker by hand (https://docs.docker.com/get-docker/), then run `bin/jumphp init` again. |

Machine diagnosis: `bin/jumphp doctor`. Every command: `bin/jumphp help`.
