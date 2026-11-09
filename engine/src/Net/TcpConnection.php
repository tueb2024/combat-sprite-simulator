<?php

namespace JumPHP\Net;

use Evenement\EventEmitter;
use JumPHP\Http\Http;
use JumPHP\Http\HttpResponse;
use JumPHP\Kernel\GameConfig;
use PhpWasmNetwork\Connection;
use PhpWasmNetwork\Event;
use PhpWasmNetwork\Network;

/**
 * One TCP connection, or one UDP "connection" (a socket to one address: each message is a datagram) or the pings of one host (scope
 * tcp, udp, icmp: see Tcp). Events: open, data (string $bytes), close (?string $reason: null = closed normally),
 * error (string $message, before 'close' when a connection could not be made).
 */
final class TcpConnection extends EventEmitter
{
    private const CHUNK = 60000;

    public const IDLE = 'idle';
    public const CONNECTING = 'connecting';
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    private string $state = self::IDLE;
    /** @var resource|null direct socket (desktop) */
    private $socket = null;
    private ?Connection $bridge = null;
    private string $pending = '';
    /** a failure during start() waits for the first poll(): the caller attaches its listeners after connect() returns */
    private bool $starting = false;
    private ?string $startFailure = null;
    /** @var list<string> messages written before it opened, kept apart (datagrams, ping requests) */
    private array $pendingMessages = [];
    private float $deadline = 0.0;

    public function __construct(private GameConfig $config, private Http $http, private string $host, private int $port,
        private float $timeout, private bool $viaBridge, private string $scope = 'tcp', private ?\Closure $runtime = null)
    {
    }

    public function state(): string { return $this->state; }
    public function isOpen(): bool { return $this->state === self::OPEN; }
    public function isClosed(): bool { return $this->state === self::CLOSED; }

    public function start(): void
    {
        $this->starting = true;
        try {
            $this->begin();
        } finally {
            $this->starting = false;
        }
    }

