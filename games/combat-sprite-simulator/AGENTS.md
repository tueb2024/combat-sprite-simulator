# Combat Sprite Simulator

Read `../../AGENTS.md` first. The project uses canonical JumPHP and `config/project.yaml`.

- `TitleScene` starts `PlayScene`.
- `PlayScene` is the PHP simulation and rendering loop. It handles movement, player and enemy shots, AABB collisions, scoring, lives, a 30-second opening wave, the boss, and end states.
- `Entity/Hitbox.php` holds the AABB predicate.
- Sprites in `assets/sprites/` come from `../../tools/blender/` on the PC GTX. `atlas.json` defines frame rectangles and hitboxes.
- The smoke test starts `PlayScene` with `demo: true`; `tests/CombatTest.php` tests input, shooting, collision, victory and restart.
- The boss weaponry and battle are arcade fiction. The actual Junkers G 38 was a transport.

Run `bin/jumphp install combat-sprite-simulator`, `bin/jumphp test combat-sprite-simulator`, `bin/jumphp shot combat-sprite-simulator 120`, `bin/jumphp engine:download`, `bin/jumphp build combat-sprite-simulator`, `bin/jumphp webshot combat-sprite-simulator`.
