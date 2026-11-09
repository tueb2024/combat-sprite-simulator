# Construire le micro PHP sur vos propres runners (le workflow `standalone`)

[English](ci-standalone.md)

`bin/jumphp export:standalone` et `app:export` collent un jeu ou une appli sur un **micro** : un PHP construit en statique (StaticPHP)
avec les extensions dont JumPHP a besoin. Les micros sont construits par le workflow `.github/workflows/standalone.yml` et publiés
comme la release `standalone-<clé>` (la clé est une empreinte de la version de PHP, de la liste des extensions, du commit de StaticPHP
et du registre et des correctifs de JumPHP : `tools/standalone-key.sh`). Un micro n'est donc reconstruit que **si l'un de ces éléments
change**. Cette page explique comment lancer ce workflow sur vos runners ([ci-runner.fr.md](ci-runner.fr.md) pour les installer), et ce
que les machines de GitHub vous donnaient gratuitement et que les vôtres n'ont peut-être pas.

## Le lancer

Forge > le dépôt > Actions > *standalone* > *Run workflow*, ou depuis un terminal (`gh` est celui de la forge dans un projet JumPHP) :

```bash
gh workflow run standalone.yml -f extensions=tools/app-extensions.txt -f macos=false -f linux=false
```

| Entrée | Valeurs | |
|---|---|---|
| `extensions` | `tools/standalone-extensions.txt` (jeux : SDL, OpenGL, OpenAL), `tools/app-extensions.txt` (applis de bureau, l'IDE), `tools/hybrid-extensions.txt` | le micro construit = sa propre clé |
| `linux` | `true` / `false` | `false` : pas de micro Linux (voir « Docker » plus bas) ; `export:standalone` construit alors celui de Linux sur l'ordinateur de l'utilisateur |
| `macos` | `true` / `false` | `false` : pas de micro Mac (applis de bureau seulement) ; sans runner macOS, les jobs attendraient indéfiniment |

Le job Windows prend environ 10 minutes avec les téléchargements en cache, 30 à 60 la toute première fois. Son résultat :
`https://<forge>/<propriétaire>/<dépôt>/releases/download/standalone-<clé>/micro-windows-x86_64.sfx`.

## Ce que les machines de GitHub avaient, et que les vôtres n'ont peut-être pas

Sur GitHub, le même workflow « marchait tout seul » parce que `windows-latest` et `ubuntu-latest` sont des images préparées par GitHub.
Un runner à vous est une machine nue. Tout ce qui suit est géré par le workflow, ou dit ici :

| L'image de GitHub | Un runner à vous | Ce que fait le workflow / ce que vous faites |
|---|---|---|
| PowerShell 7 (`pwsh`), PHP, Composer, Git | souvent seulement Windows PowerShell 5.1 et Git | les étapes tournent dans Windows PowerShell ; PHP 8.4 et Composer sont téléchargés (php.net, getcomposer.org, empreinte vérifiée) s'ils manquent ; le `usr\bin` de Git entre dans le PATH |
| Windows en anglais | la langue de votre Windows (français...) | `VSLANG=1033` fait parler anglais les outils de Visual Studio : le `configure.js` de PHP lit la sortie de l'éditeur de liens et s'arrête sinon sur une erreur JScript |
| Visual Studio 2022 | **à installer par vous** : *Build Tools 2022*, charge « Développement Desktop en C++ » | la seule chose qu'un runner Windows doit avoir (avec Git pour Windows). [install-windows-runner.ps1](../tools/runner/install-windows-runner.ps1) installe les deux |
| Accès rapide à `api.github.com`, rarement limité | la même API répond `504` ou « rate limit exceeded » | les téléchargements de StaticPHP sont réessayés 4 fois et gardés dans `%USERPROFILE%\.spc-downloads` entre deux exécutions ; Composer installe depuis les sources (`--prefer-source`) |
| `actions/upload-artifact@v4`, `download-artifact@v4` | **Forgejo ne les gère pas** (« not currently supported on GHES ») | sur une forge, le job Windows publie lui-même le micro dans la release (le `github.token` du job, l'API de la forge) ; le job `release` ne tourne que sur GitHub |
| Docker dans le job Linux | le job tourne dans un conteneur sans Docker | voir ci-dessous |
| Fins de ligne LF | Git pour Windows convertit en CRLF | `git config --global core.autocrlf false` avant de cloner StaticPHP (un correctif de ses fichiers s'appuie sur leur texte) |

## Docker : où il faut, où il ne faut pas

- Le **job Windows compile en natif** avec Visual Studio. Il n'utilise pas Docker et un runner Windows ne saurait pas le faire (les
  conteneurs Linux sous Windows passent par WSL2). Rien à installer pour cela.
- Le **job Linux** lance `docker build docker/standalone` puis `docker run` de StaticPHP. Dans un conteneur de job il n'y a pas de
  Docker, sauf si le runner en donne un : montez le socket Docker de l'hôte (ou du dind du runner) dans le job (`container.options` /
  `valid_volumes` dans le `config.yml` du runner), ou utilisez le **runner Linux WSL2** ([ci-runner.fr.md](ci-runner.fr.md) section 4),
  un vrai Linux avec Docker. Sans cela, lancez avec `linux=false` : `bin/jumphp export:standalone <id> --linux` construit le micro Linux
  sur l'ordinateur qui exporte (environ 15 minutes, puis gardé dans `~/.jumphp/standalone/`).
- Quel que soit le runner, **ne copiez et ne renommez jamais un micro sous une autre clé** : ce serait un PHP sans les bonnes extensions
  et les exécutables de vos utilisateurs planteraient (`Call to undefined function SDL_Init()`). Si la clé a changé, le micro doit être
  reconstruit.

## Dépannage

Les journaux des jobs se lisent sur la page du run dans la forge (job > l'étape en rouge) ; l'API de la forge ne les renvoie pas.

| Symptôme | Cause / correction |
|---|---|
| Le job `key` échoue en 30 s, juste après « Fetching the repository » | le conteneur du job ne résout pas le nom de la forge : le Docker-in-Docker du runner n'a pas de DNS vers l'extérieur. Donnez-lui-en : `dns: [1.1.1.1, 9.9.9.9]` sur le service `dind` du fichier compose du runner. Test : `docker run --network <un nouveau réseau> node:20-bookworm getent hosts <forge>` |
| `Cannot find: pwsh in PATH` | le workflow demande Windows PowerShell (`shell: powershell`) ; une ancienne copie demandait `pwsh`. Mettez-la à jour |
| `configure.js ... Erreur d'exécution Microsoft JScript` après « Checking for link.exe » | les outils de Visual Studio écrivent dans la langue de Windows. `VSLANG=1033` (dans le workflow depuis cette page) |
| `ERROR: bison is required` | vu après un **build à moitié fait** dans le même dossier : StaticPHP garde les outils msys2 mais ne les remet pas dans le PATH. Supprimez `spc/pkgroot`, `spc/source`, `spc/buildroot` de l'espace de travail et relancez (un espace propre, comme celui d'un nouveau job, fonctionne) |
| Un correctif de StaticPHP « not patched (StaticPHP changed) » ou « Failed patch module » | fins de ligne CRLF (`core.autocrlf false` et cloner de nouveau), ou un dossier source déjà corrigé par un essai précédent (supprimez `spc/source/php-src`) |
| `Failed to get GitHub release API info for madler/zlib` / `HTTP/2 504` | `api.github.com` limite ou échoue pour votre adresse. Le workflow réessaie ; la première exécution sur une nouvelle machine peut demander un second essai. Les téléchargements sont gardés : chaque essai va plus loin |
| `GHESNotSupportedError: upload-artifact@v4 ... not currently supported` | vous utilisez Forgejo : prenez le workflow de ce dépôt (il publie sans artefacts) |
| Les jobs macOS restent « waiting » et le run ne finit jamais | pas de runner macOS : `-f macos=false` |
| `Cannot find path ...\buildroot\bin\micro.sfx` | la construction a échoué avant : lisez les lignes au-dessus (ce sont elles, la cause) |

## Pour les agents IA

On peut vous demander de « faire marcher le workflow standalone sur mon runner ». Lisez l'étape en échec sur la page du run (demandez à
l'utilisateur de la coller si vous n'y accédez pas), modifiez le **workflow** (il doit rester valable sur GitHub : testez
`github.server_url`), pas la machine de l'utilisateur, sauf si ce qui manque est un prérequis ci-dessus (Git, Visual Studio Build Tools,
Docker pour le job Linux) : dites-le alors à l'utilisateur et ne l'installez que s'il dit oui. N'enregistrez jamais de runner, n'écrivez
jamais de jeton dans un fichier, ne publiez jamais un micro que vous n'avez pas construit à partir des fichiers actuels avec les
commandes de ce workflow, ne déplacez jamais une release sous une autre clé. Après une exécution réussie, vérifiez le fichier
(`gh release view standalone-<clé>`, un téléchargement qui répond 200) et, pour un micro Windows, lancez un script avec lui sur la machine
du runner (`copy /b micro.sfx + test.php test.exe`, puis `test.exe` : version de PHP, `extension_loaded('ffi')`...).
