# Combat Sprite Simulator — Missions à l'adresse

## Utilisation

Depuis le **menu du jeu JumPHP** (y compris le playground `/play/`) :

- **START MISSION** — mission de démonstration avec la photographie locale précédemment embarquée ; pas de réseau nécessaire.
- **MISSION A L ADRESSE** — saisie de rue, commune ou code postal français, puis un **vrai niveau de combat** au-dessus de l'adresse : vagues de Bf 109, Stuka et Junkers G 38.
- **SURVOL A L ADRESSE** — mêmes orthophotos IGN sans ennemis.
- **SURVOL LIBRE** — zone de démonstration.

La page `site/index.html` propose aussi une entrée d'adresse, avec **« Combattre ici »** (défaut) et **« Survol libre »**, envoyées au moteur PHP via `JsBridge`.

Dans une mission géocodée, touche **F** pour choisir une nouvelle adresse. Quand on recommence une mission après victoire/défaite, le point géographique initial est conservé.

## Principe

1. `AddressEntryScene` transmet `['address' => ...]` à `PlayScene`.
2. `AddressLookup` géocode via `JumPHP\Http\Http` (asynchrone), pour la France métropolitaine.
3. `MissionTerrain` fixe l'origine monde : position du Hurricane au-dessus de l'adresse choisie, niveau 19 WMTS PM (environ 20 cm/pixel à latitude moyenne).
4. `OrthoTileManager` charge les tuiles `HR.ORTHOIMAGERY.ORTHOPHOTOS` directement dans le rendu SDL de JumPHP. La caméra se déplace vers le nord au fil du niveau ; **le boss ne réinitialise pas la carte**.
5. `PlayScene` conserve les vagues, mouvements, tirs, hitboxes, HP, score et le boss existants. Le niveau défile à environ 250 pixels/s sur la carte (environ 50 m/s), sans utiliser une carte JS.
6. `AircraftShadow` crée dans le moteur SDL des silhouettes sombres et transparentes à partir des atlas PNG Blender du joueur, des ennemis et du boss. Décalage au sol lié à l'altitude simulée et à l'échelle géographique.

Le HUD identifie la zone. Le chronomètre de combat ne démarre pas avant géocodage et réception des premières tuiles. Après un échec de géocodage, l'utilisateur peut réessayer. Si le WMTS n'est pas chargé au bout de 12 secondes, le jeu continue avec le fond de démonstration **en signalant explicitement l'indisponibilité IGN** ; il ne prétend pas afficher la bonne adresse.

**Limites :** le terrain est une orthophoto actuelle, pas une carte historique de 1940. Le soleil et les altitudes des avions sont simulés (ombres au sol plat sans prise en compte du bâti ou du relief). Le réseau, le CORS navigateur et la disponibilité des clichés HD doivent être testés en conditions réelles sur le playground ; la CI ne certifie pas la réponse effective du serveur IGN à une adresse saisie.

## Vérification Docker JumPHP

```bash
bin/jumphp install combat-sprite-simulator
bin/jumphp test combat-sprite-simulator
bin/jumphp lint
bin/jumphp shot combat-sprite-simulator 120
bin/jumphp engine:download
bin/jumphp build combat-sprite-simulator
bin/jumphp build:site combat-sprite-simulator
bin/jumphp webshot combat-sprite-simulator
```

La CI GitHub exécute en plus des tests de géoréférencement et de continuité des coordonnées du boss. Elle ne se substitue pas à un test interactif de chargement des photos IGN.

Sources officielles :
- https://cartes.gouv.fr/aide/fr/guides-utilisateur/utiliser-les-services-de-la-geoplateforme/geocodage/
- https://cartes.gouv.fr/aide/fr/guides-utilisateur/utiliser-les-services-de-la-geoplateforme/diffusion/wmts/
