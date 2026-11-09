# CODEX — Combat Sprite Simulator : flotte 1940 / production Blender sur GTX

## Mission prioritaire

Implémente **trois véritables appareils 3D low-poly reconnaissables**, rendus en **sprites originaux via des scripts Blender exécutés sur le PC GTX**, puis intègre leurs atlas et comportements dans le jeu JumPHP existant :

- **Hawker Hurricane Mk I RAF** — joueur. Remplacer le modèle actuel composé de cubes par une silhouette fidèle et esthétiquement convaincante, lisible à la taille réelle du jeu.
- **Messerschmitt Bf 109 E-4** (ou autre sous-variante E documentée en 1940) — ennemi **rapide**, chasseur agile et fragile.
- **Junkers Ju 87 B-2 Stuka** — ennemi **lent**, bombardier en piqué, silhouette emblématique avec ailes en W inversé et train fixe caréné.

Conserver le **Junkers G 38** comme boss et préserver la démo en cours. Le Ju 87 n'est PAS un chasseur : c'est un bombardier en piqué. Aucun nouveau gros système de jeu, pas d'OCR généralisé, pas de campagne multi-époque pour ce lot.

**Contexte vérifié dans le dépôt :** `tools/blender/render_aircraft.py` utilise actuellement `cube()` pour le fuselage et les ailes du Hurricane. `tools/blender/assemble_atlas.py` assemble les frames ; `games/combat-sprite-simulator/src/Scene/PlayScene.php` dessine actuellement les ennemis courants avec des `fillRect()`. Remplacer réellement ces éléments, sans seulement ajouter des fichiers Blender inutilisés.

## Branches, environnement et bonnes pratiques

- Dépôt : `tueb2024/combat-sprite-simulator`.
- Branche préparée pour la mission : `feature/ww2-aircraft-blender`, créée à partir de `feature/reference-pipeline-docker`. **Ne pas fusionner.** Vérifier l'état du clone sur Aiphonse avant tout checkout, et préserver les modifications non commités éventuelles. Si Codex travaille déjà sur une autre branche, expliquer les changements à rapatrier sans les écraser.
- Projet local probable : `/srv/projects/combat-sprite-simulator`, utilisateur Linux `aiphonse`. Vérifier les chemins.
- **Blender 5.2.3 sur le PC GTX** : `tchou@192.168.1.163` via SSH existant ; utiliser la carte Nvidia GTX 1660 SUPER si Blender le permet, avec rendu raisonnable. Déterminer le chemin de `blender.exe` réellement présent, ne pas supposer le chemin.
- **JumPHP canonique** : `https://git.jumphp.net/jumphp/jumPhp.git`. Lire `AGENTS.md` avant tout changement ; **toutes** les commandes PHP/JumPHP passent par `bin/jumphp` et ses conteneurs Docker, non par `php`/Composer hôte.
- Ne modifier ni La Dernière Machine, ni Slave Rift, ni Tailscale, ni le réseau ou le groupe Docker.
- Le jeu existant est une base : **ne pas repartir de zéro**, ne pas remplacer JumPHP par un moteur navigateur autonome.

## 1. Documentation historique : les livres comme source

Les huit encyclopédies ont pour source le Lenovo, transféré(s) en principe vers un dossier privé sur Aiphonse :

`/home/aiphonse/.local/share/combat-sprite-simulator/reference-books`

et un index texte privé éventuellement sous :

`/home/aiphonse/.local/share/combat-sprite-simulator/reference-index`.

**Vérifier l'existence de ces dossiers sans supposer que transfert et indexation ont réussi.** Pour les trois appareils, ne lire QUE les pages utiles (Hawker Hurricane, Messerschmitt Bf 109, Junkers Ju 87), en privilégiant `The Complete Book of Fighters` (Green/Swanborough), Crosby, Sharpe/Chant, et les volumes Munson si pertinents.

- Recherche ciblée des fiches et dimensions, avec identification du **livre + page PDF** (différencier numéro de page physique du livre).
- Si des pages sont scannées, extraire/inspecter **quelques pages pertinentes** par rendu PDF ciblé ; ne pas demander à l'utilisateur de lancer des commandes pour chaque recherche. Un OCR ciblé peut être employé seulement si opérationnel et utile ; ne **pas bloquer ce lot** en cas d'échec de l'OCR.
- Croiser silhouettes, envergure, longueur, proportions et présence de moteurs, radiateurs, verrières, empennages, armements et marquages. En cas d'absence d'une source locale, compléter avec références publiques fiables en enregistrant la provenance.
- Stocker dans Git **uniquement des fiches factuelles originales avec sources**. AUCUN PDF, scan, profil illustré original ni long extrait dans GitHub, dans les assets publiés, dans la CI ou sur la GTX.
- **Important** : ne pas inventer des références de page. Marquer explicitement `not_verified` si on n'a pas pu contrôler la page.

