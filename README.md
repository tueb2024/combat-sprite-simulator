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

## Private references and Blender pipeline

The mission is in [docs/CODEX-ASSETS-DOCKER.md](docs/CODEX-ASSETS-DOCKER.md). Eight historical PDF books were copied from the Lenovo to `~/.local/share/combat-sprite-simulator/reference-books` on Aiphonse and verified against the Lenovo SHA-256 manifest. They are **not** bundled with this repository. Run `bash tools/references/index-on-aiphonse.sh` in an ordinary Aiphonse terminal to rebuild the offline, page-searchable Docker index. Four books are text-searchable; four are scans or nearly textless. `tools/references/ocr_page.py` handles only explicitly selected pages. The [catalog](docs/reference-catalog.json) lists the index state without private text.

The original Blender entry points in `tools/blender/render_aircraft.py` and `assemble_atlas.py` accept `--spec` and `--out` to use the parametric pipeline. Its [aircraft specification](tools/blender/aircraft-spec.json) holds dimensions, engine layout, source pages, evidence status, game hitboxes and animation states for the Hurricane Mk I, Junkers G 38 and Sopwith F.1 Camel. Public facts and short book/page citations are versioned; PDFs, page images and extracted text stay private. The generator builds original 3D geometry and renders 36 RGBA views on the GTX PC, then assembles four atlases. Hurricane and Junkers retain the original game atlas sizes and frame order. Run `python3 tools/blender/validate_assets.py` to check PNG integrity, alpha coverage, frame dimensions and manifests.

The game continues to use JumPHP's official Docker-based `bin/jumphp` commands. GitHub Actions receives only the original atlas assets and source data, never the books or extracted pages. On Aiphonse, the Docker socket works through the `docker` group; an isolated Codex session may require approved execution with that group. No socket permission change is needed.

## 1940 fighter and dive-bomber sprites

The active game now uses a documented Hurricane Mk I for the player, a quick Bf 109 E-4, and a slower Ju 87 B-2 Stuka. The Junkers G 38 remains the fictional boss. The three new aircraft use profiled 3D meshes built from the original factual [specifications](assets/aircraft/specs/), then rendered in Blender 5.2.3 on the GTX PC. Each game atlas has nine transparent 512 px frames: three physical bank angles by three propeller phases. The [manifest](games/combat-sprite-simulator/assets/sprites/ww2-atlas.json) lists frame rectangles and game sizes.

Regenerate the complete set from Aiphonse with `bash tools/blender/run_on_gtx.sh --aircraft all`. The script sends only Python and JSON inputs over SSH to the GTX PC and retrieves the `.blend` files, rendered atlases, manifest, and comparison image. No reference PDF or page scan is sent there. The source scenes are in `assets/source/ww2/`; local render output is in `artifacts/ww2/`. The reviewed [comparison sheet](docs/images/ww2-comparison.png) and [GPU render log](docs/ww2-render-log.json) are versioned. Run `python3 tools/blender/validate_ww2.py` to check PNG integrity, transparency, frame layout, and manifest consistency.

For a gameplay screenshot with both enemy types, use `JUMPHP_SCENE='App\Scene\PlayScene' bin/jumphp shot combat-sprite-simulator 155 var/screenshots/ww2-wave.png`. For the boss, add `JUMPHP_SCENE_PARAMS='{"boss":true}'` and use the same CLI. All PHP tests, screenshots, and browser builds run through `bin/jumphp` in Docker.

Reviewed local captures: [Hurricane with Bf 109 and Stuka](docs/images/ww2-wave.png) and [Hurricane against the G 38](docs/images/ww2-boss.png).
