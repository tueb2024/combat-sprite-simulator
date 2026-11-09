# Survol libre — IGN Géoplateforme / JumPHP

Cette branche ajoute un mode **Survol libre** sans remplacer la démo arcade et sans moteur cartographique JavaScript.

## Architecture réelle

- **Barre de recherche** : `games/combat-sprite-simulator/site/index.html`. Une interface HTML minimale (sans Leaflet / MapLibre) envoie `fly-to-address` à la page du jeu (iframe de même origine) via `window.JumPHP.send`.
- **Transition** : `TitleScene` reçoit l'événement du bridge JumPHP et ouvre `GeoFlightScene` avec l'adresse. Le menu donne aussi accès à « SURVOL LIBRE » sans adresse, sur une zone de démonstration dans la Beauce.
- **Géocodage** : `src/Geo/AddressLookup.php` interroge `https://data.geopf.fr/geocodage/search` **en PHP**, via `$this->engine->http()->get()` ; vérifie les coordonnées WGS84 et limite la première version à la France métropolitaine.
- **Projection** : `src/Geo/GeoProjection.php` transforme latitude/longitude en pixels WMTS `PM` (Web Mercator), avec zoom 19 et tuiles 256 × 256.
- **Fond IGN** : `src/Geo/OrthoTileManager.php` consulte `https://data.geopf.fr/wmts` pour `HR.ORTHOIMAGERY.ORTHOPHOTOS`, charge les images par `JumPHP\Http\Http`, écrit temporairement les JPEG dans `/tmp`, puis utilise `Sdl::loadTexture()`. Les fichiers temporaires sont immédiatement supprimés après chargement SDL ; cache **mémoire** plafonné à 30 textures, au plus quatre téléchargements simultanés. Tout passe par JumPHP en PHP natif/WASM.
- **Ombres** : `src/Geo/AircraftShadow.php` extrait le canal alpha de l'atlas Hurricane d'origine, crée une silhouette semi-transparente via GD et la charge comme texture SDL. Le décalage sol/sprite simule une faible altitude et une direction solaire, **sans relief 3D**.
- **Scène** : `src/Scene/GeoFlightScene.php`, mouvement du joueur, caméra attachée au sol, préchargement d'une bordure de tuiles, HUD et crédits.

La source actuelle de l'atlas Hurricane utilise neuf frames 256 × 256 : l'ombre est dérivée de la frame centrale `[256, 256, 256, 256]`. Si Codex change la grille pendant sa refonte Blender, **adapter ce rectangle** ou utiliser le manifeste.

## Vérifications

Depuis le projet Aiphonse (Docker fonctionnel, ne pas réinstaller PHP sur le serveur) :

```sh
bin/jumphp install combat-sprite-simulator
bin/jumphp test combat-sprite-simulator
bin/jumphp lint
bin/jumphp shot combat-sprite-simulator 120
bin/jumphp engine:download
bin/jumphp build combat-sprite-simulator
bin/jumphp webshot combat-sprite-simulator
```

Pour tester l'**interface d'adresse**, il faut construire et servir **le site JumPHP** en plus du seul `dist/web`, car `bin/jumphp serve` seul sert la page du jeu sans le formulaire extérieur :

```sh
bin/jumphp build:site combat-sprite-simulator
bin/jumphp siteshot combat-sprite-simulator
```

Le site déployé par JumPHP sert `site/index.html` à la racine et le jeu en `/play/`. Vérifier le formulaire dans cette disposition **de même origine**. Une intégration HTML uniquement côté site n'est pas visible sur le jeu brut servi par `bin/jumphp serve`.

Si l'indexation de l'adresse ne fonctionne pas dans le navigateur, vérifier la réponse HTTP et les en-têtes CORS de l'IGN, ainsi que la couche et les formats annoncés dans le `GetCapabilities`. Ne pas prétendre que les tuiles chargent si aucune capture Web ne le démontre. Le zoom et la disponibilité haute résolution varient selon la couche et la zone.

## Confidentialité, attribution, licences

- L'adresse saisie est transmise à l'API publique de géocodage de l'IGN. Le mode ne conserve aucune liste d'adresses sur un serveur tiers du jeu.
- Les photographies sont celles du **territoire contemporain**, pas des vues historiques de 1940.
- Crédit visible dans le HUD et sur le site : `© IGN — BD ORTHO®`. Vérifier les obligations de licence (et, pour toute copie mise en cache durablement, conditions de diffusion et attribution).
- Le cache des tuiles est en mémoire et ne se retrouve pas dans GitHub/CI.

## Sources

- WMTS / GetTile : https://cartes.gouv.fr/aide/fr/guides-utilisateur/utiliser-les-services-de-la-geoplateforme/diffusion/wmts/
- Géocodage : https://cartes.gouv.fr/aide/fr/guides-utilisateur/utiliser-les-services-de-la-geoplateforme/geocodage/
- Catalogue de mises à jour IGN (couche 20 cm) : https://cartes.gouv.fr/aide/fr/partenaires/ign/generalites-ign/actualites/2026-09-mises-a-jour/

## Intégration avec le travail Blender parallèle

Ne pas modifier `PlayScene.php` ou les scripts Blender sur cette branche. Quand `feature/ww2-aircraft-blender` aura livré ses vrais atlas, fusionner/rebaser **après revue** (pas de merge automatique), vérifier les chemins/fichiers et le format des neuf frames. Le mode de survol reprendra alors immédiatement le nouveau Hurricane et l'ombre dérivée de sa vraie silhouette.
