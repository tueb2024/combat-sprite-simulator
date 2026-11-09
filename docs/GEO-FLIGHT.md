# Survol libre — IGN Géoplateforme / JumPHP

Cette branche ajoute un mode **Survol libre** sans remplacer la démo arcade et sans moteur cartographique JavaScript.

## Architecture réelle

- **Barre de recherche native dans le jeu** : `src/Scene/AddressEntryScene.php`, accessible depuis le menu titre « SURVOL A L ADRESSE » ou via **F / un clic sur le HUD** pendant le vol. Le texte est saisi dans le canvas JumPHP (clavier physique UTF-8 / clavier de jeu manette-souris-tactile), sans HTML externe. `games/combat-sprite-simulator/site/index.html` reste une interface facultative de lancement.
- **Sprite Blender réel** : `GeoFlightScene` et `PlayScene` partagent `assets/sprites/hurricane-mk1-atlas.png` (grille 3 × 3, frames de 512 px) et l'ombre reprend la silhouette centrale via `AircraftShadow`.
- **Transition** : `TitleScene` reçoit l'événement du bridge JumPHP et ouvre `GeoFlightScene` avec l'adresse. Le menu donne aussi accès à « SURVOL LIBRE » sans adresse, sur une zone de démonstration dans la Beauce.
- **Géocodage** : `src/Geo/AddressLookup.php` interroge `https://data.geopf.fr/geocodage/search` **en PHP**, via `$this->engine->http()->get()` ; vérifie les coordonnées WGS84 et limite la première version à la France métropolitaine.
- **Projection** : `src/Geo/GeoProjection.php` transforme latitude/longitude en pixels WMTS `PM` (Web Mercator), avec zoom 19 et tuiles 256 × 256.
- **Fond IGN** : `src/Geo/OrthoTileManager.php` consulte `https://data.geopf.fr/wmts` pour `HR.ORTHOIMAGERY.ORTHOPHOTOS`, charge les images par `JumPHP\Http\Http`, écrit temporairement les JPEG dans `/tmp`, puis utilise `Sdl::loadTexture()`. Les fichiers temporaires sont immédiatement supprimés après chargement SDL ; cache **mémoire** plafonné à 30 textures, au plus quatre téléchargements simultanés. Tout passe par JumPHP en PHP natif/WASM.
- **Ombres** : `src/Geo/AircraftShadow.php` extrait le canal alpha de l'atlas Hurricane d'origine, crée une silhouette semi-transparente via GD et la charge comme texture SDL. Le décalage sol/sprite simule une faible altitude et une direction solaire, **sans relief 3D**.
- **Scène** : `src/Scene/GeoFlightScene.php`, mouvement du joueur, caméra attachée au sol, préchargement d'une bordure de tuiles, HUD et crédits.

L'atlas Blender utilisé actuellement contient neuf frames de 512 × 512 : l'ombre est dérivée de la frame centrale `[512, 512, 512, 512]`. Toute nouvelle version de l'atlas doit conserver cette convention ou adapter le code au manifeste.

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

Pour tester la **barre d'adresse intégrée au jeu**, `bin/jumphp build` et `bin/jumphp serve` suffisent : sélectionner **SURVOL A L ADRESSE** depuis le menu. Sur la carte, utiliser la touche **F** ou le bandeau supérieur. Le site avec formulaire extérieur est facultatif :

```sh
bin/jumphp build:site combat-sprite-simulator
bin/jumphp siteshot combat-sprite-simulator
```

Le site déployé par JumPHP sert `site/index.html` à la racine et le jeu en `/play/`. Le canvas dans `/play/` comporte maintenant son **propre écran de saisie**, sans dépendance au wrapper HTML. Les adresses saisies restent transmises à l'API IGN publique.

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

Ne pas modifier `PlayScene.php` ou les scripts Blender sur cette branche. Le travail Blender de `feature/ww2-aircraft-blender` est intégré à `main` : le jeu de combat et le survol utilisent le même Hurricane généré dans Blender. Ne pas substituer l'ancien atlas de 256 px.
