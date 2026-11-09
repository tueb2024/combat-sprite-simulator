# Applications de bureau (Tauri) : des pages HTML, du PHP derrière

[English](tauri.md) — la version anglaise est la référence complète (API détaillée, protocole C).

JumPHP fait des jeux (SDL : bureau, navigateur, téléphones) et des **applications de bureau** : des
fenêtres natives dont le contenu est en **HTML / CSS / JavaScript**, affiché par la WebView du système, et dont la
logique est en **PHP**. La fenêtre, la WebView, les boîtes de dialogue et les notifications viennent de
[Tauri 2](https://tauri.app) ; le programme, c'est PHP.

```bash
bin/jumphp app:new notes "Notes"      # apps/notes à partir de skeleton-app/
bin/jumphp install notes
bin/jumphp app:run notes              # une fenêtre sur ton écran
bin/jumphp app:test notes             # php -l + phpstan + phpunit (sans fenêtre) + l'app pour de vrai sur un écran virtuel
bin/jumphp app:shot notes             # apps/notes/var/screenshots/app.png
bin/jumphp app:export notes           # apps/notes/dist/standalone/notes-linux-x86_64 : un seul fichier à donner
```

L'exemple : [`games/hello-desktop`](../games/hello-desktop), un bloc-notes (ouvrir / enregistrer avec les boîtes
de dialogue du système, une horloge PHP envoyée à la page, des fichiers glissés sur la fenêtre, une question avant
de quitter si le texte n'est pas enregistré).

**Natif uniquement.** Les applications de bureau ne tournent pas dans le navigateur. Linux, Windows et macOS : le
runtime est compilé et essayé sur les trois par GitHub Actions ; l'export en un fichier existe pour Linux et
Windows. Un **jeu** peut aussi s'en servir : les [jeux hybrides](#jeux-hybrides-sdl--tauri) (`ui: hybrid`). **Rien ne change pour les jeux** : jeux SDL sur le bureau, dans le navigateur (WebAssembly), en
exécutable autonome, applis Android et Apple fonctionnent exactement comme avant ; aucun de leurs codes, images
Docker ou commandes n'utilise cette partie.

## Le principe

PHP reste le programme principal, comme pour un jeu. Tauri est un *backend de plateforme* que PHP pilote :

1. `app.php` → `JumPHP\Tauri\App::boot()` → `run()` : PHP appelle `jt_run()` de la bibliothèque
   `libjumphp_tauri.so` (Rust, dossier `tauri/`) **depuis son thread principal**, par FFI ;
2. Tauri construit l'app et fait tourner sa boucle native (GTK sous Linux) **sur ce thread** ;
3. à chaque frame (60 par seconde, et tout de suite quand il se passe quelque chose), Tauri rappelle PHP :
   `App::frame()` lit les événements (`jt_poll()`), fait un tour de la boucle ReactPHP (timers, HTTP, tâches),
   et envoie ses commandes (`jt_send()`), exécutées juste après sur le thread principal ;
4. quand PHP demande à quitter, `jt_run()` rend le code de sortie.

C'est le même modèle que le moteur navigateur (la page appelle `jumphp_frame()` à chaque
`requestAnimationFrame`). PHP ne bloque jamais : dialogues, sélecteurs de fichiers et appels des pages répondent
plus tard (une `Task` côté PHP, une `Promise` côté JavaScript). **PHP décide** : fermer une fenêtre ou l'app est un
événement auquel PHP répond (par défaut il ferme / quitte ; un listener peut faire `preventDefault()`).

Une seule bibliothèque pour toutes les apps : les pages sont servies depuis le dossier de l'app à l'exécution
(`tauri://localhost/`), pas compilées dedans.

## Une app

```
apps/notes/
  app.php                 exit(JumPHP\Tauri\App::boot(__DIR__)->run());
  config/project.yaml        ui: webview, main: App\Main, app: {...}
  src/Main.php            implémente JumPHP\Tauri\AppMain : commandes, listeners, timers
  ui/                     les pages : index.html, CSS, JS, images
  assets/icon.png         l'icône de la fenêtre
  tests/MainTest.php      étend JumPHP\Testing\AppTestCase
```

`config/project.yaml` :

