# Tutoriel JumPHP de A à Z

Ce tutoriel va d'une machine vide à un jeu en ligne sur ton propre domaine, en HTTPS. Il faut compter
environ 30 minutes, dont la plupart à attendre Docker. English version: [tutorial.md](tutorial.md).

On y voit dans l'ordre :

- A. créer le projet ;
- B. passer l'assistant ;
- C. faire écrire le jeu par Claude Code ;
- D. jouer et tester ;
- E. le modifier soi-même ;
- F. le mettre dans le navigateur ;
- G. le mettre en ligne sur un serveur ;
- H. l'envoyer sur Git ;
- I. mettre JumPHP à jour.

---

## A. Créer le projet

**Prérequis** : une machine Linux, macOS ou Windows avec WSL2, et un terminal. Le reste (git, Docker...)
est proposé par l'assistant.

```bash
composer create-project jumphp/jumphp mon-jeu
```

Si tu n'as pas composer (ni PHP), deux autres façons donnent le même résultat :

```bash
# avec Docker
docker run --rm -it -v "$PWD":/app -w /app composer create-project jumphp/jumphp mon-jeu
cd mon-jeu && bin/jumphp

# avec git
git clone https://git.jumphp.net/jumphp/jumPhp.git mon-jeu && cd mon-jeu && bin/jumphp
```

Le dossier `mon-jeu` contient tout JumPHP :

| Dossier | Contenu |
|---|---|
| `engine/` | le moteur |
| `skeleton/` | le squelette d'un jeu |
| `games/` | les jeux d'exemple : pong, jumpball en 3D, casse-briques... |
| `bin/jumphp` | la CLI |
| `AGENTS.md` | le guide complet, pour les humains comme pour les IA |

Il est relié au dépôt JumPHP par git, sous le remote `jumphp`.

## B. L'assistant

Avec composer, l'assistant démarre tout seul. Sinon, lance `bin/jumphp`. Il pose ses questions une par une
et demande toujours avant d'agir. Entrée accepte la réponse entre crochets. Ctrl+C l'arrête, et
`bin/jumphp init` le reprend.

1. **Outils.** git, curl et Docker sont vérifiés, et l'assistant propose d'installer ceux qui manquent. Sous
   Linux, il ajoute aussi ton utilisateur au groupe `docker`. PHP, composer, emscripten et le serveur web
   tournent dans Docker : tu n'as rien d'autre à installer.
2. **Ton jeu.** Il demande :
   - le **nom** affiché (par exemple `Space Race`) ;
   - l'**identifiant** : le dossier `games/space-race`, qui sert aussi d'adresse web ;
   - une phrase de **description** ;
   - la **résolution** : 320×180 par défaut. Ce sont les pixels du jeu, agrandis à la taille de la fenêtre ;
   - la **langue** par défaut.

   L'assistant construit l'image Docker du runtime (environ 5 min la première fois), puis crée le jeu à
   partir du squelette, un petit jeu de plateforme.
3. **Moteur navigateur.** Prends « le télécharger » : environ 10 Mo, au lieu de 25 min de compilation.
   Accepte ensuite le premier test.
4. **Serveur.** Tu peux le faire maintenant ou plus tard : voir la partie G.
5. **GitHub.** Tu peux le faire maintenant ou plus tard : voir la partie H.
6. **Claude Code.** Écris en quelques lignes ce que doit faire ton jeu, puis une ligne vide. Par exemple :

   ```
   Un shoot'em up vertical : le vaisseau tire sur des vagues d'aliens, un boss tous les 3 niveaux,
   des bonus (tir double, bouclier), 3 vies, meilleur score sauvegardé. Jouable au clavier, à la manette
   et sur téléphone.
   ```

L'assistant écrit `games/space-race/BRIEF.md`. C'est ton texte, complété par tout ce que Claude doit savoir :
lire AGENTS.md, tester, regarder les captures, et ne rien publier sans te demander. Il lance ensuite Claude
Code avec ce prompt, après avoir proposé de l'installer s'il manque.

## C. Claude écrit le jeu

Claude lit `AGENTS.md`, remplace le squelette par ton jeu, puis vérifie son travail tout seul :

- `bin/jumphp test space-race` : lint, analyse statique, et 600 images jouées sans écran ;
- `bin/jumphp shot space-race` : des captures PNG qu'il regarde ;
- la version navigateur, avec `bin/jumphp build` puis `bin/jumphp webshot`.

Demande-lui des changements comme à un collègue : « des ennemis plus rapides au niveau 2 »,
« un mode 2 joueurs », « traduis en anglais »... Pour le relancer plus tard :
`claude "$(cat games/space-race/BRIEF.md)"`, ou simplement `claude` dans le dossier.

## D. Jouer et tester

```bash
bin/jumphp run space-race        # dans une fenêtre, avec le son
bin/jumphp test space-race       # tous les contrôles
bin/jumphp shot space-race 120   # capture après 2 s : games/space-race/var/screenshots/shot.png
```

Les touches de base :

| Touche | Action |
|---|---|
| flèches + Entrée | menus |
| Échap | pause / retour |
| F11 | plein écran |
| F3 | images par seconde |

Une manette branchée marche directement.

## E. Le modifier soi-même

Un jeu, c'est :

| Fichier | Contenu |
|---|---|
| `config/project.yaml` | titre, résolution, première scène, démo jouée par `test` |
| `config/input.yaml` | les actions (`jump`, `fire`...) associées aux touches, à la manette et au tactile |
| `config/lang.yaml` | les traductions |
| `src/Scene/*.php` | les écrans : titre, partie, pause, fin de partie |
| `src/Entity/*.php` | ce qui bouge |