    private function begin(): void
    {
        $this->state = self::CONNECTING;
        $this->deadline = microtime(true) + $this->timeout;
        if ($this->viaBridge) {
            $this->askTicket();
            return;
        }
        $udp = $this->scope === 'udp';
        $socket = @stream_socket_client(($udp ? 'udp' : 'tcp') . "://" . (str_contains($this->host, ':') && !str_starts_with($this->host, '[') ? "[{$this->host}]" : $this->host) . ":{$this->port}", $errno, $error, 0,
            $udp ? STREAM_CLIENT_CONNECT : STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if (!$socket) {
            $this->fail($error !== '' ? $error : "cannot connect ($errno)");
            return;
        }
        stream_set_blocking($socket, false);
        $this->socket = $socket;
    }

    /** @return bool false when it cannot be sent (not open) */
    public function write(string $bytes): bool
    {
        if ($this->state === self::CLOSED || $bytes === '') {
            return $this->state !== self::CLOSED;
        }
        if ($this->state === self::CONNECTING) {
            $this->scope === 'tcp' ? $this->pending .= $bytes : $this->pendingMessages[] = $bytes;      // sent as soon as it opens
            return true;
        }
        $this->send($bytes);
        return true;
    }

    public function close(): void
    {
        if ($this->state === self::CLOSED) {
            return;
        }
        $this->finish(null);
    }

    public function poll(): void
    {
        if ($this->startFailure !== null) {
            $message = $this->startFailure;
            $this->startFailure = null;
            $this->fail($message);
            return;
        }
        if ($this->state === self::CLOSED) {
            return;
        }
        if ($this->state === self::CONNECTING && microtime(true) > $this->deadline) {
            $this->fail('connection timed out');
            return;
        }
        if ($this->socket !== null) {
            $this->pollSocket();
        } elseif ($this->bridge !== null) {
            $this->pollBridge();
        }
    }

    private function pollSocket(): void
    {
        if ($this->scope === 'udp') {
            if ($this->state === self::CONNECTING) {
                $this->opened();            // nothing to wait for: datagrams, no connection
            }
            while ($this->socket !== null && ($datagram = @fread($this->socket, 65535)) !== false && $datagram !== '') {
                $this->emit('data', [$datagram]);     // one datagram per message
            }
            return;
        }
        if ($this->state === self::CONNECTING) {
            if (@stream_socket_get_name($this->socket, true) === false) {
                // not connected yet; a socket that becomes writable without being connected has failed (refused, unreachable)
                $read = null;
                $write = [$this->socket];
                $except = null;
                if (@stream_select($read, $write, $except, 0) === 1) {
                    $this->fail('connection refused');
                }
                return;
            }
            $this->opened();
        }
        $data = @fread($this->socket, 65536);
        if ($data !== '' && $data !== false) {
            $this->emit('data', [$data]);
        } elseif ($data === false || feof($this->socket)) {
            $this->finish('connection closed');
        }
    }

    private function pollBridge(): void
    {
        foreach ($this->bridge->poll() as $event) {
            switch ($event->type) {
                case Event::ROOM:
                    $this->opened();
                    break;
                case Event::MESSAGE:
                    $this->emit('data', [$event->data]);
                    break;
                case Event::PEER_LEAVE:
                    $this->finish('connection closed');
                    return;
                case Event::ERROR:
                    $info = $event->info();
                    $this->fail((string)($info['message'] ?? $event->data));
                    return;
                case Event::CLOSE:
                    $this->finish($this->state === self::OPEN ? 'connection closed' : 'bridge closed');
                    return;
            }
        }
    }

    private function askTicket(): void
    {
        try {
            $this->http->post($this->config->tcpTicketUrl(), ['slug' => $this->config->id(), 'host' => $this->host, 'port' => $this->port, 'scope' => $this->scope])
                ->then(function (HttpResponse $r): void {
                    try {
                        $data = $r->json();
                    } catch (\JsonException) {
                        $data = null;            // not JSON (a proxy's "404 page not found" during a restart): the status below says why
                    }
                    if ($this->state !== self::CONNECTING) {
                        return;
                    }
                    if (!$r->ok() || !is_array($data) || !isset($data['ticket'], $data['bridge'])) {
                        $this->fail(is_array($data) && isset($data['error']) ? (string)$data['error'] : 'the TCP bridge refused (HTTP ' . $r->status . ')');
                        return;
                    }
                    try {
                        $this->bridge = Network::connect($this->bridgeUrl((string)$data['bridge']), ['transport' => 'ws']);
                        $this->bridge->createRoom(2, ['tcp_ticket' => (string)$data['ticket']]);
                    } catch (\RuntimeException $e) {
                        $this->bridge = null;
                        $this->fail($e->getMessage());          // net_mode local without a usable runtime
                    }
                }, fn (\Throwable $e) => $this->fail('cannot reach the playground: ' . $e->getMessage()));
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    /** The server's bridge from the ticket answer, or the user's runtime, by the network mode (BridgeRoute). @throws \RuntimeException */
    private function bridgeUrl(string $serverBridge): string
    {
        return BridgeRoute::forConfig($this->config, $this->runtime !== null ? ($this->runtime)() : new NoRuntime(), $serverBridge);
    }

    private function opened(): void
    {
        if ($this->state !== self::CONNECTING) {
            return;
        }
        $this->state = self::OPEN;
        $this->emit('open');
        if ($this->pending !== '') {
            $this->send($this->pending);
            $this->pending = '';
        }
        foreach ($this->pendingMessages as $message) {
            $this->send($message);
        }
        $this->pendingMessages = [];
    }

    private function send(string $bytes): void
    {
        if ($this->socket !== null && $this->scope === 'udp') {
            @fwrite($this->socket, $bytes);          // one datagram
        } elseif ($this->socket !== null) {
            while ($bytes !== '') {
                $n = @fwrite($this->socket, $bytes);
                if ($n === false) {
                    $this->finish('write failed');
                    return;
                }
                if ($n === 0) {                // buffer full: a short wait, the frame stays light for ordinary queries
                    usleep(1000);
                }
                $bytes = (string)substr($bytes, $n);
            }
        } elseif ($this->bridge !== null) {
            foreach ($this->scope === 'tcp' ? str_split($bytes, self::CHUNK) : [$bytes] as $part) {      // a datagram / a ping request: one message
                $this->bridge->send($part, 0);
            }
        }
    }

    private function fail(string $message): void
    {
        if ($this->starting) {
            $this->startFailure = $message;
            return;
        }
        $r = $this->runtime !== null ? ($this->runtime)() : null;
        if ($r instanceof \JumPHP\Runtime\RuntimeClient) {
            $r->reportFailure();                // the runtime may be gone: the badge looks again at once
        }
        $this->emit('error', [$message]);
        $this->finish($message);
    }

    private function finish(?string $reason): void
    {
        if ($this->state === self::CLOSED) {
            return;
        }
        $this->state = self::CLOSED;
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->bridge?->close();
        $this->bridge = null;
        $this->emit('close', [$reason]);
    }
}
