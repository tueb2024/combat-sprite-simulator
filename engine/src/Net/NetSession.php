<?php

namespace JumPHP\Net;

use Evenement\EventEmitter;
use JumPHP\Kernel\GameConfig;
use PhpWasmNetwork\Connection;
use PhpWasmNetwork\Event;
use PhpWasmNetwork\Network;

/**
 * Online play through php-wasm-network: the same code in the browser (WebSocket / WebRTC through the
 * engine's wasmnet extension) and on desktop (WebSocket in plain PHP). Never blocks the frame, except
 * the first connection on desktop (up to 5 s).
 *
 * Rooms: one player creates a room (gets a 4-letter code), the others join with the code, or quickJoin()
 * pairs players automatically. The creator is the host (peer 0): make the host the authority of the game.
 *
 *   $net = $engine->net();
 *   $net->on('room', fn (array $info) => ...);              // in a room: code, my peer id, peers
 *   $net->on('peerJoin', fn (int $peer) => ...);
 *   $net->on('message', fn (int $from, int $type, string $payload) => ...);
 *   $net->connect();  $net->host(2);   // or $net->join('ABCD') / $net->quickJoin(2)
 *   // every frame: $net->poll();      // the engine does it while the session is open
 *   $net->send(MSG_STATE, pack('gg', $x, $y), reliable: false);
 *
 * Events: open, close (string $reason), error (string $code, string $message), room (array $info),
 * peerJoin (int $peer), peerLeave (int $peer), host (int $peer), message (int $from, int $type, string $payload),
 * pong (int $ms).
 */
class NetSession extends EventEmitter
{
    private ?Connection $connection = null;
    private ?array $room = null;
    private int $peerId = -1;
    private int $hostId = -1;
    /** @var int[] the other peers */
    private array $peers = [];
    /** @var callable|null what to do once connected */
    private $onOpen = null;
    private ?int $ping = null;

    public function __construct(private GameConfig $config)
    {
    }

    public static function isAvailable(): bool
    {
        return class_exists(Network::class);
    }

    /** @param array $options php-wasm-network options, e.g. ['transport' => 'ws'] to disable WebRTC */
    public function connect(?string $url = null, array $options = []): void
    {
        $this->close();
        $this->connection = Network::connect($url ?? $this->config->netUrl(), $options);
    }

    public function isConnected(): bool
    {
        return $this->connection !== null && $this->connection->state() === Connection::OPEN;
    }

    public function isActive(): bool
    {
        return $this->connection !== null && $this->connection->state() !== Connection::CLOSED;
    }

    /** Creates a room for $maxPeers players (connects first if needed). */
    public function host(int $maxPeers = 2, array $meta = [], bool $public = false): void
    {
        $meta += ['game' => $this->config->id()];
        $this->whenOpen(fn () => $this->connection->createRoom($maxPeers, $meta, $public));
    }

    public function join(string $code): void
    {
        $code = strtoupper(trim($code));
        $this->whenOpen(fn () => $this->connection->joinRoom($code));
    }

    /** Joins a public room waiting for players of this game, or creates one. */
    public function quickJoin(int $maxPeers = 2, array $meta = []): void
    {
        $meta += ['game' => $this->config->id()];
        $this->whenOpen(fn () => $this->connection->quickJoin($maxPeers, $meta));
    }

    public function leaveRoom(): void
    {
        $this->connection?->leaveRoom();
        $this->room = null;
        $this->peers = [];
    }

    /** No one else can join (the match started). */
    public function lockRoom(bool $locked = true): void
    {
        $this->connection?->lockRoom($locked);
    }

    private function whenOpen(callable $action): void
    {
        if ($this->connection === null) {
            $this->connect();
        }
        if ($this->isConnected()) {
            $action();
        } else {
            $this->onOpen = $action;
        }
    }

    /** @param int|null $to a peer id, null = everybody; $reliable false = may be lost (WebRTC), for frequent state */
    public function send(int $type, string $payload = '', ?int $to = null, bool $reliable = true): bool
    {
        if (!$this->isConnected() || $this->room === null) {
            return false;
        }
        return $this->connection->send(MessageCodec::encode($type, $payload), $to, $reliable);
    }

    public function sendJson(int $type, array $data, ?int $to = null, bool $reliable = true): bool
    {
        return $this->send($type, substr(MessageCodec::json($type, $data), 1), $to, $reliable);
    }

    public function ping(): void
    {
        $this->connection?->ping();
    }

    /** Reads what arrived and emits the events. Call once per frame. */
    public function poll(): void
    {
        if ($this->connection === null) {
            return;
        }
        foreach ($this->connection->poll() as $event) {
            switch ($event->type) {
                case Event::OPEN:
                    $this->emit('open');
                    if ($this->onOpen !== null) {
                        $action = $this->onOpen;
                        $this->onOpen = null;
                        $action();
                    }
                    break;
                case Event::CLOSE:
                    $this->room = null;
                    $this->peers = [];
                    $this->emit('close', [$event->data]);
                    break;
                case Event::ERROR:
                    $info = $event->info();
                    $this->emit('error', [(string)($info['code'] ?? 'error'), (string)($info['message'] ?? $event->data)]);
                    break;
                case Event::ROOM:
                    $info = $event->info();
                    $this->room = $info;
                    $this->peerId = (int)($info['peer'] ?? -1);
                    $this->hostId = (int)($info['host'] ?? 0);
                    $this->peers = array_map('intval', $info['peers'] ?? []);
                    $this->emit('room', [$info]);
                    break;
                case Event::PEER_JOIN:
                    $this->peers = array_values(array_unique([...$this->peers, $event->from]));
                    $this->emit('peerJoin', [$event->from]);
                    break;
                case Event::PEER_LEAVE:
                    $this->peers = array_values(array_diff($this->peers, [$event->from]));
                    $this->emit('peerLeave', [$event->from]);
                    break;
                case Event::HOST:
                    $this->hostId = $event->from;
                    $this->emit('host', [$event->from]);
                    break;
                case Event::MESSAGE:
                    [$type, $payload] = MessageCodec::decode($event->data);
                    $this->emit('message', [$event->from, $type, $payload]);
                    break;
                case Event::PONG:
                    $this->ping = (int)($event->info()['ms'] ?? 0);
                    $this->emit('pong', [$this->ping]);
                    break;
            }
        }
    }

    public function roomCode(): ?string
    {
        return isset($this->room['room']) ? (string)$this->room['room'] : null;
    }

    public function inRoom(): bool
    {
        return $this->room !== null;
    }

    public function peerId(): int
    {
        return $this->peerId;
    }

    public function isHost(): bool
    {
        return $this->room !== null && $this->peerId === $this->hostId;
    }

    /** @return int[] the other players in the room */
    public function peers(): array
    {
        return $this->peers;
    }

    public function playerCount(): int
    {
        return $this->room === null ? 0 : count($this->peers) + 1;
    }

    public function lastPing(): ?int
    {
        return $this->ping;
    }

    public function close(): void
    {
        $this->connection?->close();
        $this->connection = null;
        $this->room = null;
        $this->peers = [];
        $this->onOpen = null;
    }
}
