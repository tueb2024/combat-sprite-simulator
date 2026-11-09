<?php

namespace JumPHP\Net;

/**
 * UDP for applications (DNS, NTP, game servers...), never blocking: `$c = $engine->udp()->open($host, $port)`; the events of TcpConnection, but
 * every `data` is ONE datagram and every `write()` sends one. Desktop: a plain UDP socket. Browser: the playground's TCP bridge, for an
 * application the administrator approved for UDP (docs/tcp.md): any public address and port, 300 datagrams a second at most.
 */
final class Udp
{
    public function __construct(private Tcp $tcp)
    {
    }

    public function open(string $host, int $port, float $timeout = 10.0): TcpConnection
    {
        return $this->tcp->connect($host, $port, $timeout, 'udp');
    }
}
