# COMBAT SPRITE SIMULATOR — lancement Codex / JumPHP / Blender

**Mission autonome de développement.** Démarre un **nouveau projet indépendant** de La Dernière Machine et Slave Rift. Ce document est hébergé temporairement dans la branche `bootstrap/combat-sprite-simulator` du dépôt La Dernière Machine uniquement pour permettre son transfert à Codex : **aucun code du nouveau jeu ne doit être écrit dans ce dépôt**. Le dépôt cible sera `tueb2024/combat-sprite-simulator`, à créer avec `gh` si inexistant.

## Résultat prioritaire : une démo qui fonctionne, pas une documentation

Réalise d'abord une **démo jouable de 1 à 3 minutes**, au rythme arcade d'un shoot'em up vertical classique. L'utilisateur souhaite pouvoir tester rapidement une première version ; **utilise une enveloppe de travail initiale de l'ordre de 20 à 30 minutes comme objectif de priorisation, jamais comme garantie**. S'il faut choisir, privilégie : (1) jeu jouable, (2) tests, (3) premiers assets Blender, (4) photographie IGN, (5) enrichissements ultérieurs.

L'architecture doit utiliser **le vrai moteur PHP JumPHP**, pas une réécriture JavaScript/canvas indépendante. Lis l'AGENTS.md complet à la racine du dépôt upstream avant de coder :
- https://git.jumphp.net/jumphp/jumPhp
- Dépôt Git canonique présumé : https://git.jumphp.net/jumphp/jumPhp.git (vérifier par git ls-remote avant clonage)
- Lire en priorité le fichier AGENTS.md à la racine du dépôt réellement cloné.
- Documentation : https://jumphp.net/fr/docs
- L'ancien miroir GitHub davidnurdin/jumPhp ne doit pas être considéré comme la source de référence sans vérification de sa synchronisation.

Ne présume pas que les anciennes commandes sont toujours identiques : commence par lire `AGENTS.md` et `bin/jumphp help` du dépôt canonique, puis adapte le workflow. Conformément à ce guide, passe par `bin/jumphp` (environnement Docker), pas par `php`, `composer` ou un moteur JS bricolé ; utilise `games/<id>/src/Scene`, `src/Entity`, `config/` et `assets/` et des tests headless.

## Infrastructure connue

