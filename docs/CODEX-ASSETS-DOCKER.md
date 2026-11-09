# Combat Sprite Simulator — suite Codex : livres privés, Blender GTX et Docker

## Cadre

Branche dédiée : `feature/reference-pipeline-docker`. La démo existante doit continuer à fonctionner. Ne modifier ni la branche `feature/first-playable`, ni les autres projets. Le moteur de jeu est **JumPHP** et doit rester celui du dépôt canonique `https://git.jumphp.net/jumphp/jumPhp.git`, avec son `AGENTS.md` comme contrat de développement.

### Résultat attendu de cette passe

1. Importer les **huit PDF depuis le Lenovo**, en conservant leur confidentialité.
2. Les rendre interrogeables par Codex **sur Aiphonse**, avec une trace livre/page/source.
3. Faire de **Docker la voie normale de build/test/rendu JumPHP**, sans contournement du moteur.
4. Industrialiser un prototype de génération Blender paramétrique pour Hurricane, Junkers G 38 et une troisième silhouette d'une famille différente, par exemple un biplan ; produire atlas alpha et manifeste validés dans la CI.
5. Préserver l'accès jeu existant : pas de changement de Tailscale/SSH/pare-feu ni de service permanent.

## A. Transfert Lenovo → Aiphonse

Les huit PDF sont sous **`C:\Users\tchou\Downloads` sur le Lenovo** ; une recherche précédente sur le **PC GTX** avait logiquement échoué. Ne pas prétendre qu'ils sont absents du Lenovo.

Exécuter le transfert en priorité depuis **Windows PowerShell sur le Lenovo**, avec le script versionné `tools/references/push-from-lenovo.ps1`. Le Lenovo initie une connexion SSH/SCP vers `aiphonse@192.168.1.18` ; **aucun serveur SSH à activer sur le Lenovo**. Les fichiers sont trouvés par motif de titre, puis copiés sous un nom ASCII stable (les noms originaux ont des espaces, apostrophes et Unicode). Un manifeste localement calculé SHA-256 fait correspondre les huit livres à leurs originaux.

Préparer une copie à jour du dépôt sur Aiphonse : vérifier `git status --short`, sauvegarder toute modification légitime, puis récupérer cette branche sans perdre de travail. Si le dépôt est sous `/srv/projects/combat-sprite-simulator`, l'utilisateur pourra lancer sur son Lenovo :

```powershell
ssh aiphonse@192.168.1.18 "cat /srv/projects/combat-sprite-simulator/tools/references/push-from-lenovo.ps1" | Set-Content -LiteralPath "$env:TEMP\combat-push-books.ps1" -Encoding UTF8
& "$env:TEMP\combat-push-books.ps1"
```

**Ne pas lancer cette commande sur le PC GTX.** Si le fichier script a été cloné ailleurs, corriger le chemin distant après l'avoir identifié. Si le transfert échoue, donner l'erreur réelle et une commande SCP fonctionnelle ; **ne pas modifier les paramètres réseau, ni ouvrir un port sur le Lenovo**.

Destination privée, **hors du dépôt Git** :

`/home/aiphonse/.local/share/combat-sprite-simulator/reference-books`

Permissions répertoire 0700 et PDF 0600. Conserver les originaux exclusivement sur Lenovo et dans ce stockage privé sur Aiphonse. Aucun PDF dans `assets/`, `games/`, `artifacts/`, GitHub Actions ou les images Docker. Ne pas exposer ce répertoire dans le serveur de jeu.

## B. Index privé Docker des livres

Le répertoire texte dérivé est lui aussi privé :

`/home/aiphonse/.local/share/combat-sprite-simulator/reference-index`

Après copie des livres :

```sh
mkdir -p "$HOME/.local/share/combat-sprite-simulator/reference-index"
chmod 700 "$HOME/.local/share/combat-sprite-simulator/reference-index"
docker compose -f compose.references.yaml build reference-index
CSS_UID=$(id -u) CSS_GID=$(id -g) docker compose -f compose.references.yaml run --rm reference-index
CSS_UID=$(id -u) CSS_GID=$(id -g) docker compose -f compose.references.yaml run --rm --entrypoint python reference-index /work/search_index.py "Hurricane"
```

Ce conteneur n'a **pas de réseau** et reçoit les livres en **montage lecture seule**. Il extrait le texte présent avec `pdftotext`, conserve les délimiteurs de pages et crée `catalog.local.json` avec SHA-256, nombre de pages, état d'extraction. Il n'effectue **aucun OCR massif**. Si certains livres sont des scans image sans texte, les marquer comme tels et consulter de façon ciblée leurs pages en mode visuel quand nécessaire ; pas d'hallucination de dimensions. Contrôler que les SHA-256 correspondent au manifeste Lenovo.

