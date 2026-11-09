# Threads et TrueAsync

JumPHP sait faire tourner du travail lourd dans de **vrais threads** grâce à
[TrueAsync](https://true-async.github.io) : génération de niveaux, recherche de chemin, IA, images
procédurales… La frame n'attend jamais, le jeu reste à 60 images par seconde.

Exemple complet : [`games/hello-threads`](../games/hello-threads), une fractale calculée par un pool de
threads.

## Deux runtimes

| | `default` | `async` |
|---|---|---|
| PHP | 8.3 NTS (`php:8.3-cli-bookworm`) | **8.6 ZTS**, fork TrueAsync (`php-8.6.0-trueasync-0.11.3` + `ext/async` v0.11.3) |
| Image | `jumphp/desktop:dev` (`docker/desktop`) | `jumphp/desktop-async:dev` (`docker/desktop-async`, ~15 min la première fois) |
| Extensions | SDL2, SDL_image, SDL_mixer, SDL_ttf, GD… | les mêmes, mêmes commits, + `true_async` et libuv 1.52.1 |
| `JumPHP\Async` | sans threads : travail réparti entre les frames | vrais threads (pool, un thread par tâche) |
| Navigateur | moteur WebAssembly PHP 8.5 ; `JumPHP\Async` passe par des **Web Workers** (voir plus bas) | idem |

Un jeu choisit son runtime dans `config/project.yaml` :

```yaml
project:
  runtime: async
```

`bin/jumphp run|test|shot|install|export:desktop` prennent alors l'image async (construite automatiquement
au premier usage, ou `bin/jumphp setup async`). `JUMPHP_RUNTIME=default|async` force l'un ou l'autre, par
exemple pour vérifier qu'un jeu async marche aussi sans threads :

```bash
JUMPHP_RUNTIME=default bin/jumphp test hello-threads
JUMPHP_RUNTIME=async bin/jumphp test engine        # tests du moteur avec de vrais threads
```

Tout le moteur (et Pong, le casse-briques…) tourne tel quel sur le runtime async.

## L'API : `JumPHP\Async`

```php
$tasks = $this->engine->tasks();

// une tâche, un thread
$task = $tasks->thread(static fn () => Maze::generate(64, 48, $seed));

// un pool : N threads démarrés une fois, beaucoup de petits travaux
$pool = $tasks->pool();                       // un worker par cœur (Tasks::workers())
$pool->submit(static fn (int $y) => Fractal::row($y, 192), $y)
    ->then(fn (string $row) => $this->rows[$y] = $row,      // appelé sur le thread principal
           fn (\Throwable $e) => ...);

$task->isDone(); $task->isOk(); $task->result(); $task->error();
$pool->close();                               // dans exit()
```

- Le moteur interroge les tâches au début de chaque frame, avant `update()` des scènes : les callbacks de
  `then()` s'exécutent là, sur le thread principal, et peuvent donc modifier la scène.
- `submit()` ne bloque jamais : la file de TrueAsync est gardée courte (64 travaux), le reste attend dans
  une réserve locale vidée à chaque frame. Avec `Async\ThreadPool::submit()` brut, une file pleine
  **suspend l'appelant**, c'est-à-dire qu'elle gèle le jeu.
- `Tasks::backend()` dit où le travail tourne : `'threads'` (TrueAsync), `'workers'` (navigateur) ou
  `'frames'` (runtime `default` : thread principal, entre les frames, `$tasks->budget` ms par frame, 4 par
  défaut, au moins un travail). `Tasks::threaded()` est vrai pour les deux premiers.

### Ce qui passe dans un thread

Un thread est un **autre monde PHP** : il reçoit une **copie** de la closure et de ses arguments. Le moteur y
charge l'autoloader du jeu (`vendor/autoload.php`) ; il n'y a ni moteur, ni SDL, ni scène, ni conteneur.

- **`static fn`** obligatoire : une closure écrite dans une méthode emporte `$this` (la scène, avec ses
  ressources SDL) même si elle ne s'en sert pas. JumPHP la refuse avec une `LogicException`, dans les deux
  modes, pour que l'erreur se voie aussi sans threads.
- Des valeurs simples à l'aller et au retour : entiers, flottants, chaînes, tableaux, objets simples. Pas de
  références (`&$x`), pas de ressources (fichiers, textures, sons), pas d'objets à propriétés dynamiques.
- Le code des threads va dans des classes statiques à part (`src/Work/` dans hello-threads, exclu du
  conteneur dans `config/services.yaml`).
