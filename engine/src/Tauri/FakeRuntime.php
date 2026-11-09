<?php

namespace JumPHP\Tauri;

/**
 * A runtime without a window, for tests (AppTestCase) and headless checks: events are given with push(),
 * the commands PHP sends are kept in $sent. run() plays frames until the app quits or $maxFrames.
 */
final class FakeRuntime implements NativeRuntime
{
    /** @var list<array<string, mixed>> every command sent, in order */
    public array $sent = [];
    /** @var list<array<string, mixed>> */
    private array $queue = [];
    private ?\Closure $frame = null;
    private ?int $exitCode = null;
    public array $config = [];

    public function __construct(public int $maxFrames = 600)
    {
    }

    public function run(array $config, \Closure $frame): int
    {
        $this->config = $config;
        $this->frame = $frame;
        $this->push(['type' => 'ready']);
        for ($i = 0; $i < $this->maxFrames && $this->exitCode === null; $i++) {
            $frame();
        }
        return $this->exitCode ?? 0;
    }

    /** Starts without looping (tests drive the frames with frame()). */
    public function start(array $config, \Closure $frame): void
    {
        $this->config = $config;
        $this->frame = $frame;
        $this->push(['type' => 'ready']);
    }

    /** One frame (after start()). */
    public function frame(): void
    {
        if ($this->frame === null) {
            throw new \LogicException('FakeRuntime: start() first');
        }
        ($this->frame)();
    }

    /** An event, as the native side would send it: ['type' => 'invoke', 'id' => 1, 'name' => ..., 'payload' => ...]. */
    public function push(array $event): void
    {
        $this->queue[] = $event;
    }

    public function poll(): array
    {
        $events = $this->queue;
        $this->queue = [];
        return $events;
    }

    public function send(array $command): void
    {
        $this->sent[] = $command;
        if (($command['cmd'] ?? '') === 'app.exit') {
            $this->exitCode = (int)($command['code'] ?? 0);
        }
    }

    /** the pictures handed over (embedded games): how many, and the last one */
    public int $pictures = 0;
    public string $lastPicture = '';

    public function picture(string $bmp): void
    {
        $this->pictures++;
        $this->lastPicture = $bmp;
    }

    public function version(): string
    {
        return 'fake';
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /** @return list<array<string, mixed>> the commands named $cmd */
    public function sentOf(string $cmd): array
    {
        return array_values(array_filter($this->sent, static fn (array $c): bool => ($c['cmd'] ?? '') === $cmd));
    }
}
