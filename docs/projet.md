# Un projet JumPHP : création, assistant, serveur, GitHub

## Créer le projet

```bash
composer create-project jumphp/jumphp mon-jeu
```

Composer télécharge JumPHP : le moteur, le squelette, les jeux d'exemple, les outils et la CLI `bin/jumphp`.
Le paquet racine n'a aucune dépendance PHP, donc rien n'est exigé de la machine à part composer lui-même.
Ensuite, `tools/post-create.sh` :

1. **relie le dossier au dépôt JumPHP** (`git init`, remote `jumphp`, rattachement au commit de cette
   version). Le projet a ainsi l'historique du framework : tes commits viennent par-dessus, et
   `bin/jumphp update` fusionne plus tard les nouvelles versions (`git pull jumphp main`) ;
2. **lance l'assistant** (`bin/jumphp init`) sur le terminal.

Si l'assistant ne peut pas démarrer (composer lancé dans Docker, CI, pas de terminal), la commande s'arrête
avec la suite à taper : `cd mon-jeu && bin/jumphp`.

Autres façons de faire, qui donnent le même résultat :

```bash
# sans PHP sur la machine
docker run --rm -it -v "$PWD":/app -w /app composer create-project jumphp/jumphp mon-jeu
cd mon-jeu && bin/jumphp

# avec git seulement
git clone https://git.jumphp.net/jumphp/jumPhp.git mon-jeu && cd mon-jeu && bin/jumphp
```

Dans ce dernier cas, `origin` désigne le dépôt JumPHP. L'étape Git de l'assistant le renomme en `jumphp`
et crée ton propre `origin`.

## L'assistant (`bin/jumphp init`)

`bin/jumphp` lancé sans argument démarre l'assistant tant que le projet n'a pas de jeu (`JUMPHP_GAME` vide
dans `.env`). Ensuite, il affiche l'aide. Chaque étape demande avant d'agir. Ctrl+C l'arrête à tout moment,
et `bin/jumphp init` reprend là où il en était : les étapes déjà faites sont reconnues. Les textes sont en
français quand la langue du système l'est (`JUMPHP_LANG=en` pour forcer l'anglais).

1. **Outils.** L'assistant vérifie que git, curl et Docker (avec Compose et Buildx) sont présents, et
   propose d'installer ceux qui manquent avec le gestionnaire de paquets du système : apt, dnf, pacman,
   zypper, apk ou Homebrew. Docker s'installe avec le script officiel de Docker sur Debian, Ubuntu et
   Fedora, et avec Docker Desktop sur macOS. L'utilisateur est ajouté au groupe `docker`, et l'assistant
   continue dans un shell qui a ce groupe. PHP, composer, emscripten et le serveur web tournent dans
   Docker : il n'y a rien d'autre à installer.
2. **Ton jeu.** Les questions portent sur :
   - le nom (titre affiché) ;
   - l'identifiant (dossier `games/<id>`, adresse web) ;
   - une phrase de description ;
   - la résolution virtuelle : 320×180, 256×224, 320×240 ou 480×270 ;
   - la langue par défaut.

   L'assistant construit ensuite l'image Docker du runtime (environ 5 min la première fois), puis crée le
   jeu depuis le squelette (`bin/jumphp new`). Il écrit `JUMPHP_GAME=<id>` dans `.env`. Les jeux
   d'exemple peuvent être retirés ; pong reste toujours, c'est le jeu de référence d'AGENTS.md.
3. **Moteur navigateur.** Tu choisis entre :
   - le **télécharger** déjà compilé (`bin/jumphp engine:download`) : c'est l'archive
     `jumphp-engine.tar.gz` de la release GitHub de la version (`VERSION`), vérifiée avec son sha256 ;
   - le compiler sur place (`bin/jumphp engine:build`, environ 25 min) ;
   - le faire plus tard.

   L'assistant propose ensuite un premier `bin/jumphp test <id>`.
4. **Serveur** (facultatif, voir plus bas).
5. **GitHub.** L'assistant fait le premier commit, avec le nom et l'e-mail git demandés s'ils ne sont pas
   configurés (ils sont enregistrés dans ce projet seulement). Il propose d'installer la CLI `gh` et de se
   connecter (`gh auth login`), puis crée le dépôt, privé ou public, avec
   `gh repo create --source . --remote origin --push`. Si le dépôt est public, il propose aussi de publier
   le jeu sur GitHub Pages (`bin/jumphp pages <id>`). Pour faire cette étape seule : `bin/jumphp github`.
6. **Claude Code.** Tu décris seulement ce que doit faire ton jeu, en quelques lignes. L'assistant écrit et
   commite `games/<id>/BRIEF.md`, un prompt complet qui rappelle :
   - de lire AGENTS.md ;
   - de remplacer le squelette ;
   - de prendre les jeux d'exemple comme modèles ;
   - de tester et de regarder les captures ;
   - la définition de « fini » ;
   - de demander avant de publier quoi que ce soit.

   Il propose d'installer Claude Code (installateur officiel `claude.ai/install.sh`) s'il manque, puis le
   lance avec ce prompt. Pour le relancer plus tard : `claude "$(cat games/<id>/BRIEF.md)"`. Pour refaire
   cette étape seule : `bin/jumphp init --claude`.

`bin/jumphp init --yes` prend toutes les réponses par défaut, n'installe aucun outil, ne crée pas de dépôt
Git et ne lance pas Claude. C'est utile pour les tests et la CI.

