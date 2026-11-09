# Combat Sprite Simulator

A separate JumPHP game project. The first mission is a 1940 inspired vertical arcade battle: fly a Hurricane, survive a short wave, then defeat a fictional armed Junkers G 38. The historic G 38 was a four engine transport, not this game's combat aircraft.

## Run

```sh
bin/jumphp install combat-sprite-simulator
bin/jumphp test combat-sprite-simulator
bin/jumphp shot combat-sprite-simulator 120
bin/jumphp engine:download
bin/jumphp build combat-sprite-simulator
bin/jumphp webshot combat-sprite-simulator
bin/jumphp serve combat-sprite-simulator 8181
```

Open `http://localhost:8181` on the same machine. For a second machine, use an SSH tunnel to that port. Controls: arrows or WASD to move, Space to fire, Enter to start or restart.

This repository uses the canonical JumPHP source at `https://git.jumphp.net/jumphp/jumPhp.git`, commit `f9365d7375c804d1ce4bbbd48adabbf7e7e1fe52`. Its `jumphp` Git remote points there; `origin` points to this independent game's private GitHub repository.

The scrolling background is a licensed IGN BD ORTHO® 2023 extract; see `CREDITS.md` for its source and attribution. The 3D aircraft sprites are original Blender renders; their script is in `tools/blender/` and source scene in `assets/source/`.
