# Build, Docker, déploiement

Seul Docker est nécessaire. Images utilisées :

| Image | Rôle | Construite par |
|---|---|---|
| `jumphp/desktop:dev` | PHP 8.5 CLI + extensions SDL, OpenGL et OpenAL (mêmes forks, commits et patch que le moteur wasm) + GD, composer, Xvfb (OpenGL sans écran : `jumphp-xvfb`) | `bin/jumphp setup` (`docker/desktop/Dockerfile`) |
| `jumphp/desktop-async:dev` | PHP 8.6 ZTS (fork TrueAsync) + `ext/async` + libuv + les mêmes extensions SDL, OpenGL et OpenAL (avec `php86-compat.h`), pour les jeux `runtime: async` ([async.md](async.md)) | `bin/jumphp setup async` ou au premier usage (`docker/desktop-async/Dockerfile`, ~15 min) |
| moteur wasm (sortie fichiers, pas d'image) | `build/engine/php-web.{mjs,wasm}`, `php-wasm-network.js`, `engine.json` | `bin/jumphp engine:build` (`docker/engine-wasm`, buildx bake, cache `.cache/buildx-engine`) |
| `emscripten/emsdk:3.1.35` | `file_packager` (données du jeu) | téléchargée |
| `jumphp/relay:dev` | serveur relais php-wasm-network | `bin/jumphp relay up` (`docker/relay`) |
| `dunglas/frankenphp:1.12.7-alpine` | serveur web local (FrankenPHP : Caddy + PHP, prêt pour le portail) | téléchargée |
| `ghcr.io/puppeteer/puppeteer` | Chrome sans écran pour `bin/jumphp webshot` | téléchargée |

## Le moteur WebAssembly (`docker/engine-wasm`)

Repris de php-wasm-sdl : `emscripten/emsdk:3.1.35`, php-8.5.12 (SAPI embed, statique), extensions
`davidnurdin/php-sdl`, `kea/php-sdl-image`, `kea/php-sdl-mixer`, `kea/php-sdl-ttf` (leurs `config.m4`
remplacés pour lier les ports emscripten `-sUSE_SDL*=2`), `wasmnet` de php-wasm-network,
[`Ponup/php-opengl`](https://github.com/Ponup/php-opengl) (OpenGL → WebGL 1, `-lGL`) et
[`Ponup/php-openal`](https://github.com/Ponup/php-openal) (OpenAL → Web Audio, `-lopenal`) ; GD (PNG),
mbstring, bcmath, ctype, zlib, tokenizer. Tous les dépôts sont épinglés par commit (`ARG *_REF`, les
mêmes dans `docker/desktop/Dockerfile`, `docker/desktop-async/Dockerfile` et `docker/relay/Dockerfile`).

`docker/ext/` est partagé par ces builds (contexte nommé `ext` : `--build-context ext=docker/ext`, et
`contexts` dans `docker-bake.hcl`) :
- `php-opengl.patch` : ce que le moteur ajoute à PHP-OpenGL. Les framebuffers ; `glGetIntegerv` / `glGetFloatv`
  (sauvegarde de l'état de SDL) ; `glGetActiveUniform` ; les uniforms 2f, 4f, 2i à 4i, 1fv à 4fv et Matrix3fv ;
  `glBufferSubData`, `glReadPixels` et `glCopyTexSubImage2D`. Les tampons et les pixels peuvent être des chaînes
  binaires (`pack('g*', ...)`) ou `null`. Les erreurs de shader deviennent des warnings (elles étaient fatales),
  et les fonctions `glDelete*`, vides dans l'original, sont écrites. Enfin, sous emscripten, les fonctions absentes
  de WebGL (`glPolygonMode`, `glDrawBuffer`, `glPointSize`…) deviennent des warnings. Le régénérer : appliquer le
  patch sur le commit épinglé, modifier, `git diff > docker/ext/php-opengl.patch`.
- `jumphp-xvfb` : lance une commande avec un écran X virtuel (Xvfb, OpenGL Mesa llvmpipe), pour les tests et
  les captures des jeux 3D (`bin/jumphp test` / `shot`). `xvfb-run` se bloque quand le conteneur tourne avec un
  utilisateur ordinaire.
- `engine/stubs/opengl.stub.php` et `openal.stub.php` sont générés depuis les extensions : `bin/jumphp php . tools/gl-stubs.php`.

Points importants :
- `CFLAGS="-O2 ..."` au configure : sans lui PHP compile en `-O0` (7 à 12 fois plus lent).
- `PKG_CONFIG_PATH` passé comme variable de configure : `emconfigure` remplace celui de l'environnement.
- édition de liens `-O3`, LTO, `MODULARIZE`/`EXPORT_ES6`, `-lidbfs.js`, `--js-library library_wasmnet.js`,
  pas d'ASYNCIFY ni de threads (donc pas d'en-têtes COOP/COEP).
- `source/phpw.c` : `phpw_run(code)` évalue le code puis donne `jumphp_frame()` à `emscripten_set_main_loop`.
- `php-wasm-network.js` (côté navigateur du réseau) est livré avec le moteur : toujours du même commit
  que l'extension.

Changer de version : modifier les `ARG` (moteur, desktop, relais) et `engine/composer.json`
(`davidnurdin/php-wasm-network`), puis `bin/jumphp engine:build` et `bin/jumphp setup`.

## Build navigateur d'un jeu (`bin/jumphp build <jeu>`)

1. `tools/stage-web.sh` (image desktop) : copie propre du jeu dans `.build/<jeu>` (sans `vendor`, `var`,
   `dist`, `tests`), `composer install --no-dev` avec `COMPOSER_MIRROR_PATH_REPOS=1` (le moteur est copié,
   pas lié), container précompilé (`tools/dump-container.php`), page (`tools/render-web.php` :
   `index.html`, `game.json`, scripts).
2. `tools/package-web.sh` (image emsdk) : `file_packager` (`.build/<jeu>` → `/game` dans le navigateur,
   LZ4, cache IndexedDB), injection du chargeur dans `php-web.mjs` juste après `run();` (le commentaire
   `--pre-js` disparaît en `-O3`), `version.json` (empreintes, pour invalider les caches).

Résultat `games/<jeu>/dist/web/` : `index.html`, `jumphp.js`, `touch.js`, `php-wasm-network.js`,
`php-web.mjs`, `php-web.wasm` (~7,5 Mo, commun à tous les jeux), `php-web.data` (le jeu), `game.json`,
`version.json`. N'importe quel hébergement statique convient (aucun en-tête particulier) ; `index.html`
et `version.json` ne doivent pas être mis en cache longtemps.

## Bureau

`bin/jumphp run <jeu>` lance `php app.php` dans l'image desktop avec : votre utilisateur, le dépôt monté sur
`/workspace`, X11 (`DISPLAY`, `/tmp/.X11-unix`, `XAUTHORITY`) ou Wayland (`WAYLAND_DISPLAY`,
`JUMPHP_X11=1` pour forcer X11), le son par la socket PulseAudio / PipeWire (`$XDG_RUNTIME_DIR/pulse/native`),
`/dev/dri` (GPU) et `--network host` (relais local). Sans GPU, SDL passe en rendu logiciel.
`bin/jumphp doctor` vérifie tout ça. `bin/jumphp export:desktop <jeu>` produit une image autonome
`jumphp/game-<jeu>`.

## Déploiement

`bin/jumphp deploy <jeu>` : `rsync` de `dist/web/` vers `$JUMPHP_DEPLOY_TARGET/<jeu>/` (par exemple
`user@serveur:/var/www/jeux`, avec votre agent SSH). Le relais en production : le dossier
`server/` de php-wasm-network (Caddy en TLS + relais + coturn) ; les jeux utilisent alors
`net_url: wss://votre-domaine/ws` dans `project.yaml` ou `?net=` dans l'URL.

`bin/jumphp pages [jeux...]` : construit les jeux (tous par défaut), génère une page d'accueil à partir de
leurs `game.json` (`tools/pages-index.php`) et pousse le tout comme unique commit de la branche `gh-pages`
du remote git (`JUMPHP_PAGES_REMOTE`, `origin` par défaut ; `JUMPHP_PAGES_BRANCH`, `gh-pages` par défaut).
GitHub Pages sert alors `https://<compte>.github.io/<dépôt>/`. La branche est remplacée à chaque
publication : c'est une branche de déploiement, sans historique à garder. Les Web Workers de `JumPHP\Async`
n'ont besoin d'aucun en-tête particulier, donc GitHub Pages convient tel quel.
