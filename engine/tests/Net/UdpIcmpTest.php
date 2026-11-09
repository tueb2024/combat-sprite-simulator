<?php

namespace JumPHP\Tests\Net;

use JumPHP\Http\Http;
use JumPHP\Kernel\GameConfig;
use JumPHP\Net\Icmp;
use JumPHP\Net\Tcp;
use JumPHP\Net\Udp;
use PHPUnit\Framework\TestCase;

final class UdpIcmpTest extends TestCase
{
    public function testADesktopUdpSocketCarriesOneDatagramEachWay(): void
    {
        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        self::assertNotFalse($server, (string)$error);
        stream_set_blocking($server, false);
        $port = (int)substr(strrchr((string)stream_socket_get_name($server, false), ':'), 1);
        $tcp = new Tcp(new GameConfig(['id' => 'dns']), $this->createStub(Http::class));
        $connection = (new Udp($tcp))->open('127.0.0.1', $port);
        $got = [];
        $connection->on('data', function (string $d) use (&$got): void { $got[] = $d; });
        $connection->write('one');
        $connection->write('two');
        $peer = null;
        $received = [];
        for ($i = 0; $i < 200 && count($got) < 2; $i++) {
            $tcp->poll();
            while (($d = @stream_socket_recvfrom($server, 2048, 0, $peer)) !== false && $d !== '') {
                $received[] = $d;
                stream_socket_sendto($server, "echo:$d", 0, $peer);
            }
            usleep(2000);
        }
        self::assertSame(['one', 'two'], $received);
        self::assertSame(['echo:one', 'echo:two'], $got);
    }

    public function testThePingOutputIsReadForTheRoundTripAndTheTtl(): void
    {
        $ok = Icmp::parse(1, "64 bytes from 93.184.216.34: icmp_seq=1 ttl=57 time=12.3 ms\n", 'example.com');
        self::assertTrue($ok['ok']);
        self::assertSame(12.3, $ok['ms']);
        self::assertSame(57, $ok['ttl']);
        $hop = Icmp::parse(2, "From 10.0.0.1 icmp_seq=1 Time to live exceeded\n", 'example.com');
        self::assertSame(['seq' => 2, 'ok' => false, 'error' => 'ttl', 'hop' => '10.0.0.1'], $hop);
        self::assertSame('no answer', Icmp::parse(3, "100% packet loss", 'x')['error']);
    }

    /** the exact text of iputils 20240905 (`ping -c1 -t1 8.8.8.8`), and the other forms of that line */
    public function testTheRouterWhoAnsweredTimeToLiveExceededIsFoundInEveryForm(): void
    {
        $real = "PING 8.8.8.8 (8.8.8.8) 56(84) bytes of data.\nFrom 172.17.0.1 icmp_seq=1 Time to live exceeded\n\n--- 8.8.8.8 ping statistics ---\n1 packets transmitted, 0 received, +1 errors, 100% packet loss, time 0ms\n\n";
        self::assertSame(['seq' => 1, 'ok' => false, 'error' => 'ttl', 'hop' => '172.17.0.1', 'ms' => 3.5], Icmp::parse(1, $real, '8.8.8.8', 3.46));
        $hop = fn (string $line) => Icmp::parse(1, "PING x\n$line\n--- stats ---\n", 'x');
        self::assertSame('10.0.0.1', $hop('From gw.example.net (10.0.0.1) icmp_seq=1 Time to live exceeded')['hop']);
        self::assertSame('10.0.0.1', $hop('From 10.0.0.1: icmp_seq=1 Time to live exceeded')['hop']);
        self::assertSame('10.0.0.1', $hop('36 bytes from 10.0.0.1: Time to live exceeded')['hop'], 'macOS');
        self::assertSame('2001:db8::1', $hop('From 2001:db8::1 icmp_seq=1 Time exceeded: Hop limit')['hop']);
        $unreachable = $hop('From 192.168.1.1 icmp_seq=1 Destination Host Unreachable');
        self::assertSame(['unreachable', '192.168.1.1'], [$unreachable['error'], $unreachable['hop']]);
    }

