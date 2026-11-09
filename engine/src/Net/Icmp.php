<?php

namespace JumPHP\Net;

use JumPHP\Async\Task;

/**
 * Pings (ICMP echo), never blocking: `$engine->icmp()->ping('example.com', 4)->then(fn (array $replies) => ...)`; one reply per request:
 * `['seq' => 1, 'ok' => true, 'ms' => 12.3, 'ttl' => 57, 'from' => '93.184.216.34']`, or `['seq' => 2, 'ok' => false, 'error' => 'no answer']`.
 * `$engine->icmp()->trace('example.com')` is a traceroute: a Task of hops `['hop' => 1, 'ip' => '192.168.1.1', 'ms' => 0.4, 'reached' => false]` ending at the host
 * (`'reached' => true`), `'ip' => null` for a router that stays silent; it gives up after 5 silent hops in a row.
 * A low `$ttl` makes the routers answer "time to live exceeded" (`'error' => 'ttl', 'hop' => '10.0.0.1'`): a traceroute asks with 1, 2, 3...
 *
 * Desktop: the system's `ping`. Browser: the playground's TCP bridge, which runs its host's `ping` (an application the administrator approved for
 * ICMP: docs/tcp.md; one ping at a time, 5 a second, 200 a connection).
 */
final class Icmp
{
    /** @var list<array{task: Task, connection: ?TcpConnection, host: string, count: int, ttl: int, trace: bool, sent: int, replies: array, nextAt: float, proc: mixed, pipe: mixed, buf: string, started: float, pstart: float}> */
    private array $jobs = [];
    private int $nextId = 0;

    public function __construct(private Tcp $tcp)
    {
    }

    /** @return Task list<array<string, mixed>> the replies, in the order of the requests */
    public function ping(string $host, int $count = 4, int $ttl = 64): Task
    {
        return $this->start($host, max(1, min(20, $count)), max(1, min(64, $ttl)), false);
    }

    /** @return Task list<array{hop: int, ip: ?string, ms: ?float, reached: bool}> the route to the host, one entry per router (TTL 1, 2, 3...) */
    public function trace(string $host, int $maxHops = 30): Task
    {
        return $this->start($host, max(1, min(64, $maxHops)), 1, true);
    }

    private function start(string $host, int $count, int $ttl, bool $trace): Task
    {
        $task = new Task();
        $job = ['task' => $task, 'connection' => null, 'host' => $host, 'count' => $count, 'ttl' => $ttl, 'trace' => $trace, 'sent' => 0, 'replies' => [],
            'nextAt' => 0.0, 'proc' => null, 'pipe' => null, 'buf' => '', 'started' => microtime(true), 'pstart' => 0.0];
        if (Tcp::viaBridge()) {
            $connection = $this->tcp->connect($host, 0, 15.0, 'icmp');
            $job['connection'] = $connection;
            $index = $this->nextId + 1;
            $connection->on('data', function (string $json) use ($index): void {
                $reply = json_decode($json, true);
                if (is_array($reply) && isset($this->jobs[$index])) {
                    $this->jobs[$index]['replies'][(int)($reply['seq'] ?? 0)] = $reply;
                }
            });
            $connection->on('error', function (string $message) use ($index): void {
                $this->finish($index, $message);
            });
            $connection->on('close', function (?string $why) use ($index): void {
                $this->finish($index, $why);
            });
        }
        $this->jobs[++$this->nextId] = $job;
        return $task;
    }

    public function poll(): void
    {
        foreach ($this->jobs as $i => &$job) {
            $now = microtime(true);
            if ($job['connection'] !== null) {
                $c = $job['connection'];
                if ($c->isOpen() && $job['sent'] < $job['count'] && $now >= $job['nextAt'] && count($job['replies']) >= $job['sent']) {
                    $c->write((string)json_encode(['seq' => ++$job['sent'], 'ttl' => $job['trace'] ? $job['sent'] : $job['ttl']]));
                    $job['nextAt'] = $now + 0.3;
                }
            } else {
                $this->pollProcess($job, $now);
            }
            if (count($job['replies']) >= $job['count'] || ($job['trace'] && self::traceDone($job['replies'])) || $now - $job['started'] > 8 + $job['count'] * 3) {
                $this->settle($i, $job);
            }
        }
        unset($job);
        $this->jobs = array_filter($this->jobs, fn (array $j) => !$j['task']->isDone());
    }

