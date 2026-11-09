<?php

namespace JumPHP\Tests\Net;

use JumPHP\Bridge\JsBridge;
use JumPHP\Http\Http;
use JumPHP\Kernel\GameConfig;
use JumPHP\Net\Ssh;
use JumPHP\Net\SshConnection;
use PHPUnit\Framework\TestCase;

final class SshTest extends TestCase
{
    private function connection(array $options, bool $browser): SshConnection
    {
        return new SshConnection(new GameConfig(['id' => 'sql']), $this->createStub(Http::class), $this->createStub(JsBridge::class), 'db.internal', 3306, $options, 20.0, $browser);
    }

    public function testTheArgumentsPipeTheStreamThroughTheSshServer(): void
    {
        $args = $this->connection(['sshHost' => 'bastion.example.com', 'sshUser' => 'me', 'sshPort' => 2222], true)
            ->arguments('bastion.example.com', '/home/user/.ssh/id', false);
        self::assertSame(['-W', 'db.internal:3306', '-p', '2222', '-l', 'me'], array_slice($args, 0, 6));
        self::assertContains('BatchMode=yes', $args);
        self::assertContains('UserKnownHostsFile=/home/user/.ssh/known_hosts', $args);
        self::assertSame(['-i', '/home/user/.ssh/id'], array_slice($args, array_search('-i', $args), 2));
        self::assertSame(['--', 'bastion.example.com'], array_slice($args, -2));
    }

    public function testAFailureTellsWhichServerWasReachedAndWhatItAccepts(): void
    {
        $connection = $this->connection(['sshHost' => 'bastion.example.com'], true);
        $add = new \ReflectionMethod($connection, 'addStderr');
        $reason = new \ReflectionMethod($connection, 'reason');
        $add->invoke($connection, "debug1: Connecting to bastion.example.com [51.75.179.153] port 22.\ndebug1: Remote protocol version 2.0, remote software version OpenSSH_9.2\n"
            . "debug1: Server host key: ssh-ed25519 SHA256:abcdef\ndebug1: Offering public key: /home/user/id ED25519 SHA256:mykey explicit\n"
            . "debug1: Authentications that can continue: publickey,password\nroot@bastion.example.com: Permission denied (publickey,password).\n");
        $text = $reason->invoke($connection, 255);
        self::assertStringContainsString('Permission denied (publickey,password)', $text);
        self::assertStringContainsString('reached 51.75.179.153:22', $text);
        self::assertStringContainsString('server OpenSSH_9.2', $text);
        self::assertStringContainsString('host key ssh-ed25519 SHA256:abcdef', $text);
        self::assertStringContainsString('key offered ED25519 SHA256:mykey', $text);
        self::assertStringNotContainsString('debug1:', $text);
    }

    public function testASecretTurnsBatchModeOffAndAnIpv6TargetIsBracketed(): void
    {
        $connection = new SshConnection(new GameConfig(['id' => 'sql']), $this->createStub(Http::class), $this->createStub(JsBridge::class), '2001:db8::5', 3306, [], 20.0, false);
        $args = $connection->arguments('bastion', '', true);
        self::assertSame('[2001:db8::5]:3306', $args[1]);
        self::assertContains('BatchMode=no', $args);
        self::assertNotContains('-i', $args);
    }

    public function testWithoutAnSshHostItFailsAtOnce(): void
    {
        $ssh = new Ssh(new GameConfig(['id' => 'sql']), $this->createStub(Http::class), $this->createStub(JsBridge::class));
        $connection = $ssh->connect('db.internal', 3306, []);
        $closed = null;
        $connection->on('close', function (?string $why) use (&$closed): void { $closed = $why; });
        $ssh->poll();                 // a failure while starting is told at the first poll, once the caller has its listeners
        self::assertTrue($connection->isClosed());
        self::assertSame('No SSH host.', $closed);
    }
}
