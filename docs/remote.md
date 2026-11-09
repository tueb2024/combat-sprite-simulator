# Jeux « distants » : un moteur partagé, le jeu fichier par fichier

Une build normale (`bin/jumphp build`) met tout dans un seul dossier : le moteur WebAssembly et le jeu
emballé dans `php-web.data`. Un **site de jeux** (le playground JumPHP par exemple) en héberge des dizaines.
Il serait dommage de faire retélécharger 10 Mo de moteur pour chaque jeu, et de refaire un paquet à chaque
modification d'un fichier. D'où le mode distant :

- le **moteur partagé** (`bin/jumphp runtime:build`, ou `runtime:download`) est servi une seule fois, par
  exemple dans `/engine/0.2.0/`. Il contient le wasm, les scripts de la page et `jumphp-vendor.pack` (le code
  PHP du moteur avec ses dépendances Composer). Le navigateur le garde en cache pour tous les jeux du site ;
- le **jeu** est servi tel quel, fichier par fichier, avec un `manifest.json` qui donne leur liste et leur
  empreinte. Seuls les fichiers modifiés sont retéléchargés.

Le mode normal ne change pas : sans `remote` dans la page, `jumphp.js` fait exactement ce qu'il faisait.

## Essayer en local

```bash
bin/jumphp runtime:build                     # build/runtime (il faut build/engine : engine:download)
bin/jumphp build:remote pong                 # games/pong/dist/remote : engine/, files/, manifest.json, index.html
JUMPHP_WEB=remote bin/jumphp serve pong      # http://localhost:8080
JUMPHP_WEB=remote bin/jumphp webshot pong 8 '[[1,"Enter"]]'
```

## Le format

La page est `page.html` du moteur partagé (le même gabarit que `engine/web/index.html`), avec :

- `{{ENGINE}}` : l'adresse du dossier du moteur (`/engine/0.2.0/`) ;
- `{{GAME_JSON}}` : les infos du jeu (`id`, `title`, `width`, `height`, `touch`, `controls`, `lang`,
  `net_url`...) plus `"remote": {"manifest": "manifest.json"}` (adresse relative à la page) ;
- `{{V_JS}}`, `{{V_TOUCH}}`, `{{V_NET}}` : les empreintes de `version.json` du moteur ;
- `{{WASM_SIZE}}` : `runtime.json` → `wasm_size`.

Le moteur et la page doivent être sur la **même origine** (modules JavaScript, Web Workers).

`manifest.json` :

```json
{
  "format": 1,
  "engine": "0.2.0",
  "base": "files/",
  "autoload": { "App\\": ["src/"] },
  "files": [
    { "path": "config/project.yaml", "hash": "3fa2c9d1e0b4a7f2", "size": 412 },
    { "path": "src/Scene/PlayScene.php", "hash": "9b0c...", "size": 5120, "url": "/drafts/abc/src/Scene/PlayScene.php" }
  ]
}
```

- `files` : les fichiers du jeu, chemins relatifs au dossier du jeu (`games/<id>/`), sans `vendor/`, `var/`,
  `dist/`, `cache/`, `tests/`, `site/`. Chacun est téléchargé depuis `base + path + "?v=" + hash` (ou depuis
  `url` s'il est donné : un brouillon, un autre serveur), puis gardé en cache sous cette adresse ;
- `hash` : une empreinte du contenu (n'importe laquelle : elle sert d'adresse de cache). Sans `hash`, le fichier
  est retéléchargé à chaque fois ;
- `autoload` : les espaces de noms PSR-4 du jeu (`composer.json`). Les dépendances Composer du jeu autres que
  le moteur ne sont pas chargées : un jeu distant n'utilise que `jumphp/engine` ;
- le conteneur de services est construit au démarrage dans le navigateur (pas de `cache/container.php`) :
  aucun code du jeu ne tourne sur le serveur.

Dans le navigateur, le jeu est en `/game` comme d'habitude, le moteur en `/game/vendor` (`jumphp-vendor.pack`,
autoloader de Composer + les espaces de noms du manifeste), et les sauvegardes du joueur dans `/game/var`
(IndexedDB de l'origine du site). Les Web Workers de `JumPHP\Async` reçoivent les mêmes fichiers.

`jumphp-vendor.pack` : `JPK1`, la longueur d'un en-tête JSON (uint32 petit-boutiste), l'en-tête
`{"files": [[chemin, début, taille], ...]}` puis les octets (`tools/pack.php`).

## Publication

`bin/jumphp runtime:build` écrit aussi `.build/jumphp-runtime.tar.gz` (+ `.sha256`) : à joindre à la release
GitHub de la version, à côté de `jumphp-engine.tar.gz` ; un site le récupère avec
`bin/jumphp runtime:download <version>` ou directement depuis la release.
