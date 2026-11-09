<?php

namespace JumPHP\Net;

use Evenement\EventEmitter;
use JumPHP\Bridge\JsBridge;
use JumPHP\Http\Http;
use JumPHP\Http\HttpResponse;
use JumPHP\Kernel\GameConfig;

/**
 * One connection through an SSH server (`ssh -W host:port`, see Ssh). The events of TcpConnection: open, data (string), close
 * (?string $reason), error (string, before 'close'); plus knownHosts (string: the known_hosts text after the session, browser only).
 */
final class SshConnection extends EventEmitter
{
    private const HOME = '/home/user/.ssh';
    private static int $next = 1;

    private string $state = TcpConnection::IDLE;
    private int $id;
    private string $stderr = '';
    private string $pending = '';
    private float $deadline = 0.0;
    /** @var resource|null */
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private bool $opened = false;
    /** a failure during start() waits for the first poll(): the caller attaches its listeners after connect() returns */
    private bool $starting = false;
    private ?string $startFailure = null;
    private ?\Closure $listener = null;
    /** @var list<string> the keyring's temporary key files (desktop) */
    private array $tempKeys = [];

    /** @param array<string, mixed> $options */
    public function __construct(private GameConfig $config, private Http $http, private JsBridge $bridge, private string $host,
        private int $port, private array $options, private float $timeout, private bool $browser, private ?\Closure $runtime = null)
    {
        $this->id = self::$next++;
    }

