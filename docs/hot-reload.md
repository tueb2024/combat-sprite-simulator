# Hot reload (remote games in the browser)

A page that shows a remote game (`docs/remote.md`) in an iframe can push changed files into the running game
without reloading it. The JumPHP playground does it in its online editor and sandbox (⚡ Live).

## The protocol

1. The page saves the files where the game's manifest points (the manifest is asked for again, `no-store`).
2. It sends `{type: 'jumphp:hot'}` to the iframe (`iframe.contentWindow.postMessage(...)`).
3. The engine (`engine/web/jumphp.js`) fetches the manifest, writes the files whose hash changed into `/game`,
   deletes the removed ones, and decides (`engine/src/Debug/HotReload.php`):

| What changed | What happens | `result` |
|---|---|---|
| nothing the game reads (`*.md`, `*.txt`, `tests/`, `site/`, `docs/`) | nothing | `assets` or `none` |
| pictures, sounds | reloaded in place (textures, sfx, the music played again) | `assets` |
| `config/lang.yaml`, `config/input.yaml` | translations, keys reloaded | `assets` |
| another data file (a level, a `.json`...) | the playing scene entered again, same parameters | `scene` |
| `*.php`, `project.yaml`, `services.yaml`, `composer.json` (namespaces) | PHP restarted in the same engine | `restart` |

4. It answers `{type: 'jumphp:hot-done', game, result, changed, message?}`; `result: 'error'` when the game
   did not start again (`message`: PHP's error first). The next `jumphp:hot` tries again.

An engine older than 0.3.0 does not answer: after a few seconds without `jumphp:hot-done`, reload the iframe.

## The restart

`HotReload::save()` writes the playing scene, its parameters and, for a scene implementing
`JumPHP\Scene\HotReloadable`, its `hotState()` to `/tmp/jumphp-hot.json`; `HotReload::shutdown()` closes the
sound, the network and SDL. Then `phpw_restart()` (`docker/engine-wasm/source/phpw.c`) ends the PHP request and
starts a new one in the same WebAssembly instance: no download, no compilation, the canvas kept. The boot
(`Engine`) finds the saved scene (`HotReload::pending()`), starts it instead of the start scene, then calls
`hotRestore()` with the state. A boot that fails (a syntax error) leaves the engine waiting for the next files.

```php
final class PlayScene extends AbstractScene implements HotReloadable
{
    public function hotState(): array { return ['room' => $this->room, 'score' => $this->score]; }
    public function hotRestore(array $state): void { ['room' => $this->room, 'score' => $this->score] = $state; }
}
```

Plain values only: they cross from one version of the code to the next (JSON).
