# Tiles et collisions

## Charger une carte

| Source | Appel | Pour |
|---|---|---|
| Tiled (`.tmj`) | `TiledJsonLoader::load($config->path('assets/maps/n1.tmj'))` | les vrais niveaux |
| Lignes de texte | `ArrayLoader::fromRows([...], legend: ['#' => 1], objects: ['P' => 'start'])` | prototypes, tests, niveaux générés |
| Octets bruts | `BinaryLoader::load($fichier, $l, $h, $tw, $th, $image, $colonnes, $solidite, $formes)` | niveaux extraits de vieux jeux |

### Tiled : conventions

- Carte orthogonale, non infinie, enregistrée en JSON (`.tmj`). Tilesets intégrés ou `.tsj`, **une image
  par tileset** (pas de « collection of images »). Données de calques en CSV ou base64 (zlib / gzip).
- **Calque de collision** : nommé `collision`, ou propriété booléenne `collision`, sinon le premier.
  Il peut être invisible (case « visible » décochée).
- **Forme d'une tuile** : propriété de tuile `collision` (chaîne) =
  `full` · `none` · `oneway` (traversable par dessous) · `slope:0,16` (hauteur en pixels au bord gauche,
  au bord droit) · `heights:0,1,2,...` (une hauteur par colonne) · `oneway-slope:...`. Ou dessiner des
  rectangles / polygones dans l'éditeur de collision de Tiled. Sans rien : pleine.
- **Calque au premier plan** (devant les entités) : propriété booléenne `foreground`.
- **Objets** : calques d'objets → `$map->objects` (type = « Class » / « Type » de Tiled). Points de départ :
  `$map->object('start')`. Instanciation : `EntityManager::register('coin', Coin::class)` puis
  `spawnFromMap($map)`.
- Propriétés de tuiles (`hazard`, `coin`...) : `$map->tileProperty($gid, 'hazard')`, et l'événement
  `touch` de `CollisionSystem`.
- Tuiles animées, retournées (flip), calques image avec parallaxe : gérés.

## Masques (`CollisionMask`)

Chaque gid du calque de collision a un masque : `full()`, `none()`, `oneWay()`, `heights([...])`,
`slope($w, $h, $de, $a)`, `fromRows(['#...', ...])` (forme quelconque). `->oneWay` : bloque seulement
une entité qui tombe dessus par au-dessus. `isSolid($x, $y)` (coordonnées locales), `topAt($x)`.

## Déplacement (`CollisionSystem::move($entite, $carte, $dt)`)

- Avance **pixel par pixel**, en X puis en Y ; pas de traversée possible à vitesse raisonnable.
- **Murs** : vitesse X annulée, collée au pixel près (`hitLeft` / `hitRight`).
- **Marches et pentes** : au sol, une marche jusqu'à `$maxStepUp` pixels (4) est gravie ; en descente la
  collision « colle » au sol jusqu'à `$snapDown` pixels (6).
- **Sol / plafond** : vitesse Y annulée, position entière.
- **Plateformes** (mobiles ou non) : `setPlatformProvider(fn () => [[x, y, width, height, vx, vy, oneWay], ...])`,
  l'entité posée dessus est transportée.
- **Bords** : gauche / droite de la carte = murs (`$solidEdges`), sortie par le bas = événement `fell`.
- **Contacts** : événement `touch` pour chaque tuile avec propriétés recouverte (calque de collision +
  `$touchLayers`), et la tuile sous les pieds (`_ground` dans les propriétés).

L'entité doit utiliser le trait `TileCollision` pour recevoir `onGround`, `onCeiling`, `onWallLeft`,
`onWallRight`, `groundGid`. Ne pas intégrer la vitesse deux fois : avec `move()`, ne pas appeler
`Entity::update()` (qui ajoute aussi `vx * dt`), voir `skeleton/src/Entity/Player.php`.

Pour des objets rapides, un pas fixe (`FixedStep(120)`). Entre entités : `Aabb::intersects`,
`Aabb::overlap` (vecteur de sortie), `Aabb::sweep` (instant d'impact).

## Dessin (`TileMapRenderer`)

`renderBackground()` (calques image puis calques de tuiles non `foreground`), les entités, puis
`renderForeground()`. Seules les tuiles visibles sont dessinées. Un tileset sans image (ArrayLoader sans
`tileset`) est dessiné en blocs de couleur (`$tiles->placeholder`). Le nom de texture d'un tileset est
`tileset:<chemin de l'image>` : on peut la fournir soi-même (`createTextureFromGd('tileset:...')`, voir le
squelette).