```yaml
project:
  id: notes
  title: "Notes"
  version: 0.1.0
  ui: webview                   # une app de bureau (run / test / shot passent la main aux commandes app:*)
  main: App\Main
  app:
    pages: ui                   # dossier des pages
    tick_hz: 60                 # frames par seconde quand rien ne se passe
    native_commands: true       # les commandes native.* intégrées pour les pages
    window: { url: index.html, width: 960, height: 640, min_width: 480, min_height: 320, devtools: false }
```

Côté PHP :

```php
final class Main implements AppMain
{
    public function boot(App $app): void
    {
        // page -> PHP -> page : const r = await jumphp.invoke('greet', {name: 'Ada'})
        $app->command('greet', fn (array $p) => "Bonjour {$p['name']}");
        // une réponse qui vient plus tard : renvoyer une Task
        $app->command('pick', fn () => FilePicker::open(['filters' => ['Texte' => ['txt', 'md']]]));
        // PHP -> page : jumphp.on('clock', ...)
        $app->gameLoop->addPeriodicTimer(1.0, fn () => $app->send('clock', ['time' => date('H:i:s')]));
        // PHP décide de la fermeture
        $app->window()->on('close-requested', function (NativeEvent $e) use ($app) {
            $e->preventDefault();
            Dialog::confirm('Quitter ?', 'Notes', 'Quitter', 'Rester')->then(fn ($oui) => $oui && $app->quit());
        });
    }
}
```

Côté page, `window.jumphp` est injecté avant les scripts de la page :

```js
const r = await jumphp.invoke('greet', {name: 'Ada'});   // Promise : la réponse de PHP, ou le message de son exception
jumphp.send('clicked', {x: 1});                          // PHP : $app->on('page:clicked', ...)
jumphp.on('clock', (p) => ...);                          // PHP : $app->send('clock', ...)
await jumphp.invoke('native.open', {filters: {Images: ['png', 'jpg']}});   // sans code PHP : native.message,
                                                         // native.confirm, native.open, native.save, native.notify, native.openUrl
```

En ligne de commande (sans fenêtre) : une classe `main` qui implémente aussi `JumPHP\Tauri\AppCli` reçoit d'abord
`static cli(array $args, string $root): ?int`, avant le chargement de la bibliothèque native : un code de sortie
s'arrête là (`--help`, `--version`, une installation silencieuse), `null` ouvre la fenêtre comme d'habitude.

## Fonctions natives depuis PHP : `JumPHP\Native`

Les mêmes appels sur toutes les cibles ; ce qui les réalise dépend de l'endroit où le code tourne :

```php
Dialog::message('Enregistré.', 'Notes');
Dialog::confirm('Supprimer ?', 'Notes', 'Supprimer', 'Garder')->then(fn (bool $oui) => ...);
FilePicker::open([...])->then(fn (?string $fichier) => ...);   // aussi multiple, folder(), save()
Notification::show('Fini', 'Export terminé');
Shell::openUrl('https://jumphp.net');
Clipboard::copy('texte'); Clipboard::paste()->then(fn (string $t) => ...);
Native::supports('open');                                      // avant d'appeler

// apps et jeux hybrides : icône de zone de notification avec menu, raccourci global (même sans le focus)
$app->tray('main', ['tooltip' => 'Notes', 'menu' => [['id' => 'show', 'text' => 'Afficher'], '-', ['id' => 'quit', 'text' => 'Quitter']]])
    ->on('menu', fn (string $item) => $item === 'quit' ? $app->quit() : $app->window()->show());
$app->globalShortcut('CmdOrCtrl+Shift+K', fn () => $app->window()->show()->focus());

// menus (JumPHP\Tauri\Menu) : d'une fenêtre ($app->window()->setMenu()) ou de toute l'app ($app->setMenu()) ;
// éléments avec id (et raccourci), à cocher ('checked'), séparateurs ('-'), sous-menus ('items'), éléments du
// système ('role' : quit, about, copy, cut, paste, select_all, undo, redo, minimize, fullscreen, hide...).
// Sur macOS il n'y a qu'une barre de menus : celui d'une fenêtre devient celui de l'app.
$app->window()->setMenu([
    ['text' => 'Fichier', 'items' => [['id' => 'open', 'text' => 'Ouvrir...', 'accelerator' => 'CmdOrCtrl+O'], '-', ['role' => 'quit']]],
    ['text' => 'Affichage', 'items' => [['id' => 'wrap', 'text' => 'Retour à la ligne', 'checked' => true]]],
]);
$app->window()->on('menu', fn (string $item, ?bool $coche) => ...);   // aussi $app->on('menu', fn ($item, $fenetre, $coche))
$app->window()->setMenuItem('wrap', ['checked' => false]);
```

