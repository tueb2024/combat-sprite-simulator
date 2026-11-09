# Les pièges du navigateur (WebAssembly)

Le même PHP tourne dans le navigateur, avec ces différences :

| Sujet | Dans le navigateur | À faire |
|---|---|---|
| Entiers | 32 bits (`PHP_INT_SIZE = 4`, max 2 147 483 647) | temps en float (`Time::now()`, `$engine->time()`), couleurs en tableaux / chaînes, `pack()` sans `q J P` |
| Boucle | une frame par `requestAnimationFrame`, rien ne doit bloquer | jamais `sleep`, `usleep`, `while(true)`, `fgets(STDIN)` ; compter `$dt` ou `GameLoop::addTimer` |
| Réseau | pas de sockets, pas de curl | `JumPHP\Net` (multijoueur), `JumPHP\Http` (requêtes web, CORS) |
| Fichiers | `/game` = le jeu (lecture), `/game/var` = IndexedDB (sauvegardé toutes les 2 s), `/tmp` = mémoire | `Storage` / `Options` pour écrire |
| Extensions | SDL, SDL_image (png, jpg, bmp), SDL_mixer (wav, ogg, mp3), SDL_ttf, GD (png), mbstring, bcmath, ctype, zlib, json, pcre, tokenizer | pas d'intl, pdo, curl, sockets (TCP : `$engine->tcp()` par le pont TCP, SSH : `$engine->ssh()`, voir docs/tcp.md et docs/ssh-wasm.md), pcntl... composer : paquets en PHP pur |
| Textures | largeur ≤ 4064 px (WebGL) | `Sdl::loadTexture` découpe seul ; `getTextures()` rend les tranches |
| Blend modes | absents du binding SDL | `fillRect` avec alpha (texture 1×1 translucide), PNG avec alpha, images pré-teintées |
| Shaders, 3D | pas d'OpenGL dans le binding | `$engine->gl()` (WebGL, navigateur seulement : [api-web.md](api-web.md)) |
| Onglet masqué | plus de frames (`requestAnimationFrame` s'arrête) | `lifecycle()->on('hide')` est appelé tout de suite ; `pause_scene` dans project.yaml |
| Son | démarre après un geste de l'utilisateur | la page affiche « CLICK / TAP TO PLAY » une fois chargée |
| Quitter | une page ne se ferme pas | `Engine::quit()` → `?back=URL` ou « THANKS FOR PLAYING » |
| Performances | ~2-4× plus lent que le natif | peu d'objets créés par frame, textures en cache, `FixedStep` raisonnable |
| Démarrage | chargement du moteur (7,5 Mo, en cache ensuite) + du jeu | assets légers, textures chargées à la demande (`registerTexture` + groupes) |

`PHP_OS` vaut `Linux` aussi dans le navigateur : utiliser `Platform::isWasm()`.

## Déboguer

- `bin/jumphp webshot <jeu> 6 '[[1,"Enter"]]'` : charge la page dans un Chrome sans écran, affiche la console,
  échoue sur une erreur PHP / JS, capture `dist/webshot.png`.
- La console du navigateur reçoit `stdout` / `stderr` de PHP. Une exception pendant le jeu affiche un écran
  rouge « CRASH » avec le message, le fichier et la ligne.
- `?debug=1` dans l'URL : overlay FPS (F3 aussi).
- Les messages `munmap() failed` d'emscripten sont filtrés (sans conséquence).