- Une exception dans un thread revient en `JumPHP\Async\TaskFailed` (classe, message, fichier et ligne
  d'origine). Une exception non rattrapée dans un thread brut TrueAsync serait relancée dans le thread
  principal, au milieu d'un `usleep()` : JumPHP enveloppe chaque travail pour l'éviter.

### Quand les résultats arrivent

TrueAsync signale la fin d'un thread par son reactor (libuv), qui ne tourne que lorsque le thread principal
attend : `usleep()`, `Async\delay(1)`… mais pas `Async\suspend()` ni `Async\delay(0)`.
- **Sur le bureau**, la boucle ReactPHP dort entre deux frames : les résultats arrivent naturellement.
- **En headless** (`test`, `shot`), les frames s'enchaînent sans pause : `Tasks::poll()` attend alors 1 ms
  par frame, et seulement tant que des tâches sont en cours.
- Il ne faut jamais boucler sur `isDone()` sans rendre la main.

## Mesures (i7-8565U, 4 cœurs, hello-threads, boucle temps réel)

| Image 192×144, 2500 itérations | thread principal (4 ms/frame) | pool de 4 threads |
|---|---|---|
| temps de calcul | 1,3 à 2,8 s | 0,24 à 0,76 s |
| images / seconde du jeu | 60 | 60 |

Calcul brut : 4 parts de travail en 0,66 s contre 0,36 s pour une seule, soit environ ×2,2 sur ce processeur
portable (le turbo baisse quand les 4 cœurs travaillent).

## Ce qu'il a fallu adapter (runtime async)

TrueAsync impose **PHP 8.6 en ZTS**. Les extensions SDL compilent sur 8.6 avec trois adaptations, toutes
dans `docker/desktop-async` et sans fork :

- `php86-compat.h`, inclus de force (`-include`) : `XtOffsetOf`, `WRONG_PARAM_COUNT`, `zval_dtor()` et
  `zend_exception_get_default()` ont disparu de PHP 8.6 ; ils sont redéfinis avec leurs équivalents actuels
  (`offsetof`, `zend_wrong_param_count()`, `zval_ptr_dtor_nogc()`, `zend_ce_exception`).
- `php-sdl/src/rect.c` : le `zend_unregister_functions()` de MSHUTDOWN laisse une entrée invalide dans la
  table des fonctions sous 8.6 (segfault à la sortie de PHP). Il est retiré à la compilation (`sed`) : le
  moteur détruit ces fonctions lui-même.
- libuv ≥ 1.45 est requis (Debian bookworm fournit 1.44) : il est compilé depuis les sources.

Ces correctifs pourraient être reportés dans les forks `php-sdl` (pour qu'ils compilent aussi en 8.6).

## Dans le navigateur : un pool de Web Workers

Dans le navigateur, `Tasks::backend()` vaut `'workers'`. La page (`engine/web/jumphp.js`) lance un pool de
**Web Workers** (`engine/web/jumphp-worker.js`), chacun avec **son propre moteur PHP WebAssembly** et les
fichiers du jeu. Le moteur n'est ni recompilé ni passé en ZTS, et il ne faut pas de SharedArrayBuffer : les
en-têtes COOP/COEP sont donc inutiles, et le jeu reste intégrable en iframe dans le portail.

```
jeu (PHP, thread principal)                    page (JS)                     worker n (PHP wasm)
Pool::submit(static fn ...)
  -> /tmp/jumphp-tasks/out/<id>.job ─────────► worker libre ? postMessage ─► Worker::run()
                                                                              (closure + arguments)
  <- /tmp/jumphp-tasks/in/<id>.res ◄────────── écrit le résultat ◄────────── serialize([ok, valeur])
Tasks::poll() -> then()
```

- La closure voyage sous forme de **code source et valeurs capturées**, grâce à
  [laravel/serializable-closure](https://github.com/laravel/serializable-closure) (PHP pur, MIT). La même
  `static fn` que pour les threads fonctionne. La classe où elle est écrite (pour `self::`) doit être
  chargeable par l'autoloader, ce qui est le cas des scènes.
- Nombre de workers : nombre de cœurs moins un, au plus 4. `?workers=N` dans l'URL de la page change ce
  nombre ; `?workers=0` le désactive.
- Les workers démarrent dès qu'un pool est créé, puisqu'il faut 0,5 à 1 s pour démarrer chaque moteur. Un
  worker qui plante est relancé, et sa tâche échoue en `TaskFailed` (`WorkerError`). Si aucun worker ne
  démarre (navigateur trop ancien, mémoire), la page le signale et PHP refait les tâches lui-même, entre les
  frames.
- Chaque worker réserve sa propre mémoire WebAssembly (256 Mo au départ, allouée à la demande par le
  navigateur) : c'est pour cela que leur nombre est limité à 4.
- Le transport des fichiers se teste sans navigateur : `engine/tests/Async/WebWorkersTest.php` joue le rôle
  de la page et des workers.

Mesure (Chrome sans écran, Docker, deuxième image de hello-threads) : **3 workers 4,55 s à 60 FPS**, contre
7,55 s à 23 FPS sur le thread principal.

## Pourquoi pas TrueAsync lui-même dans le navigateur

Les Web Workers suffisent pour la même API, mais TrueAsync compilé en WebAssembly buterait sur quatre points :

1. **Les coroutines.** TrueAsync change de contexte avec les fibers Zend (assembleur boost ou
   `ucontext`). Aucun des deux n'existe pour WebAssembly, et le fork n'a pas de code emscripten. Il faudrait un
   backend de fibers emscripten (`emscripten_fiber_*`), qui impose ASYNCIFY : binaire plus gros et PHP plus
   lent. JSPI (stack switching natif) pourrait remplacer ASYNCIFY plus tard.
2. **libuv.** C'est le reactor de TrueAsync, et il n'existe pas de portage WebAssembly maintenu.
3. **ZTS + pthreads.** Il faudrait SharedArrayBuffer, donc les en-têtes COOP/COEP, ce qui gêne l'intégration en
   iframe.
4. **PHP 8.6.** Le moteur WebAssembly devrait passer du 8.3 au fork 8.6.
