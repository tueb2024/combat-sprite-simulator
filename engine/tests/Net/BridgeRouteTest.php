<?php

namespace JumPHP\Tests\Net;

use Evenement\EventEmitter;
use JumPHP\Async\Task;
use JumPHP\Http\Http;
use JumPHP\Http\HttpResponse;
use JumPHP\Kernel\GameConfig;
use JumPHP\Net\BridgeRoute;
use JumPHP\Net\NoRuntime;
use JumPHP\Net\RuntimeLink;
use JumPHP\Net\TcpConnection;
use PHPUnit\Framework\TestCase;

final class BridgeRouteTest extends TestCase
{
    private const SERVER = 'wss://tcp.jumphp.net/ws';

    private static function runtime(string $state): RuntimeLink
    {
        return new class($state) extends EventEmitter implements RuntimeLink {
            public function __construct(private string $state)
            {
            }

            public function state(): string { return $this->state; }
            public function bridgeUrl(): string { return 'ws://127.0.0.1:47821/v1'; }
            public function check(): Task { return new Task(); }
            public function pair(string $app, string $title): Task { return new Task(); }
        };
    }

    public function testServerModeAlwaysUsesTheServer(): void
    {
        foreach ([RuntimeLink::READY, RuntimeLink::MISSING, RuntimeLink::RUNNING] as $state) {
            self::assertSame(self::SERVER, BridgeRoute::resolve('server', self::runtime($state), self::SERVER));
        }
    }

    public function testAutoModeUsesTheRuntimeOnlyWhenReady(): void
    {
        self::assertSame('ws://127.0.0.1:47821/v1', BridgeRoute::resolve('auto', self::runtime(RuntimeLink::READY), self::SERVER));
        foreach ([RuntimeLink::RUNNING, RuntimeLink::MISSING, RuntimeLink::UNKNOWN, RuntimeLink::CHECKING, RuntimeLink::REFUSED, RuntimeLink::UNSUPPORTED] as $state) {
            self::assertSame(self::SERVER, BridgeRoute::resolve('auto', self::runtime($state), self::SERVER), $state);
        }
    }

    public function testLocalModeUsesTheRuntimeOrSaysWhyNot(): void
    {
        self::assertSame('ws://127.0.0.1:47821/v1', BridgeRoute::resolve('local', self::runtime(RuntimeLink::READY), self::SERVER));
        // running but not authorised yet: the runtime itself asks the user when the connection is created
        self::assertSame('ws://127.0.0.1:47821/v1', BridgeRoute::resolve('local', self::runtime(RuntimeLink::RUNNING), self::SERVER));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the JumPHP runtime is not available (state: missing)');
        BridgeRoute::resolve('local', self::runtime(RuntimeLink::MISSING), self::SERVER);
    }

    public function testWithoutTheExtensionTheServerIsUsed(): void
    {
        self::assertSame(self::SERVER, BridgeRoute::resolve('auto', new NoRuntime(), self::SERVER));
        self::assertSame(RuntimeLink::UNSUPPORTED, (new NoRuntime())->state());
    }

    public function testTheModeComesFromTheProjectAndTheEnvironmentWins(): void
    {
        self::assertSame('auto', (new GameConfig())->netMode());
        self::assertSame('local', (new GameConfig(['net_mode' => 'local']))->netMode());
        self::assertSame('server', (new GameConfig(['net_mode' => ' SERVER ']))->netMode());
        self::assertSame('auto', (new GameConfig(['net_mode' => 'nonsense']))->netMode());
        putenv('JUMPHP_NET_MODE=server');
        try {
            self::assertSame('server', (new GameConfig(['net_mode' => 'local']))->netMode());
        } finally {
            putenv('JUMPHP_NET_MODE');
        }
    }

    public function testALocalModeConnectionFailsClearlyWithoutARuntime(): void
    {
        $http = $this->createStub(Http::class);
        $task = new Task();
        $task->settle(new HttpResponse(200, json_encode(['ticket' => 't', 'bridge' => self::SERVER]) ?: ''));
        $http->method('post')->willReturn($task);
        $connection = new TcpConnection(new GameConfig(['id' => 'x', 'net_mode' => 'local']), $http, 'db.lan', 3306, 5.0, true, 'tcp', fn () => self::runtime(RuntimeLink::MISSING));
        $connection->start();
        $error = null;
        $connection->on('error', function (string $m) use (&$error): void { $error = $m; });
        $connection->poll();
        self::assertSame('the JumPHP runtime is not available (state: missing); install and start it, or set net_mode to auto or server', $error);
        self::assertTrue($connection->isClosed());
    }
}