    public function state(): string { return $this->state; }
    public function isOpen(): bool { return $this->state === TcpConnection::OPEN; }
    public function isClosed(): bool { return $this->state === TcpConnection::CLOSED; }

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
        $this->state = TcpConnection::CONNECTING;
        $this->deadline = microtime(true) + $this->timeout + 5;
        $sshHost = trim((string)($this->options['sshHost'] ?? ''));
        if ($sshHost === '') {
            $this->fail('No SSH host.');
            return;
        }
        $this->browser ? $this->askTicket($sshHost) : $this->startProcess($sshHost);
    }

    public function write(string $bytes): bool
    {
        if ($this->state === TcpConnection::CLOSED) {
            return false;
        }
        if ($bytes === '') {
            return true;
        }
        if ($this->state === TcpConnection::CONNECTING && !$this->opened) {
            $this->pending .= $bytes;
            return true;
        }
        $this->send($bytes);
        return true;
    }

    public function close(): void
    {
        if ($this->state === TcpConnection::CLOSED) {
            return;
        }
        if ($this->browser) {
            $this->bridge->send('ssh', ['op' => 'kill', 'id' => $this->id]);
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
        if ($this->state === TcpConnection::CLOSED) {
            return;
        }
        if ($this->process !== null) {
            $this->pollProcess();
        }
        if ($this->state === TcpConnection::CONNECTING && !$this->opened && microtime(true) > $this->deadline) {
            $this->fail('SSH connection timed out');
        }
    }

    /** The arguments of `ssh -W` (the same for the program and for ssh.wasm). @return list<string> */
    public function arguments(string $sshHost, string $identityPath, bool $hasSecret, int|array $keys = 0): array
    {
        $args = ['-W', self::bracket($this->host) . ':' . $this->port, '-p', (string)(int)($this->options['sshPort'] ?? 22)];
        if (($user = trim((string)($this->options['sshUser'] ?? ''))) !== '') {
            array_push($args, '-l', $user);
        }
        $extra = array_map('strval', (array)($this->options['sshOptions'] ?? []));
        $set = ['ConnectTimeout=' . (int)$this->timeout, 'ServerAliveInterval=30', 'LogLevel=DEBUG1',
            'BatchMode=' . ($hasSecret ? 'no' : 'yes'), 'StrictHostKeyChecking=accept-new', 'NumberOfPasswordPrompts=1'];
        foreach (array_merge($set, $extra) as $o) {
            array_push($args, '-o', $o);
        }
        if ($identityPath !== '') {
            array_push($args, '-i', $identityPath, '-o', 'IdentitiesOnly=yes');
        }
        $paths = is_array($keys) ? $keys : array_map(fn (int $i) => self::HOME . '/id_' . $i, range(0, max(0, $keys - 1)));
        foreach ($keys === 0 ? [] : $paths as $path) {
            array_push($args, '-i', $path);      // the keyring: ssh tries them in turn (an agent's behaviour)
        }
        if ($keys !== 0 && $keys !== []) {
            array_push($args, '-o', 'IdentitiesOnly=yes');
        }
        if ($this->browser) {
            array_push($args, '-o', 'UserKnownHostsFile=' . self::HOME . '/known_hosts');
        }
        $args[] = '--';
        $args[] = $sshHost;
        return $args;
    }

    private static function bracket(string $host): string
    {
        return str_contains($host, ':') && !str_starts_with($host, '[') ? "[$host]" : $host;
    }

    // browser: ssh.wasm in a worker, its network the TCP bridge --------------------------------------------------------------

    private function askTicket(string $sshHost): void
    {
        $port = (int)($this->options['sshPort'] ?? 22);
        try {
            $this->http->post($this->config->tcpTicketUrl(), ['slug' => $this->config->id(), 'host' => $sshHost, 'port' => $port, 'scope' => 'ssh'])
                ->then(function (HttpResponse $r) use ($sshHost): void {
                    try {
                        $data = $r->json();
                    } catch (\JsonException) {
                        $data = null;            // not JSON (a proxy's "404 page not found" during a restart): the status below says why
                    }
                    if ($this->state !== TcpConnection::CONNECTING) {
                        return;
                    }
                    if (!$r->ok() || !is_array($data) || !isset($data['ticket'], $data['bridge'])) {
                        $this->fail(is_array($data) && isset($data['error']) ? (string)$data['error'] : 'the TCP bridge refused SSH (HTTP ' . $r->status . ')');
                        return;
                    }
                    try {
                        $bridge = BridgeRoute::forConfig($this->config, $this->runtime !== null ? ($this->runtime)() : new NoRuntime(), (string)$data['bridge']);
                    } catch (\RuntimeException $e) {
                        $this->fail($e->getMessage());          // net_mode local without a usable runtime
                        return;
                    }
                    $this->openWorker($sshHost, $bridge . '?raw=1&ticket=' . rawurlencode((string)$data['ticket']));
                }, fn (\Throwable $e) => $this->fail('cannot reach the playground: ' . $e->getMessage()));
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    private function openWorker(string $sshHost, string $websocket): void
    {
        $identity = (string)($this->options['sshKey'] ?? '');
        $secret = (string)($this->options['sshPassphrase'] ?? $this->options['sshPassword'] ?? '');
        $this->bridge->on('ssh', $this->listener = function (array $e): void {
            if ((int)($e['id'] ?? 0) === $this->id) {
                $this->onPage($e);
            }
        });
        $ring = array_values(array_filter((array)($this->options['sshKeys'] ?? []), fn ($k) => is_array($k) && trim((string)($k['key'] ?? '')) !== ''));
        $this->bridge->send('ssh', ['op' => 'open', 'id' => $this->id, 'options' => [
            'args' => $this->arguments($sshHost, $identity !== '' ? self::HOME . '/id' : '', $secret !== '' || array_filter($ring, fn ($k) => ($k['passphrase'] ?? '') !== '') !== [], count($ring)),
            'websocket' => $websocket, 'identity' => $identity, 'secret' => $secret, 'knownHosts' => (string)($this->options['knownHosts'] ?? ''),
            'identities' => array_map(fn ($k) => (string)$k['key'], $ring), 'secrets' => array_map(fn ($k) => (string)($k['passphrase'] ?? ''), $ring),
        ]]);
        $this->opened();
    }

    /** @param array<string, mixed> $e */
    private function onPage(array $e): void
    {
        switch ($e['ev'] ?? '') {
            case 'stdout':
                $this->emit('data', [(string)base64_decode((string)($e['data'] ?? ''))]);
                break;
            case 'stderr':
                $this->addStderr((string)($e['text'] ?? ''));
                break;
            case 'knownHosts':
                $this->emit('knownHosts', [(string)($e['text'] ?? '')]);
                break;
            case 'exit':
                $code = (int)($e['code'] ?? 0);
                $this->finish($code === 0 ? null : $this->reason($code), $code !== 0);
                break;
            case 'error':
                $this->fail((string)($e['text'] ?? 'SSH error'));
                break;
        }
    }

    // desktop: the system's ssh --------------------------------------------------------------------------------------------

    private function startProcess(string $sshHost): void
    {
        $secret = (string)($this->options['sshPassphrase'] ?? $this->options['sshPassword'] ?? '');
        $key = (string)($this->options['sshKey'] ?? '');
        $keyFile = $key !== '' && is_file($key) ? $key : '';
        // the keyring: each key a temporary file (0600) for the life of the connection
        $paths = [];
        foreach ((array)($this->options['sshKeys'] ?? []) as $k) {
            if (is_array($k) && trim((string)($k['key'] ?? '')) !== '') {
                $file = (string)tempnam(sys_get_temp_dir(), 'jhk');
                chmod($file, 0600);
                file_put_contents($file, rtrim((string)$k['key']) . "\n");
                $paths[] = $this->tempKeys[] = $file;
            }
        }
        $command = ['ssh', ...$this->arguments($sshHost, $keyFile, false, $paths)];
        if ($secret !== '') {
            $this->fail('A password or passphrase needs the desktop tunnel (SshTunnel) or an ssh-agent: this pipe is not interactive.');
            return;
        }
        $process = self::hasProgram('ssh') ? @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes) : false;
        if (!is_resource($process)) {
            $this->fail('cannot start ssh (is the ssh program installed?)');
            return;
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $this->process = $process;
        $this->pipes = $pipes;
        $this->opened();
    }

    private function pollProcess(): void
    {
        $data = @fread($this->pipes[1], 65536);
        if ($data !== '' && $data !== false) {
            $this->emit('data', [$data]);
        }
        $err = @fread($this->pipes[2], 8192);
        if ($err !== '' && $err !== false) {
            $this->addStderr($err);
        }
        $status = proc_get_status($this->process);
        if (!$status['running'] && ($data === '' || $data === false)) {
            $code = (int)$status['exitcode'];
            $this->finish($code === 0 || $code === -1 ? null : $this->reason($code), $code > 0);
        }
    }

    private static function hasProgram(string $name): bool
    {
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            foreach ([$name, $name . '.exe'] as $file) {
                if ($dir !== '' && is_file($dir . DIRECTORY_SEPARATOR . $file) && is_executable($dir . DIRECTORY_SEPARATOR . $file)) {
                    return true;
                }
            }
        }
        return false;
    }

    // both ----------------------------------------------------------------------------------------------------------------

    private function reason(int $code): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $this->stderr) ?? '');
        $text = $text !== '' ? $text : "ssh ended with code $code";
        return $this->facts !== [] ? $text . ' [' . implode('; ', $this->facts) . ']' : $text;
    }

    /** @var array<string, string> what ssh said about the server it reached (from its debug lines), told with a failure */
    private array $facts = [];

    /**
     * ssh runs with LogLevel=DEBUG1: its errors are kept as they are, its debug lines are read for what they teach (the address reached, the
     * server's software and host key, the key offered, what the server accepts) and dropped.
     */
    private function addStderr(string $text): void
    {
        $keep = '';
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (!str_starts_with($line, 'debug')) {
                $keep .= $line . "\n";
                continue;
            }
            if (preg_match('/Connecting to (\S+) \[([^\]]+)\] port (\d+)/', $line, $m)) {
                $this->facts['reached'] = "reached $m[2]:$m[3]";
            } elseif (preg_match('/remote software version (\S+)/', $line, $m)) {
                $this->facts['software'] = "server $m[1]";
            } elseif (preg_match('/Server host key: (\S+) (SHA256:\S+)/', $line, $m)) {
                $this->facts['hostkey'] = "host key $m[1] $m[2]";
            } elseif (preg_match('/Offering public key: (?:\S+ )?(\S+) (SHA256:\S+)/', $line, $m)) {
                $this->facts['offered'] = "key offered $m[1] $m[2]";
            } elseif (preg_match('/Server accepts key/', $line)) {
                $this->facts['accepted'] = 'the server accepts this key';
            } elseif (preg_match('/Authentications that can continue: (\S+)/', $line, $m)) {
                $this->facts['methods'] = "the server allows: $m[1]";
            } elseif (preg_match('/Authenticated to /', $line)) {
                $this->facts['auth'] = 'login accepted';
            }
        }
        $this->stderr = mb_substr($this->stderr . $keep, -2000);
    }

    private function opened(): void
    {
        $this->opened = true;
        $this->state = TcpConnection::OPEN;
        $this->emit('open');
        if ($this->pending !== '') {
            $this->send($this->pending);
            $this->pending = '';
        }
    }

    private function send(string $bytes): void
    {
        if ($this->browser) {
            foreach (str_split($bytes, 49152) as $part) {
                $this->bridge->send('ssh', ['op' => 'data', 'id' => $this->id, 'data' => base64_encode($part)]);
            }
        } elseif ($this->process !== null) {
            @fwrite($this->pipes[0], $bytes);
        }
    }

    private function fail(string $message): void
    {
        if ($this->starting) {
            $this->startFailure = $message;
            return;
        }
        $this->emit('error', [$message]);
        $this->finish($message);
    }

    private function finish(?string $reason, bool $asError = false): void
    {
        if ($this->state === TcpConnection::CLOSED) {
            return;
        }
        if ($asError && $reason !== null) {
            $this->emit('error', [$reason]);
        }
        $this->state = TcpConnection::CLOSED;
        foreach ($this->tempKeys as $file) {
            @unlink($file);
        }
        $this->tempKeys = [];
        if ($this->process !== null) {
            foreach ($this->pipes as $pipe) {
                @fclose($pipe);
            }
            @proc_terminate($this->process);
            @proc_close($this->process);
            $this->process = null;
            $this->pipes = [];
        }
        if ($this->listener !== null) {
            $this->bridge->removeListener('ssh', $this->listener);
            $this->listener = null;
        }
        $this->emit('close', [$reason]);
    }
}
