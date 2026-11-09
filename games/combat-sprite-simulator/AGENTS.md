# Combat Sprite Simulator

Read `../../AGENTS.md` first. The project uses canonical JumPHP and `config/project.yaml`.

- `TitleScene` starts `PlayScene`.
- `PlayScene` is the PHP simulation and rendering loop. It handles movement, player and enemy shots, AABB collisions, scoring, lives, a 50-second opening wave, the boss, and end states.
- `Entity/Hitbox.php` holds the AABB predicate.
- Sprites in `assets/sprites/` come from the parametric Blender 5.x pipeline in `../../tools/blender/` on the PC GTX. `aircraft-spec.json` holds source citations and geometric parameters. `atlas.json` defines frame rectangles and hitboxes. Validate with `python3 tools/blender/validate_assets.py` from the repository root.
- The smoke test starts `PlayScene` with `demo: true`; `tests/CombatTest.php` tests input, shooting, collision, victory and restart.
- Ground is a small IGN BD ORTHO® 2023 extract, mirrored in `render()` to scroll; attribution is in the game HUD and `../../CREDITS.md`.
- The boss weaponry and battle are arcade fiction. The actual Junkers G 38 was a transport.
- The active player and wave sprites are now the WWII atlas set (`hurricane-mk1`, `bf109-e4`, `ju87-b2`) generated on GTX with `tools/blender/run_on_gtx.sh`. `EnemyProfile.php` owns enemy speed, HP, hitboxes and bank frames. `ww2-atlas.json` holds the nine-frame layout. Validate with `python3 tools/blender/validate_ww2.py` from the root. Keep G 38 as boss.

Run `bin/jumphp install combat-sprite-simulator`, `bin/jumphp test combat-sprite-simulator`, `bin/jumphp shot combat-sprite-simulator 120`, `bin/jumphp engine:download`, `bin/jumphp build combat-sprite-simulator`, `bin/jumphp webshot combat-sprite-simulator`.