Une scène minimale :

```php
<?php

namespace App\Scene;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

final class PlayScene extends AbstractScene
{
    private float $x = 0;

    public function enter(array $params = []): void
    {
        $this->x = 0;                                  // la scène est réutilisée : tout remettre à zéro
    }

    public function update(float $dt): void            // $dt en secondes
    {
        $this->x += $this->actions()->axis('left', 'right') * 100 * $dt;
    }

    public function render(Renderer $r): void
    {
        $r->clear([0, 0, 40]);
        $r->fillRect($this->x, 80, 16, 16, '#ffcc00');
        $this->font()->draw($r, 'HELLO', $this->width() / 2, 20, 2, [255, 255, 255], 'center');
    }
}
```

Trois règles à retenir : ne jamais bloquer une image (pas de `sleep()`), passer par `bin/jumphp` pour tout,
et dessiner uniquement dans `render()`. Tout le reste est dans [AGENTS.md](../AGENTS.md) (en anglais) et dans
les autres pages de `docs/`. Les jeux de `games/` servent de modèles : pong pour les menus, l'IA et le jeu en
ligne, jumpball pour la 3D WebGL.

## F. Dans le navigateur

```bash
bin/jumphp build space-race      # -> games/space-race/dist/web : des fichiers statiques
bin/jumphp serve space-race      # http://localhost:8080
```

Le même code PHP tourne dans la page : PHP est compilé en WebAssembly, OpenGL y devient WebGL et OpenAL
Web Audio. Sur téléphone, une manette tactile apparaît.

## G. En ligne sur ton serveur

Il te faut :

- un serveur Linux joignable en SSH. Un petit VPS suffit, car le jeu est statique ;
- un nom de domaine dont l'enregistrement DNS **A** (et **AAAA** en IPv6) pointe vers ce serveur, par
  exemple `space-race.example.com`.

```bash
bin/jumphp deploy:setup
```

Les questions :

1. **Accès SSH** (`utilisateur@machine`). Si la connexion demande un mot de passe, l'assistant propose de
   créer une clé SSH et de l'installer sur le serveur (`ssh-copy-id`).
2. **Domaine** : `space-race.example.com`.
3. **Serveur web** :
   - **FrankenPHP seul** : le plus simple. Les ports 80 et 443 doivent être libres sur le serveur, et le
     certificat HTTPS est automatique ;
   - **Traefik + FrankenPHP** : Traefik gère le HTTPS, et d'autres sites pourront venir derrière le même
     Traefik ;
   - **derrière un Traefik déjà installé** : l'assistant demande son réseau Docker, son point d'entrée et
     son résolveur de certificats.
4. **E-mail Let's Encrypt** : il sert aux avis d'expiration.
5. **Dossier sur le serveur** : `/opt/jumphp/space-race` par défaut.

Si Docker manque sur le serveur, l'assistant l'installe (ou plus tard : `bin/jumphp server:setup`). Puis, et
à chaque nouvelle version du jeu :

```bash
bin/jumphp deploy
```

La commande construit le jeu, envoie seulement ce qui a changé, et lance `docker compose up -d` sur le
serveur. Le jeu est alors sur `https://space-race.example.com/`. La toute première visite peut prendre
quelques secondes, le temps que le certificat soit délivré. Pour voir les journaux du serveur web :
`bin/jumphp server:logs`. Pour mettre plusieurs jeux sur un même domaine (une page d'accueil et un dossier
par jeu) : `bin/jumphp deploy jeu1 jeu2`.

## H. Sur Git

```bash
bin/jumphp github
```

L'assistant fait le premier commit, installe `gh` et te connecte (`gh auth login`), puis crée le dépôt,
privé ou public. Avec un dépôt public, tu peux aussi publier le jeu gratuitement sur GitHub Pages :
`bin/jumphp pages space-race` le met sur `https://<compte>.github.io/<dépôt>/`.

## I. Mettre JumPHP à jour

```bash
bin/jumphp update            # fusionne la dernière version de JumPHP (tes commits sont gardés)
bin/jumphp setup             # images Docker, si le runtime a changé
bin/jumphp engine:download   # moteur navigateur de la nouvelle version
```

---

## En cas de problème

| Symptôme | Solution |
|---|---|
| `docker: permission denied` | Ton utilisateur vient d'entrer dans le groupe `docker` : déconnecte-toi puis reconnecte-toi, ou lance `newgrp docker`, puis `bin/jumphp init`. |
| L'assistant ne démarre pas après composer | `cd mon-jeu && bin/jumphp` |
| Pas de fenêtre avec `bin/jumphp run` | Il faut un écran (X11 ou Wayland). Sans écran, utilise `bin/jumphp test` et `bin/jumphp shot`. |
| Pas de son | Il faut PulseAudio ou PipeWire. Le jeu marche quand même, sans son. |
| `engine:download` échoue | `bin/jumphp engine:build` (environ 25 min, une seule fois) |
| Le site ne répond pas en HTTPS | Vérifie que le DNS pointe vers le serveur et que les ports 80 et 443 sont ouverts, puis lance `bin/jumphp server:logs`. |
| La machine n'est pas reconnue | Installe Docker à la main (https://docs.docker.com/get-docker/), puis relance `bin/jumphp init`. |

Diagnostic de la machine : `bin/jumphp doctor`. Toutes les commandes : `bin/jumphp help`.