| Cible | Fournisseur | Fonctions |
|---|---|---|
| app de bureau, jeu hybride | `tauri` | toutes : message, confirm, open, save, folder, notify, openUrl, openPath, reveal, copy, paste |
| jeu SDL sur le bureau | `sdl` | message (la boîte de message de SDL) |
| version navigateur | `browser` | openUrl, copy |
| tests sans écran, ailleurs | `none` | rien |

Ce qui manque à une cible fait échouer la Task avec `UnsupportedFeature` (ou renvoie `false`). En dessous restent
le **FFI** de PHP (n'importe quelle bibliothèque C du système) et les extensions PHP.

## Jeux hybrides (SDL + Tauri)

Un jeu peut aussi piloter Tauri : `ui: hybrid` dans son `config/project.yaml`. Il reste un jeu SDL (scènes, fenêtre
SDL, son ; `app.php` inchangé) et gagne le côté natif des apps : `JumPHP\Native`, icône de zone de notification,
raccourcis globaux, et des fenêtres HTML à côté de la sienne (panneau de contrôle, éditeur, lanceur…). Exemple :
[`games/hello-hybrid`](../games/hello-hybrid) (des balles en SDL, un panneau de contrôle en HTML, « enregistrer une
image » par le sélecteur de fichiers du système, un menu dans la zone de notification, Ctrl+Maj+P partout).

```yaml
project:
  ui: hybrid
  main: App\Desktop                # facultatif : un AppMain (commandes des fenêtres HTML, icône, raccourcis)
  app:
    window: {url: index.html, width: 340, height: 560}    # facultatif : une fenêtre HTML (pages dans ui/)
```

`Application::run()` passe la main à `JumPHP\Tauri\Hybrid` : la boucle de Tauri appelle la frame de l'app, qui fait
un tour de ReactPHP, où le timer du jeu fait avancer le moteur comme d'habitude. Le jeu tourne **comme un jeu
normal** (sans Tauri) dans les tests sans écran, dans le navigateur, dans les applis téléphone, et sans FFI ou sans
runtime. `bin/jumphp run` le lance dans l'image Tauri ; `export:standalone` le fait avec un micro SDL **et** FFI
(`tools/hybrid-extensions.txt`).

**Une seule fenêtre** : `app: {embed: true}` met le jeu **dans** la fenêtre Tauri (`JumPHP\Tauri\EmbeddedGame`) :
SDL dessine chaque frame en mémoire (pas de fenêtre SDL), l'image passe au runtime (`jt_frame()`), la page de JumPHP
(`engine/web/tauri/game.html`) la dessine sur un canvas, nette et centrée, et renvoie clavier, souris et focus au
moteur (actions, F3, F11 marchent ; manettes lues par SDL). Fermer la fenêtre termine le jeu. Essayé pour de vrai
avec Pong sur Xvfb. Pour les grandes résolutions ou les jeux OpenGL, une fenêtre SDL reste préférable.

## Tests

`JumPHP\Testing\AppTestCase` joue l'app **sans fenêtre** (un `FakeRuntime` : ni FFI ni GTK) ; le test fait la
page et l'utilisateur : `invoke()`, `pageSend()`, `requestClose()`, `answerDialog()`, `frames()`, `wait()`,
`sent()`, `lastSent()`. `bin/jumphp app:test` les lance puis fait tourner **l'app pour de vrai** sur Xvfb : échec
sur un warning PHP, une exception, ou si la page principale ne s'est jamais chargée.

## Export : un seul exécutable

`bin/jumphp app:export <app>` réutilise `export:standalone` : le micro de StaticPHP avec les extensions des apps
(`tools/app-extensions.txt` : FFI, OpenSSL, mbstring…, sans SDL ; construit une fois, ~10 min, gardé dans
`~/.jumphp/standalone`), `ffi.enable=1` injecté dans l'exécutable, l'app en PHAR et la bibliothèque Tauri. Au
premier lancement, l'app est dépaquetée dans `~/.cache/jumphp/<id>-<build>` ; les fichiers de l'utilisateur vont
dans `~/.local/share/<id>`.

L'ordinateur a besoin de **WebKitGTK 4.1** (`libwebkit2gtk-4.1-0` : Debian 12+, Ubuntu 22.04+, Fedora 39+) et
d'une glibc 2.34 ou plus récente, comme toute app Tauri sous Linux. Vérifié sur un Debian 13 nu avec seulement
`libwebkit2gtk-4.1-0` (sans PHP).

L'export des jeux n'est pas touché : sans les variables des apps, `export:standalone` construit et retrouve le même
micro qu'avant (même clé).

## Commandes

| Commande | |
|---|---|
| `app:new <app> [titre]` | `apps/<app>` depuis `skeleton-app/` |
| `app:run <app>` | l'app sur ton écran (X11, sinon Wayland ; bus de session pour les notifications ; GPU) |
| `app:test <app>` | php -l, phpstan, phpunit, puis l'app pour de vrai sur Xvfb |
| `app:shot <app> [frames] [out.png]` | la fenêtre principale après N frames |
| `app:export <app> [--linux] [--windows]` | un exécutable Linux / Windows (`.exe` : WebView2, présent dans Windows 10 / 11) |
| `tauri:setup` | l'image `jumphp/tauri` (le runtime bureau + FFI + Rust + WebKitGTK) |
| `tauri:build` | `tauri/` → `build/tauri/linux-x86_64/libjumphp_tauri.so` |
| `tauri:download [plateforme...\|--all]` | la bibliothèque de ces sources depuis les releases GitHub : `linux-x86_64`, `windows-x86_64`, `macos-universal` |

Quand la bibliothèque manque ou est plus ancienne que ses sources, les commandes d'app la téléchargent (si la
release de ces sources existe), sinon la compilent. `JUMPHP_TAURI_TRACE=1` affiche chaque commande et événement.

