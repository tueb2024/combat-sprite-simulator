<?php

namespace JumPHP\Net;

/** What Tcp is for a plain connection, for one through an SSH server (Ssh::via()). */
final class SshConnector
{
    /** @param array<string, mixed> $options */
    public function __construct(private Ssh $ssh, private array $options)
    {
    }

    public function connect(string $host, int $port, float $timeout = 30.0): SshConnection
    {
        return $this->ssh->connect($host, $port, $this->options, max($timeout, 30.0));
    }
}
