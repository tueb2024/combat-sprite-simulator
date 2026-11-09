<?php

namespace JumPHP\Runtime;

use Evenement\EventEmitter;
use JumPHP\Async\Task;
use JumPHP\Bridge\JsBridge;
use JumPHP\Kernel\GameConfig;
use JumPHP\Kernel\Platform;
use JumPHP\Net\RuntimeLink;

/**
 * The JumPHP runtime as the application sees it (`$engine->runtime()`): the small program on the user's computer that lets this
 * browser application connect from their own IP and network, with their consent (docs/runtime.md, docs/runtime-protocol.md).
 *
 *   $runtime = $engine->runtime();
 *   $runtime->state();                       // unknown | checking | missing | running | ready | refused | unsupported
 *   $runtime->check()->then(fn (string $state) => ...);
 *   $runtime->pair($id, $title)->then(fn (bool $granted) => ...);      // the runtime's own native dialog asks the user
 *   $runtime->on('change', fn (string $state) => ...);
 *
 * The page does the WebSocket work (engine/web/jumphp-runtime.js: ports 47820 to 47829, 400 ms each); this class keeps the state from the
 * page's 'runtime' events. Only active in the browser (`Platform::isWasm()`): everywhere else the state stays `unsupported`.
 */
final class RuntimeClient extends EventEmitter implements RuntimeLink
{
    /** a probe that is not answered by then: nothing listens (10 ports x 400 ms, plus the page's time) */
    private const PROBE_TIMEOUT = 6.0;
    /** the browser is asking the user for the local network permission: the probe waits for the answer (the page waits 60 s) */
    private const ASKING_TIMEOUT = 65.0;
    private const PAIR_TIMEOUT = 40.0;

    private string $state;
    private bool $active;
    private ?int $port = null;
    private ?string $version = null;
    private ?string $os = null;
    private ?string $browserOs = null;
    /** @var list<Task> */
    private array $checks = [];
    /** @var list<Task> */
    private array $pairs = [];
    private float $probeLeft = 0.0;
    private float $pairLeft = 0.0;
    private bool $auto = false;
    private bool $autoPaired = false;
    /** the user said "this time" (or "always") in this page: a later look that finds the runtime not listing the app (a one-time pass) stays green */
    private bool $passed = false;
    private float $retryLeft = 0.0;
    /** the runtime is looked for again this often (seconds) while `auto`: quickly while it is missing / blocked, calmly otherwise (it may stop, start, be allowed later) */
    private const RETRY_EVERY = 8.0;
    private const WATCH_EVERY = 20.0;
    /** the user chose the server's proxy even though the runtime is available (the switch of the badge) */
    private bool $useServer = false;
    /** the Android app's Firebase token (to wake it) and what to do to wake it: set by the engine */
    private string $pushToken = '';
    private ?\Closure $waker = null;
    private float $clock = 0.0;
    private float $lastWake = -1000.0;

