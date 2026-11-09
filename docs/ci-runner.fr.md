# Faire tourner ses jobs CI sur sa propre machine

[English](ci-runner.md) — la version anglaise est la référence.

Les workflows de `.github/workflows/` tournent aussi sur la forge (git.jumphp.net, Forgejo Actions), avec la même syntaxe que
GitHub Actions (les `actions/*` viennent de github.com via le `DEFAULT_ACTIONS_URL` de la forge). **Sur les machines de la forge
ne tournent que les jobs de son administrateur : on apporte son propre runner**, sur un PC, un VPS ou une VM. Cette page dit
lequel choisir, comment l'installer avec un script, et les pièges.

| Votre projet est sur | Runners | Comment |
|---|---|---|
| **GitHub** | ceux de GitHub (hébergés), ou les vôtres (self-hosted) | les workflows de votre dépôt, sur les runners de **votre** compte ([notes GitHub](#un-projet-sur-github-et-les-runners-self-hosted-github)) |
| **git.jumphp.net** | **vos propres runners** | cette page |

## 1. De quel runner ai-je besoin ?

| Ce que vous construisez | Il faut | Runner (label) | Votre machine | Script |
|---|---|---|---|---|
| builds JumPHP, tests du moteur, Android, `web`, tout ce qui est Docker | Linux + Docker | Linux (`ubuntu-latest`) | **Linux / macOS / VPS** | `bin/jumphp runner:install --scope ... --name ...` |
| idem | Linux + Docker | Linux (`ubuntu-latest`) | **Windows** | [`tools/runner/install-wsl-linux-runner.ps1`](../tools/runner/install-wsl-linux-runner.ps1) (WSL2 + Docker Engine dedans) |
| la bibliothèque Windows du runtime Tauri (`jumphp_tauri.dll`), `.exe`, installeurs Windows | Windows + MSVC + Rust | Windows (`windows-latest`) | **Windows** | [`tools/runner/install-windows-runner.ps1`](../tools/runner/install-windows-runner.ps1) |
| idem | Windows + MSVC + Rust | Windows (`windows-latest`) | **Linux** | [`tools/runner/windows-vm/`](../tools/runner/windows-vm/README.md) (une VM Windows QEMU/KVM sans écran, puis le script ci-dessus dedans) |
| apps macOS / iOS | un Mac + Xcode | macOS | un Mac | pas de script (les Mac de GitHub, [docs/apple.md](apple.md)) |

Plusieurs runners sur la même machine sont possibles (un PC Windows peut héberger le runner Windows *et* le runner Linux WSL2).
Récupérer les scripts sans dépôt cloné : `bin/jumphp runner:scripts <dossier>` copie `tools/runner/`, ou les télécharger du dépôt.

**Le jeton d'enregistrement** (tous les cas) : dans la forge, l'**organisation** (ou votre **utilisateur**) > Paramètres > Actions >
Runners > *Créer un nouveau runner*. Il ne sert qu'une fois et pour ce seul utilisateur / cette seule organisation. Donnez-le aux
scripts par la variable d'environnement `JUMPHP_RUNNER_TOKEN` (pas en argument : les lignes de commande sont visibles des autres
processus et restent dans l'historique du shell).

## 2. Linux, macOS ou VPS : une commande

Seul Docker est nécessaire.

```bash
bin/jumphp runner:install --scope user/<votre-login> --name mon-pc     # vos dépôts
bin/jumphp runner:install --scope org/<organisation> --name vps-1      # ceux d'une organisation
```

- **Limité à UN utilisateur ou UNE organisation, jamais à toute l'instance** (les inscriptions sont ouvertes : un runner d'instance
  laisserait n'importe qui exécuter du code chez vous).
- Les jobs tournent dans le **Docker propre au runner** (`docker:dind`) : le `docker.sock` de l'hôte n'est jamais monté.
- Le jeton : `JUMPHP_RUNNER_TOKEN`, ou fabriqué par l'API avec votre jeton de forge (`JUMPHP_FORGE_TOKEN`, ou celui que git a déjà).
  Il n'est pas conservé.
- Fichiers : `~/.jumphp/runners/<nom>/`. Suivre : `docker compose -f ... logs -f`.
- Retirer : `bin/jumphp runner:remove --name mon-pc`, puis le supprimer dans la forge.
- Sur un VPS : installer Docker, récupérer JumPHP, lancer la commande (`restart: unless-stopped`).

## 3. Windows, runner natif (builds Windows)

[`tools/runner/install-windows-runner.ps1`](../tools/runner/install-windows-runner.ps1), PowerShell 5.1+, **en administrateur**.
Windows 10 ou 11 64 bits, ~15 Go libres, internet.

```powershell
$env:JUMPHP_RUNNER_TOKEN = '<jeton d enregistrement>'
.\install-windows-runner.ps1 -Scope org/<organisation> -Name bureau-pc
```

(Scripts bloqués : `powershell -ExecutionPolicy Bypass -File .\install-windows-runner.ps1 -Scope ...`.)

Paramètres : `-Instance` (défaut `https://git.jumphp.net`), `-Scope org/<nom>|user/<login>` (obligatoire), `-Token` (sinon
`JUMPHP_RUNNER_TOKEN`, sinon demandé en saisie masquée), `-Name`, `-Labels` (défaut `windows-latest:host,windows:host`),
`-InstallDir` (défaut `C:\ci`), `-RustVersion` (défaut = `RUST_VERSION` de `.github/workflows/tauri.yml` : les garder égaux),
`-RunnerVersion`, `-SkipBuildTools`, `-ReRegister`, `-Uninstall` (`-Purge` : aussi le dossier).

Installé depuis les seules sources officielles (la vérification entre parenthèses) :

| Outil | Source |
|---|---|
| Git for Windows (son `bash` exécute les étapes) | releases GitHub de git-for-windows (SHA-256 publié) |
| Visual Studio 2022 Build Tools : outils C++ x64 + SDK Windows 11, rien d'autre | `aka.ms/vs/17/release/vs_BuildTools.exe` (pas de somme publiée : la signature Authenticode de Microsoft est vérifiée) |
| Rust, la version du workflow, **à l'échelle de la machine** (`RUSTUP_HOME=C:\rust\rustup`, `CARGO_HOME=C:\rust\cargo`) | `static.rust-lang.org` (SHA-256 de rustup-init) |
| Node.js LTS (les actions JavaScript comme `actions/checkout`) | `nodejs.org/dist` (SHA-256 de `SHASUMS256.txt`) |
| forgejo-runner | **code.forgejo.org ne publie aucun binaire Windows** (Linux seulement, vérifié le 2026-11-07) : le script compile le tag de la release avec Go (go.dev, SHA-256 vérifié), puis supprime Go. Si un binaire Windows avec somme apparaît, il le télécharge. |

Puis il enregistre le runner (**mode hôte** : les jobs tournent directement sur la machine, sans conteneur) et crée la tâche planifiée
`ForgejoRunner-<nom>` : au démarrage, en SYSTEM, relancée en cas d'échec, avec le `bash` de Git **en premier** dans le `PATH`
(voir Dépannage). Il affiche un récapitulatif et attend la ligne « declared successfully ».

Idempotent : relancé, il ne fait que ce qui manque. Première fois : 20 à 60 minutes. Redémarrage : seulement si l'installeur Visual
Studio le demande (le script le dit ; le runner démarre ensuite tout seul). Retirer : `.\install-windows-runner.ps1 -Uninstall -Name bureau-pc`
puis supprimer le runner dans la forge. Les outils restent. Un portable ne doit pas se mettre en veille.

## 4. Windows, jobs Linux (builds JumPHP, Android, tests)

[`tools/runner/install-wsl-linux-runner.ps1`](../tools/runner/install-wsl-linux-runner.ps1), en administrateur, Windows 10 2004+ ou
11, virtualisation activée dans le BIOS. **Pas de Docker Desktop** : Docker Engine est installé *dans* WSL2.

```powershell
$env:JUMPHP_RUNNER_TOKEN = '<jeton>'
.\install-wsl-linux-runner.ps1 -Scope user/<login> -Name alice-pc
```

Il active WSL et la plateforme de machine virtuelle, installe `Ubuntu-24.04` sans l'ouvrir (utilisateur `jumphp`, systemd dans
`/etc/wsl.conf`), installe Docker Engine depuis le dépôt apt officiel de Docker (clé vérifiée contre l'empreinte publiée), clone
JumPHP dans la distribution, y lance `bin/jumphp runner:install --scope ... --name ...`, et crée la tâche `JumphpWslRunner-<nom>`
qui garde la distribution vivante (sans processus, WSL l'arrête, et le runner avec).

**Redémarrages.** La première fois, activer WSL demande en général un redémarrage de Windows : le script s'arrête (code 3010) et le dit.
Redémarrez, ouvrez PowerShell en administrateur et **relancez la même commande** : il reprend où il s'était arrêté (le jeton n'est jamais
conservé : redéfinissez `JUMPHP_RUNNER_TOKEN` ; une fois le runner enregistré, plus besoin de jeton).

Options : `-Distro`, `-LinuxUser`, `-Memory 8GB -Processors 4` (écrits dans `%USERPROFILE%\.wslconfig` ; WSL2 prend la moitié de la RAM par
défaut), `-Labels`, `-RunAtBoot`, `-Uninstall` (`-RemoveDistro` supprime la distribution et tout son contenu).

**Au logon ou au boot.** Une distribution WSL appartient à un utilisateur Windows : la tâche tourne donc sous *votre* compte, par défaut à
l'ouverture de session (le runner est arrêté tant que personne n'est connecté). `-RunAtBoot` demande votre mot de passe Windows (gardé par
le Planificateur de tâches, pas par le script) et démarre au boot.

## 5. Linux, jobs Windows : une VM Windows sans écran

Pour la bibliothèque Windows du runtime Tauri depuis Linux. [`tools/runner/windows-vm/`](../tools/runner/windows-vm/README.md) crée une VM
Windows 10 avec QEMU/KVM (installation automatique, pilotes virtio, sans fenêtre), puis y installe la section 3 :

```bash
cd tools/runner/windows-vm
./create-vm.sh --iso ~/Téléchargements/Win10_22H2.iso --dir ~/windows-ci-vm --locale fr-FR --timezone "Romance Standard Time"
export JUMPHP_RUNNER_TOKEN=<jeton>
./provision.sh --dir ~/windows-ci-vm --scope org/<nom> --name win-vm
./start-vm.sh --dir ~/windows-ci-vm [start|stop|status]
```

- **Windows demande une licence que vous possédez.** Téléchargez l'ISO vous-même chez Microsoft
  (<https://www.microsoft.com/software-download/windows10ISO>) ; les scripts ne téléchargent aucun Windows et n'en fournissent pas. Les
  ISO d'évaluation expirent (l'une s'installe déjà expirée) : prenez l'ISO multi-éditions. Windows 10 n'a plus de mises à jour de
  sécurité gratuites : la VM doit rester jetable.
- Les ports RDP / SSH de la VM sont liés à `127.0.0.1` ; PowerShell est piloté par l'agent invité QEMU (`guest-exec.py`, sans SSH). Le jeton
  entre dans l'invité par l'agent (dossier privé, supprimé aussitôt), jamais en ligne de commande.
- Il faut `/dev/kvm`, 8 Go de RAM, 80 Go de disque. Détails : le README du dossier.

## 6. Comment un projet choisit son runner

Par les **labels** de `runs-on:`. Un job attend qu'un runner de son périmètre ait *tous* les labels.

| Label | Posé par | Tourne dans |
|---|---|---|
| `ubuntu-latest`, `ubuntu-22.04` | `runner:install`, script WSL2 | un conteneur Docker (`node:20-bookworm`) |
| `windows-latest`, `windows` | `install-windows-runner.ps1` (mode hôte) | directement sur la machine (labels `:host`) |

```yaml
jobs:
  linux:   { runs-on: ubuntu-latest,  steps: [ ... ] }
  windows:
    if: github.server_url != 'https://github.com'      # sur GitHub, windows-latest est celui de GitHub
    runs-on: windows-latest
    steps:
      - uses: actions/checkout@v4
      - shell: bash                                    # le bash de Git for Windows
        run: ...
```

Votre propre label : `-Labels mon-gpu:host` (script Windows) ou `--labels mon-gpu:docker://image` (`runner:install`), puis `runs-on: mon-gpu`.
Les workflows vivent dans **`.github/workflows/`**. Ne créez **pas** `.forgejo/workflows/` : Forgejo le lit *à la place de* `.github/workflows`
quand il existe, et tous les autres workflows cesseraient de tourner sans bruit.

Exemple réel : `.github/workflows/tauri-windows.yml` (`runs-on: windows-latest`, forge seulement) construit `jumphp_tauri.dll` et le publie
comme asset `jumphp_tauri-windows-x86_64.dll` de la release `tauri-<clé>` (`bash tools/tauri-key.sh | sha256sum | cut -c1-16`) que
`bin/jumphp tauri:download windows-x86_64` et `app:export <app> --windows` téléchargent. Il publie avec le `github.token` du job (ou un secret
`FORGE_TOKEN`). Son dossier Cargo est `C:/ci/target-tauri` (conservé : builds incrémentaux), d'où `-InstallDir` = `C:\ci` par défaut.

## 7. Un projet sur GitHub, et les runners self-hosted GitHub

Ses workflows utilisent les runners de GitHub (`ubuntu-latest`, `macos-15`, `windows-latest`) avec les minutes de votre compte, ou votre
propre **runner self-hosted GitHub** (Settings > Actions > Runners > *New self-hosted runner* : GitHub donne les commandes). Les scripts
JumPHP ci-dessus concernent les runners **Forgejo** ; côté GitHub :

- Windows : `config.cmd --url ... --token ...` puis `svc.cmd install` ; installer les mêmes outils (Git, Build Tools, Rust, Node) comme le fait
  la première moitié de `install-windows-runner.ps1` ; `runs-on: [self-hosted, windows]`.
- Linux : `config.sh` et `svc.sh install` ; `runs-on: [self-hosted, linux]`.
- **Jamais de runner self-hosted sur un dépôt public** dont n'importe qui peut ouvrir des pull requests : le workflow d'un fork tournerait chez
  vous. Même règle pour la forge.

Les commandes `gh` de `bin/jumphp` (android, apple, installer, release) marchent sur les deux : `tools/bin/gh` est le vrai `gh` pour GitHub et
parle à l'API de git.jumphp.net sinon.

## 8. Sécurité (à lire)

- **Un seul utilisateur ou une seule organisation**, jamais l'instance.
- **Un runner exécute le code des workflows de son périmètre.** Qui peut pousser un workflow dans un dépôt de ce propriétaire (ou dont une pull
  request déclenche un workflow) peut exécuter des commandes sur le runner. N'enregistrez des runners que pour des propriétaires de confiance.
- **Le mode hôte (script Windows) n'isole rien** : les jobs tournent en SYSTEM sur la machine, avec tous ses fichiers et son réseau. Utilisez une
  **VM jetable** (section 5) ou une machine sans rien de précieux : pas de données personnelles, pas d'identifiants enregistrés, pas d'accès à vos
  autres machines. Les runners Linux isolent les jobs dans des conteneurs d'un Docker propre (`dind` privilégié : ne pas l'exposer à des inconnus).
- **Les jetons ne sont pas conservés** : les scripts les prennent dans l'environnement (ou une saisie masquée), les utilisent une fois, ne les
  écrivent ni dans un fichier ni dans un journal. Le runner garde son propre identifiant (`<InstallDir>\runner\.runner`, lisible des seuls
  administrateurs et SYSTEM ; `~/.jumphp/runners/<nom>/data/.runner`) : c'est un secret.
- **Révoquer** : supprimer le runner dans la forge (Paramètres > Actions > Runners) ; puis `-Uninstall` / `runner:remove` sur la machine, ou
  supprimer la VM.
- Les secrets d'un dépôt sont donnés à ses jobs : un runner partagé avec les workflows d'autrui peut les lire. Un runner par niveau de confiance.
- Un runner travaille tant que sa machine est allumée ; les jobs attendent dans la file sinon.

## 9. Dépannage

| Symptôme | Cause / remède |
|---|---|
| Erreurs `bash` bizarres sous Windows, ou « WSL n'a pas de distribution » | `C:\Windows\System32\bash.exe` est le **stub WSL**. `run.ps1` met `C:\Program Files\Git\bin` en tête du `PATH` ; si vous lancez le runner autrement, faites-en autant. |
| Une étape meurt sans message sous Windows | `shell: bash` tourne avec `-e` : un `grep` sans résultat termine l'étape. Ajouter `|| true`. |
| Les `sha256sum` diffèrent entre Windows et Linux | Git for Windows affiche `hash *fichier` : ne garder que le premier champ. |
| Le hash de fichiers extraits diffère (une clé calculée dessus) | Git for Windows a converti LF en CRLF : `git config core.autocrlf false` puis `git checkout HEAD -- <fichiers>` (fait dans `tauri-windows.yml`). |
| Le workflow ne tourne pas | un dossier `.forgejo/workflows/` existe (il remplace `.github/workflows`) ; ou le push ne touche pas les `paths:` ; ou aucun runner du périmètre n'a tous les labels. |
| « no matching runner » / le job attend | runner hors ligne, mauvais périmètre (un runner `user/` ne sert pas une organisation), label manquant. |
| `registration failed` | jeton déjà utilisé ou d'un autre périmètre : en refaire un. Vérifier `-Instance`. |
| Une relance n'a rien fait | normal (idempotent). `-ReRegister` (Windows) / `-Uninstall` puis relancer pour repartir de zéro. |
| Build Tools : code 3010 | redémarrage nécessaire ; le runner repart seul. 1618 : un autre installeur tourne. Journaux : `%TEMP%\dd_*.log`. |
| PowerShell : `NativeCommandError` sur la sortie de rustup / git / go | PowerShell traite stderr comme une erreur avec `$ErrorActionPreference='Stop'` ; les scripts encadrent les programmes natifs. |
| `generate-config` illisible (Windows) | le `>` de PowerShell 5.1 écrit en UTF-16 ; utiliser `cmd /c "... > fichier"` (le script le fait). |
| WSL : le runner s'arrête à la fermeture du terminal | la tâche de maintien est absente : `Get-ScheduledTask JumphpWslRunner-*`, `Start-ScheduledTask`. |
| WSL : systemd ne tourne pas | `/etc/wsl.conf` doit contenir `[boot] systemd=true` ; `wsl --shutdown`, relancer le script. |
| VM Windows : agent injoignable | VM éteinte ou Windows en démarrage ; `./start-vm.sh status`. |
| VM Windows : l'installation ne finit pas | la langue de l'ISO doit correspondre à `--locale` ; l'édition doit exister dans l'ISO (`--image-index`). |
| Disque plein | dossiers `target` de Rust (`C:\ci\target-tauri`), images Docker (`docker system prune`). |

Journaux : Windows `<InstallDir>\runner\runner.log` ; Linux `docker compose -f ~/.jumphp/runners/<nom>/compose.yaml logs -f` ; WSL
`wsl -d Ubuntu-24.04 -u jumphp -- docker logs jumphp-runner-<nom>-runner-1`.

> Construire le micro PHP (workflow `standalone`) sur ces runners, et ce que les images de GitHub donnaient gratuitement : [ci-standalone.fr.md](ci-standalone.fr.md).

## 10. Pour les agents IA

On peut vous demander « installe un runner sur ma machine ». Faites ceci, et rien d'autre :

1. **Demandez d'abord.** Dites ce que vous allez installer (Git, Visual Studio Build Tools ~5 Go, Rust, Node, un runner, WSL, une VM...), où, et que
   cela demande les droits administrateur et de longs téléchargements. N'installez rien avant un oui.
2. **Jamais de runner d'instance.** Le périmètre est UN utilisateur ou UNE organisation que l'utilisateur nomme ; demandez, ne devinez pas.
3. **Jetons** : l'utilisateur crée le jeton dans la forge et vous le donne dans une variable d'environnement (`JUMPHP_RUNNER_TOKEN`) ou le saisit
   dans son terminal. Ne l'écrivez jamais dans un fichier, un commit, une note, un argument de script ou votre réponse ; jamais dans `AGENTS.md`,
   un `.env` versionné ou un workflow. S'il apparaît dans votre contexte, ne le répétez pas.
4. **Donnez à l'utilisateur les commandes exactes** (il les lance en administrateur dans son terminal : vous ne pouvez pas élever pour lui) et expliquez
   les redémarrages et le « relancer la même commande ». Préférez les scripts de `tools/runner/` à l'improvisation.
5. Prévenez que le mode hôte n'isole rien (proposez une VM jetable) ; prévenez de la licence Windows pour le kit VM et ne téléchargez jamais Windows
   d'une source non officielle.
6. Ne touchez pas aux runners qui ne sont pas à l'utilisateur ; ne supprimez pas de runner dans la forge sans demande.
7. À la fin, vérifiez « Idle » dans la liste des runners (ou le récapitulatif du script) et dites comment retirer le runner.

## 11. Ce qui a été testé, et ce qui ne l'a pas été

| Élément | Testé | Comment |
|---|---|---|
| `install-windows-runner.ps1` | oui | sur une VM Windows 10 22H2 qui avait déjà les outils : enregistrement, connexion, relance (idempotence), `-Uninstall` ; sur une VM fraîchement installée par le kit `windows-vm` : l'installation complète. PowerShell 5.1. |
| `windows-vm/*` | oui | `create-vm.sh` avec une ISO Windows 10 Pro française sur un hôte Debian KVM, puis `provision.sh` ; `start-vm.sh`, `guest-exec.py` |
| `install-wsl-linux-runner.ps1` | **en partie** | syntaxe PowerShell vérifiée ; les parties Linux (édition de wsl.conf, dépôt apt et empreinte de clé Docker, clonage) exécutées dans un conteneur Ubuntu 24.04. **Jamais lancé sur un vrai Windows avec WSL** : activation de WSL, `wsl --install --no-launch`, repli du premier lancement, jeton par WSLENV, `sg docker`, tâche planifiée, `-RunAtBoot` non testés. Prévoir de petites corrections. |
| Windows 11, Windows ARM64, Windows Server | non | |
| Runner macOS | pas de script | |

## Les runners du mainteneur (un exemple)

Le runner Linux du PC du mainteneur est enregistré pour l'organisation `jumphp` seule ; les releases du moteur et du runtime sont construites en
local (`bin/jumphp engine:pack`, `runtime:build`) et publiées avec `gh release create vX.Y.Z .build/jumphp-engine.tar.gz ...`. Le runner Windows
`win10-vt2` est une VM Windows 10 (4 vCPU, 5 Go) sur le même PC, installée exactement comme la section 3.