Fiche structurée requise : `assets/aircraft/specs/{hurricane-mk1,bf109-e4,ju87-b2}.json`, avec `designation`, `variant`, `year`, `role`, `dimensions_m`, `shape`, `engine`, `livery`, `gameplay`, `sources` et `source_confidence`.

## 2. Génération Blender : géométrie réelle, pas des cubes

Créer ou refondre des scripts `bpy` **paramétriques et réutilisables**, par exemple :
- `tools/blender/airframe_geometry.py` : construction d'un fuselage par sections/anneaux et d'ailes trapézoïdales/elliptiques par sommets, épaisseur, dièdre, vrillage et saumons, verrières profilées, empennages, hélices, radiateurs, moteurs et train.
- `tools/blender/build_ww2.py` : lit les trois fichiers de specs et crée les appareils avec morphologies distinctes.
- `tools/blender/render_sprites.py` : éclairage, caméra **orthographique**, animation de roulis réel, états moteur, alpha RGBA, sorties reproductibles.
- `tools/blender/assemble_atlas.py` : généraliser l'assembleur existant (NE PAS l'effacer aveuglément) pour sortir tous les atlas et manifestes.

Silhouettes impératives :

**Hurricane Mk I (joueur)** :
- Monomoteur à hélice, ailes monoplans relativement épaisses à plan réaliste arrondi, ailes et empennage **profilés**, fuselage affiné vers l'arrière, verrière avec montants, hélice et casserole, prise d'air / radiateur si visible sous l'appareil.
- Livrée RAF début de guerre (Dark Earth / Dark Green, dessous pertinent selon variante), cocardes correctement placées, détails contrastés à petite taille.
- L'objectif est une **amélioration visuelle radicale** par rapport à l'actuel fuselage rectangulaire et aux ailes cubes.

**Bf 109 E-4 (ennemi rapide)** :
- Monomoteur en ligne, nez long et pointu, verrière étroite, plan d'ailes et empennage de la série E, nez et radiateurs adaptés, silhouette nerveuse. Éviter le look de Hurricane recolorié.
- Livrée Luftwaffe 1940 plausible et **Balkenkreuz**, sans symboles politiques extrémistes.
- Vue ennemie orientée vers le bas de l'écran ; ne pas simplement retourner un sprite joueur au prix de marquages inversés.

**Ju 87 B-2 Stuka (ennemi lent)** :
- Moteur en ligne / hélice, **ailes de mouette inversée**, grand train fixe caréné, verrière longue biplace, empennage et freins de piqué selon variante ; **pas de moteur radial** et pas de moteur supplémentaire.
- Silhouette plus large/longue que le Bf 109 en fonction des envergures réelles, mais adaptée à la lisibilité.
- Livrée Allemagne 1940 plausible, croix d'aile, petites bombes si utile.

