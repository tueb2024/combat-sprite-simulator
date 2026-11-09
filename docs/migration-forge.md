# JumPHP est hébergé sur git.jumphp.net

Depuis le 6 novembre 2026, le code de JumPHP (le framework, le playground jumphp.net, l'IDE, les installeurs, les outils) vit sur notre
forge **https://git.jumphp.net** (Forgejo). Le compte GitHub qui l'hébergeait n'est plus accessible. Rien ne change pour jouer ou pour
écrire un jeu, mais quelques adresses ont bougé.

## Ce qui change pour vous

| Avant | Maintenant |
|---|---|
| `https://github.com/davidnurdin/jumPhp` | `https://git.jumphp.net/jumphp/jumPhp` |
| `composer create-project jumphp/jumphp` | **identique** (Packagist) |
| `gh` (GitHub CLI) | `gh` livré avec JumPHP (`tools/bin/gh`), il parle à la forge **et** à GitHub |
| Se connecter à jumphp.net : GitHub | GitHub **ou** un compte git.jumphp.net ; plusieurs comptes, menu du compte |
| Releases (moteur, IDE, installeurs) sur GitHub | sur git.jumphp.net (`/releases/download/<tag>/<fichier>`, `/releases/download/latest/<fichier>`) |

Une forge n'a pas d'adresse `/releases/latest/download/` : utilisez `/releases/download/latest/<fichier>`.

## Mes projets

- **Un projet qui a un dépôt GitHub** continue de marcher tel quel (jumphp.net lit toujours GitHub).
- **Passer un projet sur la forge** : `bin/jumphp forge:migrate` (réécrit les adresses de JumPHP dans le projet et le remote `jumphp`), puis
  `git remote add origin https://git.jumphp.net/<vous>/<projet>.git` et `git push -u origin main`. Les nouveaux projets de l'IDE proposent la forge.
- **Mettre JumPHP à jour** : `bin/jumphp update` (il lit désormais la forge). Un ancien clone dont le remote `jumphp` pointe sur GitHub est
  réécrit par cette commande.
- **Droits TCP / SSH / UDP / ICMP** d'une application : ils sont donnés par l'administrateur pour une version exacte du code. Un `git push` sur
  le projet les annule, il faut les redemander (page du projet dans le studio).

## L'IDE

- Les anciennes versions de l'IDE cherchaient leurs mises à jour sur GitHub. Si la vôtre ne se met plus à jour : téléchargez la dernière sur
  https://jumphp.net/ide (ou la page des releases de la forge) et installez-la par-dessus.
- Dans l'IDE, publier / créer un dépôt / les releases utilisent la forge (ou GitHub si votre projet y est).

## Pour les administrateurs d'un serveur JumPHP

`JUMPHP_REPO` (variable d'environnement du playground) = `jumphp/jumPhp` sur `https://git.jumphp.net` ; `FORGEJO_*` pour la connexion par la
forge. Voir `todo-migration-git.md` pour le détail de l'installation de la forge.
