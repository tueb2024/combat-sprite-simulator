# Architecture

## Vue d'ensemble

```
            bureau                                   navigateur
  games/<jeu>/app.php                     page web (engine/web) : jumphp.js
        │                                         │ télécharge php-web.wasm + php-web.data
        ▼                                         ▼ ccall('phpw_run', prélude + bootstrap/wasm.php)
  Application::boot()  ◄──────── même code ────────► Application::boot()
        │  container Symfony (YAML, ou précompilé dans cache/container.php pour le navigateur)
        ▼
  Engine::boot() : SDL, son, entrées, langue, options, première scène
        │
  desktop : Loop::addPeriodicTimer(1/max_fps, tick) puis Loop::run() (bloquant)
  wasm    : phpw.c -> emscripten_set_main_loop -> jumphp_frame() -> Engine::frame() = tick() + 1 tour de boucle
```

## Une frame (`Engine::tick()`)

1. `dt` = temps écoulé (borné à 0,1 s ; fixe 1/60 s en mode sans écran), × `timeScale`.
2. `InputManager::poll()` : vide la file SDL (clavier, souris, tactile, manettes, fenêtre).
3. `NetSession::poll()` si une session réseau est ouverte.
4. `SoundManager::update()` (enchaînement intro → boucle de la musique).
5. `SceneManager::update($dt)` : la scène du haut de la pile (et les fondus).
6. Événement `frame`, puis les états « appuyé cette frame » sont oubliés.
7. Rendu : `Renderer::beginFrame()` (dessin dans une texture à la résolution virtuelle), scènes (les
   overlays au-dessus de la scène du dessous), overlay de debug (F3), `endFrame()` (mise à l'échelle avec
   bandes noires, pixels nets, `SDL_RenderPresent`).

## Modules

| Namespace | Rôle | Classes clés |
|---|---|---|
| `Kernel` | démarrage, frame, configuration | `Application`, `Engine`, `GameConfig`, `Platform` |
| `Loop` | boucle ReactPHP, pas fixe | `GameLoop` (même API que dans ton jeu : `start(1)`, `addTimer`, `eachTick`), `FixedStep` |
| `Renderer` | fenêtre, dessin, textures, texte | `Sdl`, `Renderer`, `Window`, `BitmapFont`, `TtfFont`, `CrtFilter` |
| `Input` | entrées, actions nommées | `InputManager`, `ActionMap`, `Keyboard`, `Mouse`, `Touch`, `Gamepad` |
| `Scene` | écrans, caméra | `Scene`, `AbstractScene`, `SceneManager`, `Camera` |
| `Entity` | ce qui bouge | `Entity`, `GameObject`, `EntityManager`, traits `Sprite`, `Gravity` |
| `Physics` | collisions | `CollisionSystem`, `Aabb`, `TileCollision`, `CollisionResult` |
| `Tile` | cartes | `TileMap`, `TileSet`, `CollisionMask`, `TileMapRenderer`, loaders Tiled / tableau / binaire |
| `Audio` | son | `SoundManager`, `Synth` |
| `Ui` | menus, saisie | `Menu`, `MenuScene`, `TextInput`, `Hud` |
| `Net` | en ligne | `NetSession`, `LobbyScene`, `MessageCodec`, `SnapshotBuffer` |
| `Save` | fichiers du joueur | `Storage`, `Options` |
| `Bridge` | jeu → page web | `JsBridge` |

## Injection de dépendances

`Application::buildContainer()` enregistre les services du moteur (publics, autowirés), puis charge
`config/services.yaml` du jeu (`App\` → `src/`, sauf `src/Entity`). Toute classe qui implémente `Scene`
est publique et partagée automatiquement : `SceneManager` la récupère par son nom de classe, son
constructeur peut demander n'importe quel service. `CollisionSystem` et `EntityManager` ne sont pas
partagés (un par scène). Pour le navigateur, `tools/dump-container.php` précompile le container
(`cache/container.php`, ~0,5 s de démarrage en moins) ; `GameConfig` y est un service synthétique.

## Configuration et paramètres

`config/project.yaml` (`GameConfig::DEFAULTS` liste les clés). Le bureau lit aussi des variables
d'environnement, le navigateur des constantes définies par la page (`Platform::setting()`) :
`JUMPHP_WASM`, `JUMPHP_NET_URL` (`?net=`), `JUMPHP_ROOM` (`?room=`), `JUMPHP_LANG` (`?lang=`),
`JUMPHP_DEBUG` (`?debug=1`), `JUMPHP_MOBILE`, et pour les tests `JUMPHP_SMOKE`, `JUMPHP_SCREENSHOT`,
`JUMPHP_MAX_FRAMES`, `JUMPHP_INPUT`, `JUMPHP_SCENE`, `JUMPHP_SCENE_PARAMS`, `JUMPHP_REALTIME`.

## Modes sans écran

`JUMPHP_SMOKE` / `JUMPHP_SCREENSHOT` : pilote vidéo et son `dummy`, rendu logiciel dans une surface
(`SDL_CreateSoftwareRenderer`), frames de 1/60 s enchaînées au plus vite. Le test fumée transforme toute
alerte PHP en exception (code de sortie 1). La capture écrit la surface en BMP puis en PNG (GD).