    public function testTheHopsOfATraceStopAtTheHostAndDropTheSilentTail(): void
    {
        $hops = Icmp::hops([
            1 => ['seq' => 1, 'ok' => false, 'error' => 'ttl', 'hop' => '192.168.1.1', 'ms' => 0.4],
            3 => ['seq' => 3, 'ok' => true, 'ms' => 11.0, 'ttl' => 55, 'from' => '93.184.216.34'],
            2 => ['seq' => 2, 'ok' => false, 'error' => 'no answer'],
            4 => ['seq' => 4, 'ok' => true, 'ms' => 12.0, 'ttl' => 55, 'from' => '93.184.216.34'],
        ]);
        self::assertSame([
            ['hop' => 1, 'ip' => '192.168.1.1', 'ms' => 0.4, 'reached' => false],
            ['hop' => 2, 'ip' => null, 'ms' => null, 'reached' => false],
            ['hop' => 3, 'ip' => '93.184.216.34', 'ms' => 11.0, 'reached' => true],
        ], $hops);
        $never = Icmp::hops([1 => ['seq' => 1, 'ok' => false, 'error' => 'ttl', 'hop' => '10.0.0.1'], 2 => ['seq' => 2, 'ok' => false, 'error' => 'no answer'], 3 => ['seq' => 3, 'ok' => false, 'error' => 'no answer']]);
        self::assertSame([['hop' => 1, 'ip' => '10.0.0.1', 'ms' => null, 'reached' => false]], $never);
    }

    /** a fake `ping` first in the PATH: routers 10.0.0.1 and 10.0.0.2 answer "time to live exceeded", the host from the third hop on */
    public function testATraceAsksOneMoreTtlEachTimeAndEndsWhenTheHostAnswers(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('sh script');
        }
        $dir = sys_get_temp_dir() . '/fakeping-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/ping", <<<'SH'
#!/bin/sh
printf "%s\n" "$*" >> "$(dirname "$0")/calls"
while [ $# -gt 0 ]; do if [ "$1" = "-t" ] || [ "$1" = "-m" ]; then ttl=$2; fi; shift; done
case "$ttl" in
  1) echo "From 10.0.0.1 icmp_seq=1 Time to live exceeded";;
  2) echo "From gw.example (10.0.0.2) icmp_seq=1 Time to live exceeded";;
  *) echo "64 bytes from 93.184.216.34: icmp_seq=1 ttl=55 time=9.5 ms";;
esac
SH);
        chmod("$dir/ping", 0755);
        $path = getenv('PATH');
        putenv("PATH=$dir:$path");
        try {
            $icmp = new Icmp(new Tcp(new GameConfig(['id' => 'trace']), $this->createStub(Http::class)));
            $task = $icmp->trace('example.com', 30);
            for ($i = 0; $i < 600 && !$task->isDone(); $i++) {
                $icmp->poll();
                usleep(5000);
            }
            self::assertTrue($task->isDone());
            $hops = $task->result();
            self::assertSame([1, 2, 3], array_column($hops, 'hop'));
            self::assertSame(['10.0.0.1', '10.0.0.2', '93.184.216.34'], array_column($hops, 'ip'));
            self::assertSame([false, false, true], array_column($hops, 'reached'));
            self::assertSame(9.5, $hops[2]['ms']);
            self::assertCount(3, file("$dir/calls"), 'it stopped as soon as the host answered');
        } finally {
            putenv("PATH=$path");
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    public function testATraceOnTheLoopbackWorksWithTheSystemPingWhenThereIsOne(): void
    {
        if (trim((string)shell_exec('command -v ping 2>/dev/null')) === '') {
            self::markTestSkipped('no ping program here');
        }
        $out = (string)shell_exec('ping -n -c 1 -W 2 127.0.0.1 2>&1');
        if (!str_contains($out, 'bytes from')) {
            self::markTestSkipped('ping cannot send here');
        }
        $tcp = new Tcp(new GameConfig(['id' => 'trace']), $this->createStub(Http::class));
        $icmp = new Icmp($tcp);
        $task = $icmp->trace('127.0.0.1', 5);
        for ($i = 0; $i < 400 && !$task->isDone(); $i++) {
            $icmp->poll();
            usleep(10000);
        }
        self::assertTrue($task->isDone());
        self::assertSame([['hop' => 1, 'ip' => '127.0.0.1', 'ms' => $task->result()[0]['ms'], 'reached' => true]], $task->result());
    }
}