    /** @param bool|null $active null: in a browser only (Platform::isWasm()); tests say true */
    public function __construct(private JsBridge $bridge, private GameConfig $config, ?bool $active = null)
    {
        $this->active = $active ?? Platform::isWasm();
        $this->state = $this->active ? self::UNKNOWN : self::UNSUPPORTED;
        $bridge->on('runtime', fn (array $e) => $this->pageEvent($e));
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function state(): string
    {
        return $this->state;
    }

    /** ws://127.0.0.1:<port>/v1 (47820 when it was not found yet). */
    public function bridgeUrl(): string
    {
        return 'ws://127.0.0.1:' . ($this->port ?? 47820) . '/v1';
    }

    /** The runtime's version (from its hello), null until it answered. */
    public function version(): ?string
    {
        return $this->version;
    }

    /** The system the runtime runs on, else the browser's (linux | windows | macos | android | ios | other), null when unknown. */
    public function os(): ?string
    {
        return $this->os ?? $this->browserOs;
    }

    /** Looks for the runtime. Task: the state once known (the state is kept while it looks again, so a badge does not flicker). */
    public function check(): Task
    {
        $task = new Task();
        if (!$this->active) {
            $task->settle(self::UNSUPPORTED);
            return $task;
        }
        $this->checks[] = $task;
        if ($this->probeLeft <= 0.0) {
            $this->probeLeft = self::PROBE_TIMEOUT;
            if ($this->state === self::UNKNOWN) {
                $this->setState(self::CHECKING);
            }
            $this->bridge->send('runtime', ['op' => 'probe']);
        }
        return $task;
    }

    /**
     * No click needed: looks for the runtime now, looks again every few seconds while it is missing (started later, restarted),
     * and, once found but not yet allowed for this application, asks the runtime's own consent dialog ONCE per page
     * (a refusal is respected: no second dialog until the user clicks the badge). The user still decides in the runtime's window.
     */
    public function autoConnect(): void
    {
        if ($this->auto || !$this->active) {
            return;
        }
        $this->auto = true;
        $this->check();
    }

    /** A connection through the runtime failed (or the page came back to the foreground): look again now. */
    public function reportFailure(): void
    {
        if ($this->auto) {
            $this->check();
        }
    }

    /** The token of the phone's runtime app (its `hello` gives it): the page keeps it to wake the app later. */
    public function setPushToken(string $token): void
    {
        $this->pushToken = $token;
    }

    public function pushToken(): string
    {
        return $this->pushToken;
    }

    /** What to do to wake the phone's app with its token (the engine: ask the server, which asks Firebase). */
    public function setWaker(?\Closure $waker): void
    {
        $this->waker = $waker;
    }

    /** The runtime of this phone does not answer and its app is known: the server wakes it (at most once every 90 seconds). */
    private function wakeIfAsleep(): void
    {
        if ($this->state === self::MISSING && $this->waker !== null && $this->pushToken !== '' && $this->browserOs === 'android' && $this->clock - $this->lastWake >= 90.0) {
            $this->lastWake = $this->clock;
            ($this->waker)($this->pushToken);
        }
    }

    /** The user's choice: the server's bridge (proxy) for the new connections, even when the runtime is available. */
    public function setUseServer(bool $on): void
    {
        if ($this->useServer !== $on) {
            $this->useServer = $on;
            $this->emit('change', [$this->state]);
        }
    }

    public function usesServer(): bool
    {
        return $this->useServer;
    }

    /** Asks the user (native dialog of the runtime) to let this application use their network. Task: bool granted. */
    public function pair(string $app, string $title): Task
    {
        $task = new Task();
        if (!$this->active || !in_array($this->state, [self::RUNNING, self::REFUSED], true)) {
            $task->settle(false);
            return $task;
        }
        $this->pairs[] = $task;
        $this->pairLeft = self::PAIR_TIMEOUT;
        $this->bridge->send('runtime', ['op' => 'pair', 'app' => $app, 'title' => $title]);
        return $task;
    }

    /**
     * Android: opens the JumPHP runtime app (it turns its relay on); if it is not installed the browser goes to $fallback (the install guide
     * page). Call it from a tap. Does nothing on other systems.
     */
    public function wake(string $fallback = ''): void
    {
        if ($this->active) {
            $this->bridge->send('runtime', ['op' => 'wake', 'fallback' => $fallback]);
        }
    }

    /** Pair this very application (project.yaml id and title). */
    public function pairThisApp(): Task
    {
        return $this->pair($this->config->id(), $this->config->title());
    }

    /** Time passes (the extension calls it each frame): a probe nobody answers means "missing". */
    public function update(float $dt): void
    {
        $this->clock += $dt;
        if ($this->auto && $this->retryLeft > 0.0 && ($this->retryLeft -= $dt) <= 0.0) {
            $this->retryLeft = 0.0;
            $this->check();
        }
        if ($this->probeLeft > 0.0 && ($this->probeLeft -= $dt) <= 0.0) {
            $this->probeLeft = 0.0;
            $this->found(self::MISSING);
        }
        if ($this->pairLeft > 0.0 && ($this->pairLeft -= $dt) <= 0.0) {
            $this->pairLeft = 0.0;
            $this->paired(false);
        }
    }

    /** @param array<string, mixed> $e */
    private function pageEvent(array $e): void
    {
        if (!$this->active) {
            return;
        }
        switch ((string)($e['ev'] ?? '')) {
            case 'env':
                $this->browserOs = is_string($e['os'] ?? null) ? $e['os'] : null;
                break;
            case 'hello':
                $this->port = isset($e['port']) ? (int)$e['port'] : $this->port;
                $this->version = is_string($e['runtime'] ?? null) ? $e['runtime'] : $this->version;
                $this->os = is_string($e['os'] ?? null) ? $e['os'] : $this->os;
                if (is_string($e['push'] ?? null) && $e['push'] !== '' && $e['push'] !== $this->pushToken) {
                    $this->pushToken = $e['push'];
                    $this->emit('push', [$this->pushToken]);
                }
                $this->found(in_array($this->config->id(), (array)($e['apps'] ?? []), true) ? self::READY : self::RUNNING);
                break;
            case 'pending':
                // the permission question is open in the browser: not "missing", wait for the answer (hello / blocked / missing)
                if ($this->probeLeft > 0.0) {
                    $this->probeLeft = self::ASKING_TIMEOUT;
                }
                break;
            case 'missing':
                $this->found(self::MISSING);
                break;
            case 'blocked':
                $this->found(self::UNSUPPORTED);
                break;
            case 'paired':
                $this->paired((bool)($e['granted'] ?? false));
                break;
            case 'error':
                if (($e['op'] ?? '') === 'pair') {
                    $this->paired(false);
                }
                break;
        }
    }

    private function found(string $state): void
    {
        $this->probeLeft = 0.0;
        // a refusal is remembered until the user asks again (pair) or the runtime goes away
        if ($this->state === self::REFUSED && $state === self::RUNNING) {
            $state = self::REFUSED;
        } elseif ($this->passed && $state === self::RUNNING) {
            $state = self::READY;
        }
        $this->setState($state);
        $this->wakeIfAsleep();
        $tasks = $this->checks;
        $this->checks = [];
        foreach ($tasks as $task) {
            $task->settle($this->state);
        }
        if ($this->auto) {
            // always looking: a connection that failed, a runtime started or stopped later, a browser permission given later
            $this->retryLeft = in_array($this->state, [self::MISSING, self::UNSUPPORTED], true) ? self::RETRY_EVERY : self::WATCH_EVERY;
            if ($this->state === self::RUNNING && !$this->autoPaired) {
                $this->autoPaired = true;
                $this->pairThisApp();
            }
        }
    }

    private function paired(bool $granted): void
    {
        $this->pairLeft = 0.0;
        if ($this->pairs === []) {
            return;
        }
        $this->passed = $granted;
        $this->setState($granted ? self::READY : self::REFUSED);
        $tasks = $this->pairs;
        $this->pairs = [];
        foreach ($tasks as $task) {
            $task->settle($granted);
        }
    }

    private function setState(string $state): void
    {
        if ($state === $this->state) {
            return;
        }
        $this->state = $state;
        $this->emit('change', [$state]);
    }
}