Pour chaque avion utilisé, Codex doit établir une **fiche de travail** (`aircraft-spec.json`) avec :
- ID, variante exacte (année / sous-type), rôle `player|enemy|boss`, dates, motorisation ;
- envergure, longueur, forme des ailes et empennages, implantation des moteurs, hélice / réacteur ;
- livrée, sources **livre + page du PDF** (numéro de page fichier ≠ pagination imprimée, les distinguer) ;
- ce qui est attesté / déduit / inventé pour le jeu ;
- profils de roulis, propulsion, dommages, échelle à l'écran, hitboxes et type de projectiles.

Les faits extraits et leurs références de page peuvent alimenter un catalogue de données originales sous Git ; **ne jamais ajouter de reproduction des profils, d'images des pages, ni de longs extraits de texte** au dépôt ou dans les prompts envoyés à des services externes. Vérifier les caractéristiques avec d'autres références lorsque possible.

## C. Pipeline Blender sur PC GTX

L'agent Codex tourne **sur Aiphonse** et consulte le corpus local. Il construit une fiche avion JSON et un script `bpy` versionnés dans le dépôt. Il peut lancer Blender **sur le PC GTX** via le SSH déjà fonctionnel `tchou@192.168.1.163`. Ne pas installer d'agent IA, de serveur Web ou de copies du corpus PDF sur la GTX. Ne pas toucher à `G:\LaDerniereMachine`.

Pipeline reproductible :
`fiche avion + configuration de famille` → `bpy création 3D low-poly originale` → `caméra orthographique / livrée` → `roulis gauche-centre-droite × propulsion 1-2-3` → `PNG transparents` → `atlas + manifest JSON` → `assets JumPHP`.

Distinguer :
- moteurs à hélice : trois états de disque / flou d'hélice, sans flammes de jet ;
- jets : état de poussée, sans postcombustion si l'avion historique n'en possède pas ;
- grands boss : 1 à 3 états de dommages, points faibles indépendants si utile.

L'agent doit investiguer les scripts Blender **déjà existants** dans `tools/blender/` et les enrichir, pas les écraser. Ne pas réinventer un avion complet à chaque variante : mutualiser gabarits de fuselage, implantation des moteurs, ailes et empennages. Prévoir un rendu déterministe et des tests automatiques sur les dimensions/alpha/atlas. Première tâche graphique : **revoir les silhouettes du Hurricane et du G 38** à partir du corpus, comparer proportions, puis produire un appareil d'une autre époque pour valider la généralisation.

## D. Docker pour l'ensemble du projet

JumPHP exige Docker : toutes les commandes du jeu doivent passer par `bin/jumphp` depuis le clone officiel, **pas** `php`, `composer` ou une application JS de substitution :

```sh
bin/jumphp install combat-sprite-simulator
bin/jumphp test combat-sprite-simulator
bin/jumphp shot combat-sprite-simulator 120
bin/jumphp build combat-sprite-simulator
bin/jumphp webshot combat-sprite-simulator
```

Le conteneur `reference-index` fourni ici est un **outil complémentaire indépendant** ; il ne remplace pas les images Docker que JumPHP gère lui-même. Le rendu Blender reste distant, sur la GTX, en ligne de commande `blender.exe -b --python` ; ne pas conteneuriser le GPU Windows à cette étape.

### Résoudre honnêtement le socket Docker bloqué

Le build et les tests en CI sont verts. Sur Aiphonse, diagnostiquer séparément **droits réels de l'utilisateur** et **restriction du sandbox Codex**, sans suppositions :

```sh
id
ls -l /var/run/docker.sock
docker info
docker compose version
findmnt /srv/projects
```

Ne **jamais** faire `chmod 666 /var/run/docker.sock`, exposer `2375`, modifier les groupes, lancer Docker avec privilèges élevés ou désactiver le sandbox sans autorisation explicite. Demander l'approbation d'une exécution Docker adaptée si nécessaire ; tant qu'elle manque, garder la CI GitHub comme source de tests vérifiés. La CI ne doit jamais recevoir les livres privés.

## E. Critères d'acceptation et compte rendu

1. Huit PDF transférés et vérifiés (ou liste précise de ceux manquants) ; aucun PDF dans le Git du jeu.
2. Index Docker privé et recherche de page fonctionnent, ou blocage Docker documenté avec commande de résolution autorisable.
3. Deux appareils historiques existants documentés par données techniques et sources (sans copier les planches), un troisième asset de famille différente.
4. Scripts `bpy` réutilisables, atlas PNG alpha et manifeste contrôlés, commit de code seulement.
5. Tests `bin/jumphp`, captures headless et build navigateur validés **en CI et, si possible, localement**. Ne pas annoncer une réussite locale si seul le runner GitHub a accès au socket.

N'ouvre pas de port public, ne modifie ni Tailscale ni SSH ni les autres projets, et ne fusionne pas sans autorisation. **Privilégie le pipeline fonctionnel et les preuves visuelles** plutôt qu'une longue série de documents.
