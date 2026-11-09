# {{GAME_ID}} — notes for agents

This game is built with JumPHP. **Read `../../AGENTS.md` first** (rules, commands, API).

## Checklist

```bash
bin/jumphp test {{GAME_ID}}                 # lint + phpstan + headless smoke test (must pass)
bin/jumphp shot {{GAME_ID}} 60              # title screen -> games/{{GAME_ID}}/var/screenshots/shot.png
JUMPHP_INPUT='[[5,"confirm"]]' bin/jumphp shot {{GAME_ID}} 240 var/screenshots/play.png
bin/jumphp build {{GAME_ID}} && bin/jumphp webshot {{GAME_ID}} 6 '[[1,"Enter"]]'   # browser (needs bin/jumphp engine:build)
```

## This game

Started from the JumPHP skeleton (a small platformer). Update this section as the game grows:

- **What it is**: ...
- **Scenes**: `TitleScene` (menu) → `PlayScene` (level) ; overlays `PauseScene` ; `OptionsScene` (pushed).
- **Entities**: `Player` (Sprite + Gravity + TileCollision), `Coin`.
- **Controls** (`config/input.yaml`): arrows / WASD move, Space jumps, Escape pauses.
- **Smoke test** (`config/project.yaml` smoke): `PlayScene` with `demo: true` (a bot runs and jumps).
- **Non-obvious**: the tileset is drawn with GD in `PlayScene::makeTileset()`; replace it with a PNG
  (`assets/tiles/`) or a Tiled map (`TiledJsonLoader`).