Exigences communes :
- Meshs avec faces/volume corrects et ombres simples ; pas de simples boîtes étirées pour fuselage/ailes. Le low poly est permis, pas les placeholders.
- Même angle de caméra et direction de lumière pour les 3 appareils ; vus du dessus avec volume léger, compatibles avec terrain aérien IGN. L'avion doit ressortir sur photographie chargée.
- Au minimum **3 roulis** (gauche/centre/droite, rotation autour de l'axe **longitudinal de l'avion**, avec raccourcissement et ombre perceptibles — pas juste une rotation 2D de l'image) × **3 états d'hélice** (ralenti / moyen / rapide) = **9 frames** par avion.
- Pas de flammes de réacteur sur les appareils à hélice. Rendu PNG à transparence réelle, sans fonds colorés, bords non coupés, pivots cohérents. Option : frames de dégâts et ombres séparées si budget.
- Rendre d'abord **512 px ou plus** puis réduire proprement pour un usage dans le jeu virtualisé **320 × 480** : tester réellement la lisibilité à 48–80 px de large. Ajouter une planche de comparaison claire avec les trois avions à leur taille de gameplay.
- Vérifier proportions depuis les dimensions documentées, pas trois silhouettes générées au hasard. Éviter textures ou scans du livre collés sur les meshes.
- Sauvegarder `.blend` source par appareil, scripts `bpy`, atlas `png`, métadonnées `json` et journaux du rendu.

### Lieu réel d'exécution

**TOUTE génération 3D et TOUT rendu doivent être exécutés par Blender CLI sur GTX**, pas par Blender local dans un conteneur Aiphonse, ni par un outil d'image générative :
- Depuis Aiphonse, **transmettre seulement scripts .py + specs JSON** par SSH/SCP au répertoire de travail de la GTX (par ex. sous le profil de `tchou`, emplacement à vérifier).
- Commande équivalente, sur GTX : `blender.exe -b --python tools/blender/build_ww2.py -- --spec <path> --out <folder>`, en utilisant la syntaxe exacte prise en charge par les scripts développés.
- Rapatrier de GTX vers Aiphonse les .blend et atlas générés. Créer un pilote répétable `tools/blender/run_on_gtx.sh` utilisant la clé SSH **existante**, sans nouveau compte, sans serveur Web, sans copie des PDF, ni intervention manuelle par appareil.
- Documenter `./tools/blender/run_on_gtx.sh --aircraft all` ou équivalent, de sorte qu'une nouvelle fiche JSON suffise à régénérer un avion.
- Blender GPU si disponible ; autrement exécuter sur GTX en CPU Blender, signaler ce choix sans inventer de rendu GPU réussi.

## 3. Intégration PHP/JumPHP — pas seulement des PNG

Le code actuel `PlayScene.php` utilise des `fillRect()` pour les ennemis. **Les remplacer** par atlas de sprites réels, en conservant la logique jouable :

- Type d'ennemi dans les données des vagues : `bf109` et `ju87` (pas de bloc générique dessiné par `fillRect`).
- Bf 109 : **vitesse descendante ≈ 170–210 px/s** à 320 × 480, trajectoire rapide avec changement de couloir/zigzag lisible, tirs occasionnels, HP ≈ 2. Une salve ne doit pas être quasi-inévitable.
- Ju 87 : **≈ 70–100 px/s**, approche plus stable, plongée annoncée ou largage simple, HP ≈ 4 ; hitbox un peu plus grande que le Bf 109.
- Ajuster ces chiffres après tests : **ce sont des paramètres arcade initiaux**, pas des valeurs historiques.
- Poids d'apparition équilibrés dans la phase `wave`, sans supprimer les ennemis avant le boss ni casser le timer. Gameplay avec mix de mouvements, risque et cadence appropriés.
- Orientations cohérentes : ennemis descend vers le joueur, Hurricane monte vers le haut de l'écran. Changer le frame de roulis selon le mouvement ou virage des ennemis.
- Charger les atlas une fois, exploiter le manifeste JSON ou au minimum synchroniser les rects et dimensions. Les hitboxes doivent refléter la silhouette/échelle ; ne pas calculer les collisions sur toute la zone vide d'un PNG.
- Garder le G 38, ses phases, le score, les vies, la pause, le restart, les tirs et l'orthophoto IGN déjà en place.
- Ajouter des tests de spawn/identité Bf109-Ju87, collision, tir, changement de frames, et préserver les tests existants.

## 4. Vérifications, preuves et livraison

Utiliser **le Docker officiel JumPHP**, depuis le projet Aiphonse, dès que l'accès du contexte Codex le permet :
```bash
bin/jumphp install combat-sprite-simulator
bin/jumphp test combat-sprite-simulator
bin/jumphp shot combat-sprite-simulator 120
bin/jumphp build combat-sprite-simulator
bin/jumphp webshot combat-sprite-simulator
```
Le Docker du système `aiphonse` est **fonctionnel** (version serveur 29.9.2, socket `root:docker` mode 0660, utilisateur `aiphonse` membre de `docker`). Si l'environnement isolé de Codex refuse Docker, diagnostiquer/solliciter l'autorisation prévue dans Codex, sans changer les permissions du socket et sans demander à l'utilisateur de relancer des commandes pour chaque étape. La CI existante reste une validation complémentaire.

- Conserver les captures d'écran **jeu en cours avec Bf109 et Ju87**, **Hurricane visible**, et **boss**.
- Générer une **planche comparant les rendus Blender** (Hurricane / Bf 109 / Stuka), et inspecter réellement les silhouettes, l'alpha, les dimensions et la lisibilité à résolution de jeu.
- Effectuer au moins une passe visuelle en jeu après intégration : pas seulement le succès d'un test de code.
- Bilan concret : commandes GTX exécutées, fichiers et captures produits, chemins/commits, sources documentaires effectivement consultées (pages), tests locaux + CI, limitations. Ne pas annoncer Blender exécuté s'il ne l'a pas été.
- **Commit et push sur `feature/ww2-aircraft-blender` seulement. Pas de merge, pas de push forcé.** Pas d'interruption pour demander des choix esthétiques mineurs ; choisir une version historique cohérente et livrer une démo testable.
