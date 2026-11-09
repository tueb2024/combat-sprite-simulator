<?php

namespace JumPHP\Tests\Net;

use JumPHP\Http\Http;
use JumPHP\Kernel\GameConfig;
use JumPHP\Net\Tcp;
use JumPHP\Net\TcpConnection;
use PHPUnit\Framework\TestCase;

final class TcpTest extends TestCase
{
    public function testADesktopConnectionTalksToAServerWithoutBlocking(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server, (string)$error);
        $port = (int)substr(strrchr((string)stream_socket_get_name($server, false), ':'), 1);

        $tcp = new Tcp(new GameConfig(['id' => 'sql']), $this->createStub(Http::class));
        $connection = $tcp->connect('127.0.0.1', $port);
        $events = [];
        $connection->on('open', function () use (&$events): void { $events[] = 'open'; });
        $connection->on('data', function (string $d) use (&$events): void { $events[] = "data:$d"; });
        $connection->on('close', function (?string $why) use (&$events): void { $events[] = 'close'; });
        $connection->write('ping');                   // before it is open: kept, sent at once when it opens

        $client = null;
        for ($i = 0; $i < 200 && !in_array('data:echo:ping', $events, true); $i++) {
            $tcp->poll();
            if ($client === null && ($client = @stream_socket_accept($server, 0)) !== false) {
                stream_set_blocking($client, false);
            }
            if ($client) {
                $got = fread($client, 100);
                if ($got) {
                    fwrite($client, "echo:$got");
                }
            }
            usleep(2000);
        }
        self::assertSame(['open', 'data:echo:ping'], $events);
        fclose($client);
        for ($i = 0; $i < 100 && !$connection->isClosed(); $i++) {
            $tcp->poll();
            usleep(2000);
        }
        self::assertSame(TcpConnection::CLOSED, $connection->state());
        self::assertSame('close', end($events));
    }

    public function testARefusedConnectionReportsAnErrorAndCloses(): void
    {
        $tcp = new Tcp(new GameConfig(['id' => 'sql']), $this->createStub(Http::class));
        $connection = $tcp->connect('127.0.0.1', 1, 1.0);
        $error = null;
        $connection->on('error', function (string $m) use (&$error): void { $error = $m; });
        for ($i = 0; $i < 600 && !$connection->isClosed(); $i++) {
            $tcp->poll();
            usleep(3000);
        }
        self::assertTrue($connection->isClosed());
    }
}