    /** desktop: one `ping -c 1` at a time */
    private function pollProcess(array &$job, float $now): void
    {
        if ($job['proc'] === null) {
            if ($job['sent'] >= $job['count'] || $now < $job['nextAt']) {
                return;
            }
            $flag = PHP_OS_FAMILY === 'Darwin' ? '-m' : '-t';
            $proc = @proc_open(['ping', '-n', '-c', '1', '-W', '2', $flag, (string)($job['trace'] ? $job['sent'] + 1 : $job['ttl']), $job['host']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) {
                $job['replies'][++$job['sent']] = ['seq' => $job['sent'], 'ok' => false, 'error' => 'ping is not available'];
                return;
            }
            stream_set_blocking($pipes[1], false);
            $job['proc'] = $proc;
            $job['pstart'] = microtime(true);
            $job['pipe'] = $pipes[1];
            $job['buf'] = '';
            ++$job['sent'];
            return;
        }
        $job['buf'] .= (string)@fread($job['pipe'], 8192);
        $status = proc_get_status($job['proc']);
        if ($status['running'] && $now - $job['started'] < 60) {
            return;
        }
        $text = $job['buf'] . (string)@stream_get_contents($job['pipe']);
        fclose($job['pipe']);
        proc_close($job['proc']);
        $job['proc'] = $job['pipe'] = null;
        $job['replies'][$job['sent']] = self::parse($job['sent'], $text, $job['host'], ($now - $job['pstart']) * 1000);
        $job['nextAt'] = $now + 0.2;
    }

    /**
     * What `ping -c 1` printed (iputils, macOS): a reply, a router that said "time to live exceeded" (`error` ttl, `hop`), an unreachable
     * network / host (`error` unreachable, `hop`), or nothing. Forms: `From 10.0.0.1 icmp_seq=1 Time to live exceeded`, with a name
     * (`From gw.example.net (10.0.0.1) icmp_seq=1 ...`), IPv6 (`Time exceeded: Hop limit`), macOS (`36 bytes from 10.0.0.1: Time to live exceeded`).
     *
     * @return array<string, mixed>
     */
    public static function parse(int $seq, string $text, string $host, ?float $elapsedMs = null): array
    {
        if (preg_match('/time[=<]([0-9.]+) ?ms/', $text, $t)) {
            return ['seq' => $seq, 'ok' => true, 'ms' => (float)$t[1], 'ttl' => preg_match('/ttl=(\d+)/i', $text, $l) ? (int)$l[1] : null,
                'from' => preg_match('/bytes from (?:\S+ \()?([0-9a-f:.]+)\)?:/i', $text, $f) ? $f[1] : $host];
        }
        $from = '/^(?:From|\d+ bytes from)\s+(?:\S+\s+\()?([0-9a-f:.]+)\)?:?\s.*?';
        $answer = ['seq' => $seq, 'ok' => false];
        if (preg_match($from . 'Time(?: to live)? exceeded/mi', $text, $h)) {
            $answer += ['error' => 'ttl', 'hop' => rtrim($h[1], ':')];
        } elseif (preg_match($from . 'Unreachable/mi', $text, $h)) {
            $answer += ['error' => 'unreachable', 'hop' => rtrim($h[1], ':')];
        } else {
            return $answer + ['error' => 'no answer'];
        }
        return $elapsedMs !== null ? $answer + ['ms' => round($elapsedMs, 1)] : $answer;
    }

    /** a traceroute is over when the host answered, or after 5 silent hops in a row */
    private static function traceDone(array $replies): bool
    {
        ksort($replies);
        $silent = 0;
        foreach ($replies as $r) {
            if (!empty($r['ok'])) {
                return true;
            }
            $silent = ($r['error'] ?? '') === 'no answer' ? $silent + 1 : 0;
        }
        return $silent >= 5;
    }

    /**
     * The replies of a trace as hops. A host that answered a ping with a high enough TTL ends it; routers that answered "unreachable" end it too (nothing beyond).
     *
     * @param array<int, array<string, mixed>> $replies by seq
     * @return list<array{hop: int, ip: ?string, ms: ?float, reached: bool}>
     */
    public static function hops(array $replies): array
    {
        ksort($replies);
        $hops = [];
        foreach ($replies as $seq => $r) {
            $reached = !empty($r['ok']);
            $hops[] = ['hop' => (int)$seq, 'ip' => $reached ? (string)($r['from'] ?? '') : ($r['hop'] ?? null), 'ms' => isset($r['ms']) ? (float)$r['ms'] : null, 'reached' => $reached];
            if ($reached || ($r['error'] ?? '') === 'unreachable') {
                break;
            }
        }
        // trailing silent hops of a trace that never arrived are noise
        while ($hops !== [] && !$hops[array_key_last($hops)]['reached'] && $hops[array_key_last($hops)]['ip'] === null) {
            array_pop($hops);
        }
        return $hops;
    }

    private function finish(int $index, ?string $why): void
    {
        if (isset($this->jobs[$index]) && !$this->jobs[$index]['task']->isDone()) {
            $this->settle($index, $this->jobs[$index], $why);
        }
    }

    /** @param array<string, mixed> $job */
    private function settle(int $index, array &$job, ?string $error = null): void
    {
        if ($job['task']->isDone()) {
            return;
        }
        $job['connection']?->close();
        ksort($job['replies']);
        if ($error !== null && $job['replies'] === []) {
            $job['task']->settle(null, new \RuntimeException($error));
        } elseif ($job['trace']) {
            $job['task']->settle(self::hops($job['replies']));
        } else {
            $job['task']->settle(array_values($job['replies']));
        }
    }

    public function closeAll(): void
    {
        foreach ($this->jobs as $job) {
            $job['connection']?->close();
        }
        $this->jobs = [];
    }
}