## Déployer sur un serveur

Un jeu JumPHP est un site statique, puisque PHP tourne dans le navigateur du joueur. Un petit serveur Linux
avec Docker suffit. La configuration se fait avec `bin/jumphp deploy:setup` (l'étape 4 de l'assistant), qui
demande :

- l'**accès SSH** (`utilisateur@machine`). Si la connexion sans mot de passe ne marche pas, l'assistant
  propose de créer une clé et de l'installer sur le serveur avec `ssh-copy-id` ;
- le **nom de domaine**. Son enregistrement DNS A/AAAA doit pointer vers le serveur pour que Let's Encrypt
  puisse délivrer le certificat ;
- le **serveur web** :

  | Mode | Ce qui tourne sur le serveur | Quand le choisir |
  |---|---|---|
  | `frankenphp` | FrankenPHP seul, ports 80 et 443, HTTPS automatique (Caddy + Let's Encrypt) | un serveur dédié au jeu, le plus simple |
  | `traefik` | Traefik (ports 80/443, Let's Encrypt, redirection HTTP → HTTPS) devant FrankenPHP | pour héberger d'autres sites ensuite derrière le même Traefik (réseau Docker `jumphp-traefik`) |
  | `traefik-external` | FrankenPHP seul, branché sur le réseau d'un Traefik déjà installé | le serveur a déjà son Traefik, avec un résolveur de certificats qui marche : l'assistant lit sur le serveur son réseau Docker, son point d'entrée HTTPS et son résolveur (`bin/jumphp server:traefik`) |
  | `traefik-passthrough` | FrankenPHP seul, derrière un Traefik déjà installé qui laisse passer le TLS ; FrankenPHP obtient lui-même son certificat | le Traefik du serveur n'a pas de résolveur de certificats qui marche (aucun site ne s'en sert) : l'assistant le détecte et choisit ce mode ; points d'entrée :443 et :80 dans `JUMPHP_TRAEFIK_ENTRYPOINT` / `JUMPHP_TRAEFIK_HTTP_ENTRYPOINT` |

- l'**e-mail Let's Encrypt** (modes `frankenphp` et `traefik`) ;
- le **dossier** sur le serveur (par défaut `/opt/jumphp/<id>`).

Tout est enregistré dans `.env` (`JUMPHP_SERVER`, `JUMPHP_DOMAIN`, `JUMPHP_SERVER_MODE`...). Si Docker
manque sur le serveur, `bin/jumphp server:setup` l'installe par SSH (script officiel de Docker) et ajoute
l'utilisateur au groupe `docker`.

`bin/jumphp deploy` fait alors, dans l'ordre :

1. construit la version navigateur du jeu (`JUMPHP_GAME`), ou de plusieurs jeux :
   `bin/jumphp deploy jeu1 jeu2`, ou `JUMPHP_DEPLOY_GAMES="jeu1 jeu2"`. Avec plusieurs jeux, le site a une
   page d'accueil et un dossier `/jeu1/`, `/jeu2/` par jeu. Un seul jeu qui a son propre site
   (`games/<id>/site/index.html` : page d'accueil, images, `robots.txt`...) : le site est à la racine et le jeu
   dans `/play/` ; `{{DOMAIN}}` y est remplacé par `JUMPHP_DOMAIN` (liens canoniques, sitemap) ;
2. prépare `.build/server/` : `site/`, le `compose.yaml` du mode (`docker/server/<mode>/`), le Caddyfile et
   un `.env` ;
3. l'envoie avec `rsync` (seuls les fichiers modifiés partent) ;
4. lance `docker compose up -d` sur le serveur. Les conteneurs ne sont recréés que si la configuration a
   changé : une simple mise à jour du jeu ne coupe rien ;
5. attend (2 minutes au plus) que `https://<domaine>/` réponde avec un certificat valide ; sinon il affiche
   l'état et les journaux du conteneur, et ce qu'il faut vérifier (DNS, points d'entrée de Traefik).

Les journaux du serveur web s'affichent avec `bin/jumphp server:logs`. Les certificats restent dans des
volumes Docker (`caddy_data` ou `letsencrypt`) : ils survivent aux déploiements.

Sans `JUMPHP_SERVER`, `bin/jumphp deploy <jeu>` garde l'ancien comportement : un `rsync` de `dist/web` vers
`JUMPHP_DEPLOY_TARGET`, pour un hébergement statique existant.

## Mettre JumPHP à jour

```bash
bin/jumphp update            # git pull jumphp main (fusion : tes commits sont gardés)
bin/jumphp setup             # images Docker du runtime, si docker/ a changé
bin/jumphp engine:download   # moteur navigateur de la nouvelle version (ou engine:build)
```

## Publier une version de JumPHP (mainteneurs)

1. Mettre à jour `VERSION`, puis commiter.
2. `bin/jumphp engine:build`, puis `bin/jumphp engine:pack`. On obtient `.build/jumphp-engine.tar.gz` et
   son `.sha256`.
3. Taguer et publier : `git tag vX.Y.Z && git push origin vX.Y.Z`, puis
   `gh release create vX.Y.Z .build/jumphp-engine.tar.gz .build/jumphp-engine.tar.gz.sha256`.
4. Packagist (`jumphp/jumphp`) lit les tags du dépôt Git : la nouvelle version apparaît d'elle-même
   quand le webhook Git de Packagist est en place.
