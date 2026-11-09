# OpenGL / WebGL, son 3D, mobile, cycle de vie, HTTP, PWA, manettes

Ces API marchent sur les deux cibles. Ce qui n'existe pas sur une cible ne fait rien et ne lève pas d'erreur
(`false` / `null`). L'exemple complet est `games/hello-webgl` ; `games/jumpball` est un vrai jeu en 3D.

## Le pont page ⇄ PHP

Toutes ces fonctions passent par `JumPHP\Bridge\JsBridge`, synchronisé avec les frames grâce aux hooks
`preMainLoop` / `postMainLoop` d'emscripten (le moteur wasm n'a pas besoin d'être recompilé) :

```
requestAnimationFrame
  ├─ preMainLoop  : la page écrit ses événements dans /tmp/jumphp-events (manettes, cycle de vie, réponses HTTP...)
  ├─ jumphp_frame : PHP lit les événements (JsBridge::poll), met à jour, dessine
  └─ postMainLoop : la page exécute les messages de PHP (/tmp/jumphp-bridge)
```

Les événements urgents (`hide`, `close`) sont livrés tout de suite : la page appelle PHP entre deux frames
(`jumphp_events()`), puis écrit `var/` dans IndexedDB.

Messages personnalisés : `window.JumPHP.send('bonjour', {n: 1})` dans la page, puis
`$this->engine->bridge->on('bonjour', fn (array $d) => ...)` en PHP. Pour les tests, `window.JumPHP.php('echo 1;')`
exécute du PHP entre deux frames (la sortie part dans la console).

Fichiers JS (`engine/web/`) : `jumphp-device.js` (cycle de vie, mobile), `jumphp-http.js`,
`jumphp-pwa.js` + `sw.js`, `jumphp-gamepad.js`. La 3D n'a pas de module JS : PHP appelle WebGL directement.

## OpenGL / WebGL

Le moteur embarque l'extension [PHP-OpenGL](https://github.com/Ponup/php-opengl) (avec `docker/ext/php-opengl.patch`).
Ses fonctions `gl*` tournent dans le contexte GL de SDL : OpenGL sur le bureau (pilote du GPU ou Mesa), WebGL 1
dans le navigateur (les mêmes appels compilés par emscripten). On écrit les shaders en **GLSL ES 1.00**
(`attribute` / `varying` / `gl_FragColor`, `precision mediump float;` dans les fragment shaders ; le moteur ajoute
`#version 100`), que les deux cibles acceptent. Sans contexte GL (tests sans écran avec le pilote SDL `dummy`),
`isAvailable()` vaut false : il faut dessiner un repli en 2D.

Le même programme compile sur les deux cibles, mais deux détails diffèrent, et le moteur les gère seul :
- les cibles de rendu de SDL stockent leur ligne du haut en premier, donc les passes les retournent ;
- le renderer GLES2 de SDL (navigateur) range les couleurs en BGR. Le moteur le mesure au démarrage (un pixel
  rouge relu) et inverse les canaux là où il le faut.

### Haut niveau : couche 3D et post-traitement — `$this->engine->gl()`

À la fin de chaque frame, le moteur compose en GL l'image SDL (la 2D), la couche 3D dessinée par vos shaders,
puis éventuellement un shader de post-traitement sur toute l'image :

- couche `below` : la 3D apparaît là où la 2D a la couleur clé (par défaut magenta). On efface la vue avec
  cette couleur et on dessine le HUD par-dessus. Une teinte plus sombre de la clé assombrit la 3D, ce qui
  fait marcher les fondus et les menus translucides ;