`run`, `test` et `shot` sur le dossier d'une app passent la main à `app:run`, `app:test`, `app:shot` ;
`pages` ne publie pas les apps (elles n'ont pas de version navigateur).

## Dans le navigateur, sur téléphone

La même app tourne aussi dans une page web : `bin/jumphp build <app>` produit `dist/web/` (fichiers statiques, comme un
jeu). Ses pages sont les mêmes (même `window.jumphp`), son PHP tourne dans le moteur WebAssembly de JumPHP
(`JumPHP\Tauri\BrowserRuntime`), et `engine/web/jumphp-app.js` fait en HTML ce que Tauri fait sur le bureau :
fenêtres (la principale remplit la page, les autres flottent), barre de menus et raccourcis, dialogues HTML,
sélecteur de fichiers du navigateur (copiés dans `/tmp/open/` de PHP), enregistrement = téléchargement (ce que PHP
écrit dans `/tmp/save/`), notifications, presse-papiers ; icône de zone de notification et raccourcis globaux : rien.
Sur téléphone : la page telle quelle, `bin/jumphp twa <app> --url ...` pour Android (`pwa: true`), « Sur l'écran
d'accueil » sur iPhone. Essayé dans Chrome sans écran avec `games/hello-desktop` (salutation par PHP, menu, dialogue,
enregistrement téléchargé, aucune erreur). Une vraie app Tauri native Android / iOS (PHP embarqué dans l'app) n'est
pas faite : la boucle y appartient à l'activité / UIKit.

## Linux : WebKitGTK installé par l'app

Une app Linux a besoin de WebKitGTK 4.1. **S'il manque, l'app propose de l'installer** avant d'ouvrir sa fenêtre
(`JumPHP\Tauri\LinuxWebView`) : elle reconnaît la distribution (Debian / Ubuntu / Mint, Fedora / RHEL, Arch /
Manjaro, openSUSE), demande (dans le terminal, sinon une boîte `zenity` / `kdialog`), installe avec les droits de
l'utilisateur (root, fenêtre de mot de passe `pkexec`, ou `sudo`), puis démarre. Refusé ou impossible : elle donne
la commande exacte et s'arrête. `JUMPHP_INSTALL=yes` installe sans demander, `no` dit seulement quoi faire. Essayé
sur Debian 13, Ubuntu 24.04, Fedora 42 et Arch nus. Windows (WebView2) et macOS (WKWebView) ont déjà leur WebView.

## Export macOS et mises à jour

`bin/jumphp app:export <app> --macos` (depuis Linux, dans Docker) produit `dist/standalone/<app>-macos.zip` : une
`<Titre>.app` avec deux exécutables (Apple silicon, Intel), un lanceur qui démarre celui du Mac, `Info.plist` et
l'icône ; macOS 12 ou plus ; non signée (compte développeur Apple) : la première fois, clic droit > Ouvrir. Le
workflow `app-export` l'exporte, l'ouvre pour de vrai sur un Mac Apple silicon et un Mac Intel, puis fabrique son
**.dmg** (`hdiutil` n'existe que sur Mac) : l'artefact `dmg` du run, `<app>-macos.dmg` (l'app et un lien vers
Applications où la glisser).

Une app exportée se met à jour elle-même (`$app->updater()`) : `bin/jumphp app:release <app> --url <adresse>
[--notes "..."] [--linux] [--windows] [--macos]` exporte l'app et écrit un `update.json` **signé** (clé ECDSA dans
`~/.jumphp/keys/<id>-update.pem`, à garder précieusement ; sa moitié publique dans `config/update.pub`, à commiter) ;
on dépose `dist/standalone/` à cette adresse, et project.yaml `app: {update: {url: <adresse>/update.json}}`.
`check()` vérifie la signature et la version, `install()` télécharge, vérifie le SHA-256 et remplace l'exécutable,
`relaunch()` relance. `games/hello-desktop` a Aide > Check for updates (essayé pour de vrai : la 0.1.0 a trouvé,
installé et relancé la 0.1.1). Détails : [tauri.md](tauri.md#updates).

## Démarrer avec l'ordinateur

Une app exportée peut se lancer quand l'utilisateur ouvre sa session (sans droits administrateur ;
`JumPHP\Native\Autostart`) : `$app->autostart()->set(true)` (ou `false`), `isSupported()` (une app exportée sous
Windows, macOS ou Linux, pas `php app.php`), `isEnabled()`, `startedAtLogin()` (lancée par l'ordinateur : le drapeau
`--autostart`). Windows : la valeur `<id>` de `HKCU\Software\Microsoft\Windows\CurrentVersion\Run` ; macOS : un
LaunchAgent `~/Library/LaunchAgents/<identifier>.plist` qui ouvre la `.app` ; Linux : `~/.config/autostart/<id>.desktop`.
project.yaml `app: {autostart: {default: true, hidden: true}}` : activé au premier lancement (une fois, ensuite
l'utilisateur décide), fenêtre principale cachée quand l'ordinateur l'a lancée (une app de la zone de notification).
Une page demande avec `jumphp.invoke('native.autostart', {on: true})`. Exemple : `games/hello-desktop`, View > Start
with the computer.

## Plateformes

Le workflow `.github/workflows/tauri.yml` (à chaque changement de `tauri/` ou du côté PHP, ou à la main) compile la
bibliothèque sur Linux (Ubuntu 22.04 : glibc 2.35+), Windows et macOS (universelle), lance `games/hello-desktop` pour
de vrai sur chacun (la page doit se charger et appeler PHP), et publie la release `tauri-<clé>` qu'utilisent
`tauri:download` et `app:export`. Le micro des apps pour Windows est publié par le workflow `standalone` lancé avec
`extensions: tools/app-extensions.txt`. Sous Windows et macOS sans Docker : un PHP 8.2+ avec FFI (`ffi.enable=1`),
`composer install`, puis `JUMPHP_TAURI_LIB=<la bibliothèque> php app.php`.

## Feuille de route

Fait : le runtime (Linux, Windows, macOS), PHP qui le pilote depuis son thread principal, fenêtres, pages, page ↔ PHP,
dialogues / notifications / liens / presse-papiers, menus, icône de zone de notification, raccourcis globaux,
glisser-déposer, fermeture décidée par PHP, jeux hybrides, tests sans fenêtre et en vraie fenêtre, export Linux, Windows et
macOS, mises à jour.

Ensuite : la signature des apps (certificats du développeur), Android et iOS (la boucle y est pilotée par
l'activité / UIKit ; à la place, `bin/jumphp android <app>` et `bin/jumphp ios <app>` mettent la version navigateur de
l'app dans la WebView du téléphone, voir docs/tauri.md ; Android essayé, iOS pas encore), d'autres fonctions natives.
