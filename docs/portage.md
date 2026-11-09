# Réimporter ton jeu de plateforme existant

JumPHP reprend la pile et les conventions de ton jeu actuel : boucle ReactPHP (`GameLoop::start(1)`
piloté par le navigateur), container Symfony (`config/services.yaml` autowiré), événements Evenement,
entités en traits avec `update` aliasés, format des animations, lignes de menu
`[label, valeur, action, côté]`, textures en tranches `['texture', 'width', 'height']`. Le portage
consiste surtout à **déplacer le code spécifique du jeu dans des scènes** et à brancher ses classes sur
les services du moteur.

## Démarche

1. `bin/jumphp new <id> "<TITRE>"` puis supprimer `src/Scene/PlayScene.php`, `src/Entity/*` du squelette.
2. Copier le code du jeu dans `src/` (namespace `App\`, ou garder le tien : adapter `composer.json`
   `autoload` et `config/services.yaml`), les assets dans `assets/`.
3. Remplacer les classes « moteur » par celles de JumPHP (tableau ci-dessous) ; garder les classes de jeu.
4. Découper l'ancien `Game.php` (boucle, états, événements) en scènes : titre / carte / niveau / bilan /
   game over / crédits → une scène chacune (`switchTo`, `push` pour les overlays).
5. `bin/jumphp test <id>` (prévoir une scène `smoke` en démo automatique), `bin/jumphp shot`, puis le navigateur.

## Correspondances

| Ancien | JumPHP | Notes |
|---|---|---|
| `app.php` + commande Console | `games/<id>/app.php` → `Application::boot(__DIR__)->run()` | plus de Symfony Console |
| `Loop\GameLoop` | `JumPHP\Loop\GameLoop` | même API (`addTimer`, `addPeriodicTimer`, `nextTick`, `start($maxTicks)`, `eachTick`, `pause/resume/deleteEachTick`) |
| timer périodique de `Game::run()` + calcul du `deltaTime` | `Engine::tick()` | le `$dt` arrive dans `Scene::update($dt)` ; `Engine::$timeScale` (PAL 50/60) |
| sous-pas physique, accumulateurs 60 Hz | `JumPHP\Loop\FixedStep` | `run($dt, fn ($h) => ...)` |
| `Renderer\Sdl` (`initSDL`, `loadTexture`, `getTextures`, `registerTexture`, `loadGroup`, `releaseGroupsExcept`, `setTextureVariant`) | `JumPHP\Renderer\Sdl` | mêmes noms ; découpe GD > 4064 px incluse |
| `Renderer\Renderer` (cible de rendu + letterbox) | `JumPHP\Renderer\Renderer` | `resizeView()` = ancien `resizeTarget()` ; `getRenderer()` pour les appels `\SDL_*` directs |
| `Renderer\Window` (singleton) | `JumPHP\Renderer\Window::getInstance()` | `toggleFullscreen()` |
| `SdlImage::loadImage` + clé de couleur | `Sdl::loadTexture($nom, $chemin, [r, g, b])` | |
| `TextRenderer` / `SdlTtf` | `TtfFont` (implémente `TextDrawer`) | garder ton `drawStyled` dans une sous-classe si besoin |
| `CrtFilter` | `JumPHP\Renderer\CrtFilter::apply($r)` | |
| `InputManager`, `InputKeyboard`, `InputTouchpad` | `JumPHP\Input\*` | mêmes méthodes clavier (`isKeyPressed/Held/Released`, `getLastKeyPressed`) ; le `break` manquant des événements tactiles est corrigé |
| touches codées en dur dans `eventKeyPressed` | `config/input.yaml` + `ActionMap` | les touches de debug restent possibles via `$engine->input->on('key', ...)` |
| `Scene\Screens` (machine à états en chaînes) | une `Scene` / `MenuScene` par écran | `Screens::menuItems()` → `MenuScene::items()` (même format de lignes) |
| `Scene\Camera` | `JumPHP\Scene\Camera` | `follow()` (= `stickTo`), zone morte, bornes, `lock()`, position entière |
| `Level` (`bin`, `meta`, `solidity`) | `BinaryLoader::load()` | le `.meta` (tableau PHP) donne largeur / hauteur / départ ; formes de solidité via `$shapes` (octet → `CollisionMask`), bit 7 = premier plan |
| `tileCol.data.php` (masques 32×32) | `CollisionMask::fromRows()` / `heights()` | convertir chaque forme en lignes `#` / `.` une fois, à l'exécution ou dans un script |
| `Level::setTile` + écouteurs | `TileMap::setTile()` + `onTileChanged()` | `getVersion()` pour invalider les caches |
| `TileSet::generateTiles` | `TileSet::srcRect()` | |
| `Scene::drawTiles` (cache de tuiles) | `TileMapRenderer` | garder ton cache LRU si les performances l'exigent : `Renderer::setTarget()` |
| `CollisionSystem::checkCollisions($entity, $level, $dt)` | `CollisionSystem::checkCollisions($entity, $map, $dt)` (= `move()`) | capteurs génériques au pixel ; tes comportements de blocs (ressorts, pics, tapis) → événement `touch` + propriétés de tuiles, ou garder ton `applyBlockBehaviour` |
| `objectGroundProvider`, `waterLineProvider` | `setPlatformProvider()` ; eau : à garder côté jeu | |
| `Entities\Entity`, `Utils\Vector` | `JumPHP\Entity\Entity`, `JumPHP\Util\Vector` | mêmes propriétés et méthodes (+ `getBoundingBox`, `intersects`, `kill`) |
| `Entities\Sprite` | `JumPHP\Entity\Sprite` | même format `$animations` et `reverse*` ; appeler `initSprite($sdl, $feuille)` au lieu du constructeur du trait ; `duration` / `loop` par animation en plus |
| `Physics\Gravity` | `JumPHP\Entity\Gravity` | `$gravity`, `$maxFallSpeed` |
| `Physics\Colision` | `JumPHP\Physics\TileCollision` | drapeaux `onGround`... |
| `GameObject` | `JumPHP\Entity\GameObject` | constructeur `(x, y, w, h, type, name, properties)` |
| `GameObjectManager` (gros `match` des types) | `EntityManager::register($type, $classe)` + `spawnFromMap()` | fenêtres d'activité : `$activityMargin`, `$alwaysActive` |
| `SoundManager` (`playSfx` par index, musique intro → boucle) | `JumPHP\Audio\SoundManager` | par nom (un index converti en chaîne marche) ; `loadMusic($nom, $boucle, $intro)` |
| `Utils\Lang` (sources en français) | `JumPHP\Util\Lang` + `config/lang.yaml` | la langue source est libre : mettre la table inverse |
| `Save\Options`, fichiers `var/` | `JumPHP\Save\Options`, `Storage` | IndexedDB dans le navigateur, comme avant |
| `Utils\Bridge` (lignes JSON dans `/tmp/...`) | `JumPHP\Bridge\JsBridge` | même principe ; types personnalisés → `window.JumPHP.onBridge` |
| `Multiplayer\Online`, trait `VersusNetwork` | `JumPHP\Net\NetSession`, `MessageCodec`, `SnapshotBuffer`, `LobbyScene` | même php-wasm-network, mêmes formats `pack()` |
| constantes globales de la page (préfixe du jeu) | `JUMPHP_*` (`Platform::setting()`) | `JUMPHP_NET_URL`, `JUMPHP_ROOM`, `JUMPHP_LANG`, `JUMPHP_MOBILE`... ; ajoute les tiennes dans `engine/web/jumphp.js` (`prelude()`) si besoin |
| `index.html` du dépôt web, `package.sh`, `sync-game.sh` | `bin/jumphp build` | la page générique remplace la page spécifique ; les fonctions propres au site (comptes, replays, niveaux de l'éditeur) se branchent sur `JsBridge` et `window.JumPHP` |
| `phpw.c` → `sdl_frame($app)` | `phpw.c` → `jumphp_frame()` | rien à faire côté jeu |

## Pièges connus pendant le portage

- Le trait `Sprite` n'a plus de constructeur : appelle `initSprite()` dans le constructeur de l'entité.
- Les scènes sont réutilisées : tout l'état d'un niveau doit être réinitialisé dans `enter()`.
- `CollisionSystem::move()` déplace l'entité : ne pas intégrer la vitesse une seconde fois.
- Les anciennes constantes de taille (vue 840×426, zooms) : `project.yaml width/height` est la résolution
  virtuelle ; `Renderer::resizeView()` permet toujours de la changer en cours de jeu.
- Garder les entiers 32 bits en tête (`fmod(microtime(true), 86400.0)`, `-0x1000000`) : rien ne change
  par rapport à ce que tu faisais déjà.