- couche `above` : la 3D se dessine sur la 2D (alpha) ;
- résolution `view` (la taille de project.yaml, gros pixels rétro) ou `native` (pixels de l'écran) ;
- `setSupersample(2)` : la couche est dessinée deux fois plus grande puis lissée (bords sans escalier),
  dans la limite de 2560 pixels de large.

```php
$gl = $this->engine->gl();
if ($gl->isAvailable()) {
    $gl->setLayer('below', 'view', [255, 0, 255]);
    $this->prog = $gl->program($vertexGlsl, $fragmentGlsl);    // une fois (enter)
    $this->cube = $gl->buffer($floats);                         // x y z u v ...
    $this->tex = $gl->texture('assets/textures/caisse.png');    // ou textureFromRgba($w, $h, $octets)
    $gl->setPostShader('void main() { gl_FragColor = texture2D(u_frame, v_uv) * vec4(1.0, 0.9, 0.8, 1.0); }');
}
// render(), à chaque frame :
$r->clear([255, 0, 255]);
$gl->clear([20, 20, 60]);
$gl->draw($this->prog, [
    'attributes' => ['a_pos' => [$this->cube, 3, 5, 0], 'a_uv' => [$this->cube, 2, 5, 3]],   // [buffer, taille, stride, offset] en floats
    'uniforms' => ['u_mvp' => Mat4::multiply($projection, $vue, $modele)],
    'textures' => ['u_tex' => $this->tex],
    'cull' => 'back',
]);
```

- GLSL ES 1.00 (WebGL 1). Un shader de post-traitement ne contient que `main()`. Le moteur ajoute
  `precision mediump float;`, `varying vec2 v_uv`, `u_frame` (l'image), `u_resolution`, `u_viewport` (où est la
  vue du jeu), `u_view` (taille virtuelle) et `u_time`.
- Les couleurs de l'API sont en 0..255, comme partout ailleurs ; les uniforms GLSL sont en 0..1.
- Une erreur de compilation déclenche l'événement `$gl->on('error', fn (string $m) => ...)` et s'affiche sur stderr / dans la console `[opengl]`.
- `disable()` dans `exit()` : retour à l'affichage SDL seul.
- En `mediump`, les grands arguments de `sin` / `cos` perdent toute précision (NaN sur certains GPU, Mesa compris) :
  `sin(3.14159 * fract(x))` plutôt que `sin(x * 500.0)`.
- Sans GL, `Mat4` sert aussi à la 3D logicielle (`Mat4::project()` + `drawLine`) : c'est le repli de
  `hello-webgl`. `jumpball` remplit ses faces ligne par ligne dans l'ordre du peintre (`App\Gfx\SoftwareView`).

### Bas niveau : les fonctions gl* — `$this->engine->opengl()`

SDL dessine sa 2D avec le même contexte GL : les appels `gl*` se font entre `begin()` et `end()`. `begin()` dessine
ce que SDL a en attente, sauvegarde son état GL, lie une cible 3D (taille de la vue, avec profondeur) et l'efface.
`end()` pose la 3D dans l'image 2D en cours (avec alpha) et rend son état à SDL. On dessine le HUD ensuite.

```php
$gl = $this->engine->opengl();
// enter() : les ressources (elles sauvegardent / restaurent l'état elles-mêmes)
$this->prog = $gl->program($vs, $fs, ['a_pos' => 0, 'a_normal' => 1]);
$this->vbo = $gl->buffer($floats);               // ou une chaîne pack('g*', ...)
// render() :
$r->clear([8, 10, 26]);
if ($gl->begin([8, 10, 26, 255])) {
    glUseProgram($this->prog);
    glBindBuffer(GL_ARRAY_BUFFER, $this->vbo);
    glEnableVertexAttribArray(0);
    glVertexAttribPointer(0, 3, GL_FLOAT, 0, 24, 0);
    glUniformMatrix4fv(glGetUniformLocation($this->prog, 'u_mvp'), 1, false, $mvp);
    glDrawArrays(GL_TRIANGLES, 0, $n);
    $gl->end();
}
$this->font()->draw($r, 'HUD', 4, 4);             // la 2D par-dessus
```

- Jamais de `gl*` hors de `begin()` / `end()` ou des aides du moteur (`program()`, `buffer()`, `updateBuffer()`,
  `indexBuffer()`, `texture()`, `textureFromRgba()`, `renderTarget()`, `draw()`) : ce serait l'état de SDL qui
  changerait.
- `setSupersample(2)` : cible deux fois plus grande, bords plus lisses.
- Toutes les fonctions et constantes : `engine/stubs/opengl.stub.php`. Exemple : `games/hello-webgl`,
  scène `RawGlScene`.
- Tests sans écran : `bin/jumphp test` / `shot` lancent les jeux qui appellent `gl()` / `opengl()` sur Xvfb
  (Mesa llvmpipe), et les captures montrent la vraie 3D. `JUMPHP_GL=0` force le repli sans GL.

## Son 3D — `$this->engine->audio3d()`

[PHP-OpenAL](https://github.com/Ponup/php-openal) : OpenAL Soft sur le bureau (PulseAudio / PipeWire), Web Audio
dans le navigateur (l'OpenAL d'emscripten). Les sons sont placés dans l'espace du jeu et entendus depuis un
auditeur, en général la caméra :

```php
$a = $this->engine->audio3d();
$a->synth('bip', Synth::tone(880, 0.2));                   // ou load('moteur', 'assets/sfx/moteur.wav')
$a->listener([$camX, $camY, $camZ], [0, 0, -1]);           // à chaque frame : position, direction (haut = +y)
$voix = $a->play('bip', [$x, 0, $z], 1.0, 1.0, true);      // gain, pitch, boucle
$a->move($voix, [$x, 0, $z]);                              // suivre la source
$a->stop($voix);
```

Les WAV stéréo sont mélangés en mono (OpenAL ne place que le mono dans l'espace). Le volume et la coupure du son
suivent SoundManager. `setDistance($reference, $rolloff, $max)` règle l'atténuation. La musique et les bruitages
simples restent dans SoundManager. Sans périphérique (tests), le moteur prend la sortie muette d'OpenAL Soft
(`ALSOFT_DRIVERS=null`).

## Cycle de vie — `$this->engine->lifecycle()`

| Événement | Navigateur | Desktop |
|---|---|---|
| `hide` / `show` | onglet masqué, appli en arrière-plan | fenêtre réduite / restaurée |
| `blur` / `focus` | la page perd / reprend le focus | la fenêtre perd / reprend le focus |
| `online` / `offline` | connexion réseau | — |
| `resize` (w, h), `orientation` ('landscape'/'portrait') | taille de la page, téléphone tourné | taille de la fenêtre |
| `fullscreen` (bool) | plein écran | — |
| `close` | la page se ferme (sauvegarder maintenant) | le jeu quitte |

Options de project.yaml : `pause_scene: App\Scene\PauseScene` (empilée automatiquement quand le jeu est masqué ou
perd le focus, sauf au-dessus d'un menu ; `pause_on_blur: false` pour la limiter à `hide`) et
`mute_when_hidden: true` (valeur par défaut).

## Mobile — `$this->engine->device()`

`vibrate(40)` ou `vibrate([30, 20, 30])`, `keepAwake()` (Wake Lock, l'écran ne s'éteint pas),
`lockOrientation('landscape')` (Android, en plein écran), `setFullscreen(true)`, `copy($texte)` (presse-papier),
`share($texte, $url)`, `enableMotion()` puis `tilt()` ([x, y] dans -1..1) et `motion()` (m/s²), `battery()`,
`isMobile()`, `isStandalone()` (lancé comme appli installée).

Le navigateur exige parfois un geste de l'utilisateur (plein écran, permission des capteurs sur iOS,
presse-papier) : la page exécute alors l'action au prochain tap ou à la prochaine touche.

## HTTP asynchrone — `$this->engine->http()`

```php
$this->engine->http()->get('https://exemple.org/scores.json')
    ->then(fn (HttpResponse $r) => $this->scores = $r->ok() ? $r->json() : [],
           fn (\Throwable $e) => $this->erreur = $e->getMessage());
$this->engine->http()->post($url, ['nom' => 'AAA', 'score' => 1200]);   // tableau = JSON
```

La réponse arrive à une frame suivante ; la frame n'attend jamais. Toute réponse HTTP, 404 et 500
compris, donne une `HttpResponse` (`status`, `ok()`, `json()`, `header()`, `body`). Seule l'absence de réponse
donne une `HttpError` (réseau, délai dépassé, CORS).

- Navigateur : `fetch()`. Le serveur doit autoriser l'origine de la page (CORS).
- Desktop : `curl_multi`, ou des sockets non bloquants quand curl manque (runtime async). Seule la
  résolution DNS reste bloquante (quelques ms).

## PWA (jeu installable, hors ligne) — `$this->engine->pwa()`

`pwa: true` dans project.yaml, ou bien
`pwa: {name, short_name, display: fullscreen, orientation, theme_color, background_color}`.

`bin/jumphp build` ajoute alors au jeu :

- `manifest.webmanifest` ;
- deux icônes 192 et 512, tirées de `assets/icon.png` agrandi en gros pixels, ou à défaut de l'initiale du titre ;
- `sw.js`, un service worker qui met tout le build en cache à la première visite.

Une fois le cache rempli, le jeu démarre sans réseau. Il faut du https (ou localhost).

```php
$pwa = $this->engine->pwa();
if ($pwa->canInstall()) { $items[] = ['INSTALLER', null, fn () => $pwa->install()]; }   // Task : true si accepté
if ($pwa->updateReady()) { $items[] = ['METTRE A JOUR', null, fn () => $pwa->applyUpdate()]; }
```

Événements : `installable`, `installed`, `offline` (le cache est complet), `update` (un build plus récent est
téléchargé). Un nouveau build produit un nouveau service worker, qui attend son tour. `applyUpdate()` l'active
et recharge la page, après avoir sauvegardé `var/`. Sur iOS, il n'y a pas de question d'installation : le joueur passe par
Partager > « Sur l'écran d'accueil ».

## Manettes — `$this->engine->input->getGamepad()`

Les noms sont les mêmes partout (disposition « standard » du W3C, noms Xbox) :

- boutons : `a b x y lb rb lt rt select start home ls rs` ;
- croix : `up down left right` ;
- directions des sticks : `ls_left` … `rs_down`.

Dans `config/input.yaml` on écrit par exemple `pad0:a`, `pad1:start` ou `pad0:left` (la croix OU le stick
gauche). Les numéros bruts (`pad0:button3`) marchent toujours. Par défaut, confirm = `pad0:a`,
back = `pad0:b` et pause = `pad0:start`.

```php
$pad = $this->engine->input->getGamepad();
$x = $pad->axis(0, 'lx');            // -1..1, zone morte appliquée ; lx ly rx ry ; lt rt : 0..1
$pad->rumble(0, 0.8, 0.3, 0.2);      // moteur fort, faible, secondes (navigateur Chromium)
$pad->on('connect', fn (int $i, string $nom) => ...);
```

- Navigateur : l'API Gamepad, lue avant chaque frame.
- Desktop : les événements joystick de SDL, traduits par une table Xbox / PlayStation. Le binding ne
  transmet pas les « hats » : une croix vue comme un hat (la plupart des manettes Xbox sous Linux) est
  invisible sur desktop, mais le stick gauche marche.

## Tests

- `bin/jumphp test engine` : tests unitaires `tests/Http` (curl et sockets contre un `php -S` local),
  `tests/Device`, `tests/Gl` et `tests/Input/GamepadTest.php`.
- `bin/jumphp webshot hello-webgl 6 '[[1,"Enter"],[3," "]]'` : la 3D et un shader dans Chrome sans écran.
