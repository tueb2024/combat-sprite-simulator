# Le JumPHP runtime : utiliser son propre réseau depuis le navigateur

## Ce que c'est

Une application JumPHP qui tourne **dans le navigateur** (jumphp.net, `play.jumphp.net`) n'a pas de socket : ses connexions TCP, SSH, UDP et
pings passent par le **pont du serveur** jumphp.net, qui ne voit que ce que le serveur voit. Le **JumPHP runtime** est un petit programme
installé sur *votre* ordinateur (une icône dans la zone de notification). Une fois installé, l'application connecte **depuis votre adresse IP
et votre réseau** : réseau local, VPN, serveur qui n'ouvre son port qu'à votre adresse.

## Pourquoi

- joindre une base de données de votre réseau local (`192.168.x.x`) que le serveur ne verra jamais ;
- passer par votre VPN d'entreprise ;
- vous connecter à un serveur dont le pare-feu n'accepte que votre IP ;
- pas de limite de ports « bases de données » du pont du serveur (avec votre accord, cible par cible).

## Est-ce sûr ?

Oui, et c'est fait pour : le runtime n'accepte pas n'importe qui.

- il n'écoute que sur `127.0.0.1` (votre machine), jamais sur le réseau ;
- il n'accepte que les pages des sites JumPHP connus (`jumphp.net`, `play.jumphp.net`) ; un autre site ou un programme local sans origine est refusé et journalisé ;
- **deux portes** : l'administrateur de jumphp.net a approuvé l'application *pour cette version exacte du code* (le ticket), **et** vous avez autorisé l'application sur votre ordinateur (fenêtre du système, jamais dans la page) ;
- une confirmation de plus la première fois que l'application vise une adresse privée ou un port inhabituel ;
- vos autorisations sont révocables une par une ou toutes d'un coup ; un refus est gardé 10 minutes ;
- aucune commande locale, aucun accès à vos fichiers, rien n'écoute pour les pages ; le trafic n'est jamais enregistré (seuls l'origine, l'appli, la cible, l'heure et le volume le sont) ;
- les mises à jour sont signées.

Détails : `docs/runtime-protocol.md`.

## Installer

1. **Télécharger** pour votre système sur https://jumphp.net/runtime ou dans les releases de https://git.jumphp.net/jumphp/jumPhp (tag `runtime-v<version>`) :
   Linux `runtime-setup-linux-x86_64`, Windows `runtime-setup.exe` (macOS : bientôt). Les binaires seuls : `runtime-linux-x86_64`, `runtime-windows-x86_64.exe`.
2. **Installer** : Linux `chmod +x runtime-setup-linux-x86_64 && ./runtime-setup-linux-x86_64` ; Windows : double-clic sur `runtime-setup.exe`.
3. **Le lancer** : une icône apparaît dans la zone de notification ; il démarre ensuite avec votre session.
4. **Autoriser l'application** : la première fois, une fenêtre du runtime demande « le site play.jumphp.net veut utiliser votre réseau pour l'application X. Autoriser ? ».
   Chrome peut aussi demander « Accéder aux appareils du réseau local » : acceptez. Safari bloque ce contenu : l'appli reste sur le pont du serveur.

L'application vous guide elle-même (le badge, plus bas) et la fenêtre d'aide se met au vert toute seule.

## Langue

Le runtime parle la langue de l'ordinateur (14 langues : fr en es de it pt nl pl ru uk tr ja zh ko ; sinon l'anglais). Ordre : le choix fait dans la fenêtre
(liste « Langue »), la variable d'environnement `JUMPHP_LANG`, le fichier `install.json` du dossier de données (`{"lang": "fr"}` : un installateur peut l'écrire ;
sinon le runtime l'écrit lui-même la première fois qu'une page JumPHP lui parle, avec la langue de la page : `ws://127.0.0.1:47820/v1?lang=fr`), puis la langue du
système (`LC_ALL` / `LC_MESSAGES` / `LANGUAGE` / `LANG` sous Linux, la langue d'affichage de Windows, `AppleLanguages` sous macOS). Les textes : `apps/runtime/config/lang.yaml`
(menu de la zone de notification, boîtes de dialogue, question d'autorisation) et `apps/runtime/ui/lang.js` (les pages) ; `tests/LangTest.php` vérifie qu'ils sont complets.

## L'utiliser dans une appli, pas à pas

1. Rien à installer : le client est dans le moteur (depuis la 0.5.27) ; mettez à jour JumPHP (`bin/jumphp update`).
2. Rien ne change dans vos connexions :

   ```php
   $c = $this->engine->tcp()->connect('192.168.1.10', 3306);   // ou ssh(), udp(), icmp()
   $c->on('open', fn () => ...);
   $c->on('error', fn (string $why) => $this->status->text = $why);
   ```
3. Dans le formulaire, le badge à côté des champs de connexion :

   ```yaml
   - {class: JumPHP\Runtime\RuntimeBadge, id: runtime}
   ```
4. Le réglage (project.yaml) :

   ```yaml
   project:
     net_mode: auto        # auto | server | local
   ```
5. Pour réagir en code : `$this->engine->runtime()->state()`, `->check()`, `->pair($id, $title)`, `->on('change', fn (string $state) => ...)`, `->bridgeUrl()`.
6. Tests : `$engine->setRuntime(new JumPHP\Runtime\ManualRuntime(RuntimeLink::READY))` donne l'état que vous voulez, sans navigateur.

## Le badge

| Couleur | État | Texte | Au clic |
|---|---|---|---|
| vert | `ready` : installé et autorisé | Local network active | rien |
| orange | `running` (installé, pas autorisé) ou `refused` | Allow this app on your network | demande l'autorisation (`pair`) |
| rouge | `missing` : pas installé ou pas lancé | Local network off - install the runtime | ouvre le guide d'installation (`RuntimeGuide`) |
| gris | `unsupported`, `net_mode: server`, ou recherche en cours | Server network | rien : le pont du serveur suffit |

`RuntimeGuide` est la boîte de dialogue : pourquoi, les 4 étapes (télécharger pour votre système, installer, lancer, autoriser), les liens vers
jumphp.net/runtime et les releases. Elle revérifie toutes les 2 s. On l'ouvre aussi à la main : `$this->engine->scenes->push(RuntimeGuide::class)`.
Le système est lu dans le navigateur (module `jumphp-runtime.js`) ou donné par le runtime ; inconnu, tous les systèmes sont montrés.
La version proposée se règle : `jumphp_runtime: {version: 0.1.0}` dans project.yaml.

## Les modes

| `net_mode` | Comportement |
|---|---|
| `auto` (défaut) | le runtime quand son état est `ready`, sinon le pont du serveur |
| `server` | toujours le pont du serveur |
| `local` | le runtime (`running` ou `ready`) ; sinon erreur `the JumPHP runtime is not available (state: missing)` |

Aussi `JUMPHP_NET_MODE` (adresse de la page `?net_mode=local`, ou variable d'environnement), qui l'emporte sur project.yaml. Le ticket est toujours
demandé au serveur ; seule l'adresse ouverte ensuite change. Sur le bureau, rien de tout cela : l'appli a déjà son réseau.

## En cas de problème

| Symptôme | À faire |
|---|---|
| Badge rouge alors qu'il est installé | est-il lancé (icône) ? Un pare-feu qui bloque `127.0.0.1:47820-47829` ? Chrome : acceptez « appareils du réseau local » |
| Détecté seulement après avoir fermé Chrome | corrigé : la page attend la réponse à la question « réseau local » de Chrome sans fermer la connexion (fermer la question la laissait en suspens), et cherche à nouveau dès le retour sur la page (onglet, installation terminée, réseau) : tout de suite, puis à 0,5 s, 1,5 s, 4 s |
| Badge gris sous Safari | Safari bloque `ws://127.0.0.1` depuis une page https : le pont du serveur est utilisé |
| Orange qui reste orange | cliquez le badge et répondez « Autoriser » dans la fenêtre du runtime (30 s sans réponse = refus ; un refus dure 10 min) |
| `the JumPHP runtime is not available (state: …)` | mode `local` sans runtime : installez-le, ou passez en `auto` |
| `origin` refusé (403) dans le journal du runtime | le site n'est pas dans sa liste blanche (fenêtre du runtime) |
| Connexion refusée côté ticket | l'administrateur n'a pas approuvé cette version de l'appli (`docs/tcp.md`) : les deux portes doivent être ouvertes |

## Tests de bout en bout

`tools/e2e/run.sh [chrome|firefox|all]` démarre le vrai démon (Docker, Xvfb, `HOME` temporaire) et pilote Chrome et Firefox sans interface : une page **https**
ouvre `ws://127.0.0.1:<port>/v1` et reçoit `hello` ; une origine hors liste blanche est refusée (403) et un mauvais `Host` aussi (421) ; le vrai module
`jumphp-runtime.js` retrouve le runtime sur 47820..47829 et donne le système et la version ; un ticket falsifié reçoit `error ticket` ; une origine ajoutée dans `config.json` est acceptée.
Ne couvre **pas** un ticket valide + la boîte de consentement (il faut une personne ; c'est le `ServerTest` de PHPUnit, avec une fausse boîte), ni Safari
(contenu mixte bloqué, repli sur le pont du serveur), ni l'invite « Local Network Access » de Chrome. Détails : `apps/runtime/tests/e2e/README.md`.

## Pour les agents IA

- **Quand l'utiliser** : une application JumPHP *pour le navigateur* qui doit joindre une machine du réseau de l'utilisateur (base locale, VPN, serveur filtré par IP) en TCP / SSH / UDP / ping. Pas pour un jeu, pas pour une appli de bureau (elle a déjà son réseau).
- **Comment** : mettre à jour le moteur (0.5.27 ou plus), un `RuntimeBadge` dans le formulaire de connexion, `net_mode: auto` (défaut). Garder le code de connexion identique (`$engine->tcp()` etc.) : pas de code spécial « local ».
- **Ne jamais** : contourner le consentement (ne pas appeler le runtime autrement que par `pair()` / les connexions, ne pas écrire dans `grants.json`, ne pas ouvrir de WebSocket vers `127.0.0.1:47820` à la main) ; demander à l'utilisateur de désactiver une sécurité (pare-feu, Local Network Access, Mixed Content, liste blanche d'origines) ; forcer `net_mode: local` par défaut dans une appli publiée (un utilisateur sans runtime n'aurait que des erreurs) ; mettre un secret ou une adresse privée dans le dépôt.
- Tests : `ManualRuntime` ; ne jamais supposer un runtime réel. Vérifier le badge avec `bin/jumphp shot` et regarder l'image.


## Android

Un téléphone Android peut lancer le runtime : `apps/runtime-android` (une petite appli Kotlin, `jumphp-runtime-android.apk` de la release
`runtime-android-v<version>`, que le guide du moteur propose sur un téléphone). Même protocole et même adresse que sur ordinateur ; les
questions de consentement sont des notifications avec leurs boutons ; il redémarre avec le téléphone. Sur iPhone ce n'est pas possible : l'application
continue de passer par le pont du serveur jumphp.net.

## Surveillance et interrupteur

Le client du moteur (`RuntimeClient`) regarde en permanence si le runtime est là : toutes les 8 s tant qu'il manque (ou que le navigateur le bloque), toutes les
20 s sinon (il peut s'arrêter ou démarrer plus tard), tout de suite quand une connexion échoue, quand l'onglet revient au premier plan ou que le réseau revient.
Quand le runtime est prêt, le voyant (`RuntimeBadge`) montre un demi-bouton **Local | Server** : la personne peut repasser par le proxy du serveur jumphp.net
pour les nouvelles connexions même si son réseau local est disponible (réglage gardé : option `net_use_server`).

## Réveil du runtime Android par Firebase

Si l'appli Android est arrêtée (redémarrage, gestion de batterie), la page de l'application demande au serveur jumphp.net (`POST /api/runtime/wake`, même origine que le
ticket) de la réveiller : le serveur envoie par Firebase Cloud Messaging un message `{t: wake}` à priorité haute (projet Firebase `jumphp-runtime`, clé de compte de service
dans l'environnement du site `FCM_SERVICE_ACCOUNT_B64`), l'appli rallume son relais. La page garde le jeton anonyme de l'appli (donné dans son `hello`, `RuntimeClient::pushToken()`) ;
rien d'autre n'est envoyé. Il faut les services Google Play sur le téléphone ; sans eux, le démarrage avec le téléphone et le bouton de la page restent.
