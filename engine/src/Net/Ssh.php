<?php

namespace JumPHP\Net;

use JumPHP\Http\Http;
use JumPHP\Kernel\GameConfig;
use JumPHP\Kernel\Platform;

/**
 * SSH for applications (a SQL client through a bastion...): the OpenSSH client's own `ssh -W host:port` as a pipe, never blocking.
 *
 *   $c = $engine->ssh()->connect('db.internal', 3306, ['sshHost' => 'bastion.example.com', 'sshUser' => 'me', 'sshKey' => $privateKeyText]);
 *   $c->on('open', ...); $c->on('data', fn (string $bytes) => ...); $c->on('close', fn (?string $why) => ...); $c->write($bytes);
 *
 * It has the TcpConnection's events, so what takes a Tcp (`connect($host, $port, $timeout)`) also takes `$engine->ssh()->via($options)`.
 *
 * Desktop: the system's `ssh` (your ~/.ssh, the agent, ProxyJump...). Browser: OpenSSH compiled to WebAssembly (docker/ssh-wasm,
 * bin/jumphp ssh:build) in a Web Worker, piped to this code: its network is the playground's TCP bridge, and only for an
 * application the administrator approved for SSH (a separate right from TCP: docs/tcp.md). In the browser there is no agent or
 * ssh config: the key text (sshKey) and its passphrase, or a password (sshPassword), come from the application.
 *
 * Options: sshHost, sshPort (22), sshUser, sshKey (browser: the private key text; desktop: a file), sshPassphrase, sshPassword,
 * knownHosts (the known_hosts text: the browser checks the host key against it, and a new host is added on 'knownHosts' event),
 * sshOptions (extra `-o` values).
 */
final class Ssh
{
    /** @var list<SshConnection> */
    private array $connections = [];

    public function __construct(private GameConfig $config, private Http $http, private \JumPHP\Bridge\JsBridge $bridge, private ?\Closure $runtime = null)
    {
    }

    /** Whether the browser's WebAssembly client is used (no `ssh` program to start). */
    public static function inBrowser(): bool
    {
        return Platform::isWasm();
    }

    /** @param array<string, mixed> $options */
    public function connect(string $host, int $port, array $options = [], float $timeout = 30.0): SshConnection
    {
        $connection = new SshConnection($this->config, $this->http, $this->bridge, $host, $port, $options, $timeout, self::inBrowser(), $this->runtime);
        $this->connections[] = $connection;
        $connection->start();
        return $connection;
    }

    /** A connector with Tcp's `connect($host, $port, $timeout)`: the connection goes through this SSH server. */
    public function via(array $options): SshConnector
    {
        return new SshConnector($this, $options);
    }

    public function poll(): void
    {
        foreach ($this->connections as $i => $connection) {
            $connection->poll();
            if ($connection->isClosed()) {
                unset($this->connections[$i]);
            }
        }
        $this->connections = array_values($this->connections);
    }

    public function closeAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
    }
}