- **Aiphonse** : Debian 12, Codex CLI déjà connecté, accès GitHub avec `gh`, Docker/JumPHP présents, partition de stockage `/srv/projects` nouvellement montée (vérifier `findmnt /srv/projects`).
- **PC GTX** : Windows 11 Pro ; accès SSH depuis Aiphonse **désormais sans mot de passe** à `tchou@192.168.1.163` ; Blender 5.2.3 installé (vérifier chemin réel de `blender.exe`, éventuellement sous `G:\`), Nvidia GTX 1660 SUPER 6 Go.
- **Lenovo** : client de navigation/test, pas la machine de build.
- Le répertoire `/home/aiphonse/src/jumPhp` contient une copie historique avec changements locaux d'autres jeux : **ne pas l'écraser ni développer dedans**. Le projet La Dernière Machine dans `/home/aiphonse/src/la-derniere-machine` doit rester intact.
- Les modèles Ollama et OpenCode existants sur le PC GTX ne sont **pas requis** pour cette mission : utilise Codex sur Aiphonse pour coder et le PC GTX **uniquement comme machine Blender/rendu**. Évite les conflits VRAM avec Ollama lors des rendus lourds.

### Première action : repo, sources et runtime

1. Vérifie les dépôts existants et le montage `/srv/projects`. Crée `/srv/projects/combat-sprite-simulator` comme nouvel espace de travail.
2. Initialise le projet à partir de JumPHP officiel (clone/installe dans ce dossier sans affecter les autres clones), puis crée le jeu `combat-sprite-simulator` par `bin/jumphp new combat-sprite-simulator "Combat Sprite Simulator"`, selon la version réelle du CLI.
3. Lis attentivement `AGENTS.md` et `games/pong` ou `games/breakout`. Utilise le runtime Docker déjà en cache si compatible. Essaie `bin/jumphp engine:download` pour le navigateur plutôt que recompiler toute l'infrastructure dès le premier essai ; adapte aux outils réellement présents.
4. Crée **un nouveau dépôt privé** `tueb2024/combat-sprite-simulator` avec `gh` (si les droits et l'authentification le permettent), configure correctement les remotes et commits. Travaille sur `feature/first-playable`, sans modifier le `main` des autres projets. Ne force-push jamais.
5. Mets dans le nouveau dépôt `docs/BRIEF.md` une copie de cette mission, un `README.md` avec les commandes de test et des instructions minimales de lancement.

## Sources documentaires sur le PC GTX

L'utilisateur possède **huit livres PDF** dans `C:\Users\tchou\Downloads\`. Localise-les par titres/nom partiel, car les noms complets contiennent des espaces, apostrophes, underscores et métadonnées longues. N'assume jamais qu'un fichier est présent sans test. Catalogue initial :

1. `The complete guide to fighters & bombers of the world` — Francis Crosby.
2. `Pocket Encyclopaedia of World Aircraft_ Balloons and` — Lennart A. T. Ege.
3. `Helicopters and other rotorcraft since 1907` — Kenneth Munson.
4. `The Pocket Encyclopaedia of World Aircraft in Colour` — Kenneth Munson.
5. `Encyclopedia of Airliners Between the Wars 1919 39` — Kenneth Munson.
6. `Airliners Since 1946` — Kenneth Munson.
7. `The Complete Book of Fighters` — William Green et Gordon Swanborough, édition 2001.
8. `Fighting aircraft of the world` — Mike Sharpe et Christopher Chant.

Établis simplement un `docs/reference-catalog.json` local **sans distribuer les PDF**, avec fichiers trouvés, références utiles, envergure, longueur, motorisation, époque et provenance bibliographique. Pour la démo, ne consulte que les entrées pertinentes (Hurricane et grand Junkers) : pas de traitement intégral des huit ouvrages dans le jalon initial.

Ces livres sont des références documentaires privées. **Ne copie jamais les PDF, leurs illustrations, planches ou scans dans GitHub ni dans les assets distribués**. Reconstruis des sprites originaux à partir de connaissances factuelles, de plusieurs références et de modèles Blender propres. Tu peux extraire des informations factuelles ; ne republie pas des planches dessinées.

## Première démo : 1940, Hurricane contre grand avion Junkers

**Prototype de gameplay vertical** (pas encore campagne 1914–F-35).

- Joueur : **Hawker Hurricane RAF** en vue supérieure, livrée historique lisible ; déplacement gauche/droite/haut/bas avec flèches ou WASD, tir avec Espace.
- Un petit groupe d'ennemis simples (par exemple bombardiers légers génériques d'époque) avec trajectoires lisibles.
- Boss : un grand **Junkers G 38**, si la documentation confirme le choix. Le terme « Junkers G90 » utilisé auparavant n'est pas établi : ne pas fabriquer une fausse fiche historique. Le G 38 est un transport quadrimoteur de 1929 ; si armé et présenté comme ennemi de 1940, préciser que c'est **une adaptation arcade fictive**, jamais une configuration historique.
- Victoire après destruction / défaite à zéro vie ; HUD vies, score, santé du boss ; effet de hit, fumée/explosion simple ; bouton ou touche de redémarrage.
- Défilement vertical fluide, fond aérien. **Objectif visuel : vraie orthophotographie française IGN** récupérée depuis un jeu de données à licence ouverte autorisant la réutilisation avec attribution. Utiliser un petit échantillon et générer des bandes défilantes ou tuiles ; consigner source, année, zone et crédit `© IGN` dans `CREDITS.md`. Si l'acquisition est bloquée, utiliser TEMPORAIREMENT une texture procédurale clairement indiquée comme telle, sans prétendre qu'il s'agit d'une photographie IGN.
- Contrôles de collisions AABB ou hitboxes raisonnables ; pas de réseau, boutique, progression 100 avions, physics engine ou menu sophistiqué au départ.
- Rester en PHP/JumPHP pour la simulation et le rendu. Un peu d'HTML de page hôte fourni par JumPHP est acceptable ; **pas de remplacement du jeu par une application JS autonome**.
- Destruction du boss visuelle et claire, difficulté suffisante mais testable en 1–3 minutes. Gameplay d'abord.

## Production d'assets — PC GTX Blender CLI

Depuis Codex sur Aiphonse, vérifie la clé SSH avec `ssh -o BatchMode=yes tchou@192.168.1.163 hostname`. Ne demande aucun mot de passe et ne génère aucune nouvelle clé.

Utilise des scripts `bpy` lancés avec `blender.exe -b --python <script>` sur le PC GTX. Vérifie le chemin de Blender ; les chemins sous `G:\LaDerniereMachine` appartiennent à un **autre projet** et ne doivent pas être touchés.

Produis au minimum :
- Hurricane RAF 2D en vue dessus, **3 attitudes** (roulis gauche, neutre, droite) et **3 états d'hélice** (ralenti, moyen, rapide) => atlas régulier ; pas de flammes de postcombustion sur un avion à hélice.
- Grand avion Junkers G 38 (quatre moteurs, pas six), silhouette fidèle et 1 à 2 états de dommages ; les détails peuvent rester stylisés.
- Projectiles, impacts, ombres simples, explosion (formes ou textures originales).
- Sorties PNG à fond alpha, atlas et manifeste JSON indiquant positions, tailles, offsets, pivots et hitboxes. Prévoir une taille raisonnable à l'écran et une caméra orthographique stable.
- Sauvegarder les scripts dans `tools/blender/`, et les fichiers sources .blend utiles dans `assets/source/` si leur taille/quotas Git le permettent. Rendus temporaires et grosses sorties dans `/srv/projects/combat-sprite-simulator/artifacts/`, non nécessairement commités.

**Ne crée pas une modélisation photoréaliste complète des avions avant le premier prototype.** Si Blender distant est bloqué, démarre le jeu avec des silhouettes temporaires originales dessinées par code/PNG, puis reprends la passerelle ; n'invente pas d'exécution Blender réussie.

## Vérifications et publication de la démo

Exécute et consigne au minimum (adapte à la version réelle de JumPHP) :
- `bin/jumphp install combat-sprite-simulator`
- `bin/jumphp test combat-sprite-simulator`
- `bin/jumphp shot combat-sprite-simulator 120` (examiner la capture, pas seulement son existence)
- `bin/jumphp build combat-sprite-simulator`
- `bin/jumphp webshot combat-sprite-simulator` si l'outillage navigateur est disponible.

La démo doit être lançable localement sur Aiphonse (par exemple `bin/jumphp serve combat-sprite-simulator 8181`, seulement si ce port est libre), accessible depuis Lenovo via tunnel SSH / Tailscale en toute sécurité, **sans exposer un service publiquement** ni toucher aux serveurs WordPress/Jellyfin.

À chaque jalon : commits sur `feature/first-playable`, résultats exacts des tests, captures, lien du dépôt nouvellement créé, mode d'accès à la démo, liste des limites. N'affirme pas qu'une démo Web est jouable si seul le test PHP passe. En cas de blocage, indique exactement ce qui reste et poursuis les tâches indépendantes.

## Ordre impératif de priorité

1. **Jeu PHP/JumPHP minimal avec rendu, contrôles, tirs et collisions.**
2. **Test automatisé + capture.**
3. **Un Hurricane + un boss Blender exportés en sprites.**
4. **Fonds IGN sous licence adaptée, attribution et défilement.**
5. **Build navigateur, tests en navigateur et démonstration accessible.**

Ne me demande pas d'arbitrages non essentiels. Produis le maximum vérifié dans le jalon initial, puis présente le résultat réel. Si ce jalon ne suffit pas, continue par petites étapes sans promettre un délai ni annoncer du travail futur non exécuté.
