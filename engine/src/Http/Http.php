<?php

namespace JumPHP\Http;

use JumPHP\Async\Task;
use JumPHP\Bridge\JsBridge;
use JumPHP\Kernel\Platform;

/**
 * Asynchronous HTTP ($this->engine->http()): never blocks the frame, the answer arrives in a later frame.
 *
 *   $this->engine->http()->get('https://example.org/scores.json')
 *       ->then(fn (HttpResponse $r) => $this->scores = $r->ok() ? $r->json() : [],
 *              fn (\Throwable $e) => $this->error = $e->getMessage());     // HttpError: no answer at all
 *   $this->engine->http()->post($url, ['name' => 'AAA', 'score' => 1200]);  // array = JSON body
 *
 * A Task is returned (JumPHP\Async\Task: then(), isDone(), result()); then() callbacks run on the main thread
 * at the start of a frame. Any HTTP status settles the task with an HttpResponse (check ok()); only "no answer"
 * (network, DNS, timeout, CORS) is an error.
 *
 * Backends: the browser's fetch() (the page does the request: CORS rules apply, the server must send
 * Access-Control-Allow-Origin), curl_multi on desktop, or non-blocking sockets when curl is missing (async
 * runtime). The engine polls them at the start of each frame.
 */
class Http
{
    public const USER_AGENT = 'JumPHP';

    /** @var array<int, Task> */
    private array $tasks = [];
    /** @var array<int, array> desktop: running requests */
    private array $running = [];
    private int $nextId = 1;
    private $multi = null;
    /** Seconds before a request fails with HttpError (default of request()). */
    public float $timeout = 20.0;
    /** Desktop: curl_multi when the extension is there, else non-blocking sockets. */
    public bool $useCurl;

    public function __construct(private JsBridge $bridge)
    {
        $this->useCurl = extension_loaded('curl');
        $bridge->on('http', fn (array $e) => $this->answer($e));
    }

    public function get(string $url, array $headers = []): Task
    {
        return $this->request('GET', $url, null, $headers);
    }

    /** @param string|array|null $body array: sent as JSON */
    public function post(string $url, string|array|null $body = null, array $headers = []): Task
    {
        return $this->request('POST', $url, $body, $headers);
    }

    /**
     * @param string|array|null $body array: JSON (Content-Type set)
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, string|array|null $body = null, array $headers = [], ?float $timeout = null): Task
    {
        // in the browser an address like "/api/x" is the page's own origin (fetch resolves it): no CORS, no hard-coded host
        if (!preg_match('#^https?://#i', $url) && !(Platform::isWasm() && preg_match('#^/(?!/)#', $url))) {
            throw new \InvalidArgumentException("HTTP: an http:// or https:// URL (not '$url')");
        }
        if (is_array($body)) {
            $body = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers += ['Content-Type' => 'application/json'];
        }
        $method = strtoupper($method);
        $timeout ??= $this->timeout;
        $id = $this->nextId++;
        $task = $this->tasks[$id] = new Task();

        if (Platform::isWasm()) {
            $sent = $this->bridge->send('http', ['id' => $id, 'method' => $method, 'url' => $url, 'headers' => (object)$headers,
                'body' => $body === null ? null : base64_encode($body), 'timeout' => $timeout]);
            if (!$sent) {
                $this->fail($id, 'the page did not get the request', $url);
            }
        } elseif ($this->useCurl) {
            $this->startCurl($id, $method, $url, $body, $headers, $timeout);
        } else {
            $this->startStream($id, $method, $url, $body, $headers, $timeout, 0);
        }
        return $task;
    }

    /** Requests not answered yet. */
    public function pending(): int
    {
        return count($this->tasks);
    }

    /** @internal Engine: once per frame (desktop backends; the browser answers through JsBridge) */
    public function poll(): void
    {
        if ($this->running === []) {
            return;
        }
        if ($this->multi !== null) {
            $this->pollCurl();
        }
        foreach ($this->running as $id => $request) {
            if (isset($request['stream'])) {
                $this->pollStream($id);
            }
        }
    }

    /** Cancels everything (scene change): the tasks never settle. */
    public function cancelAll(): void
    {
        foreach ($this->running as $request) {
            if (isset($request['curl'])) {
                curl_multi_remove_handle($this->multi, $request['curl']);
            } elseif (isset($request['stream']) && is_resource($request['stream'])) {
                fclose($request['stream']);
            }
        }
        $this->running = [];
        $this->tasks = [];
    }

    // --- browser -------------------------------------------------------------------------------

    /** @internal JsBridge 'http': {id, status, headers, body (base64), url} or {id, error} */
    public function answer(array $e): void
    {
        $id = (int)($e['id'] ?? 0);
        if (!isset($this->tasks[$id])) {
            return;
        }
        if (isset($e['error'])) {
            $this->fail($id, (string)$e['error'], (string)($e['url'] ?? ''));
            return;
        }
        $headers = [];
        foreach ((array)($e['headers'] ?? []) as $name => $value) {
            $headers[strtolower((string)$name)] = (string)$value;
        }
        $this->succeed($id, new HttpResponse((int)$e['status'], (string)base64_decode((string)($e['body'] ?? '')), $headers, (string)($e['url'] ?? '')));
    }

    // --- desktop: curl ----------------------------------------------------------------------------

    private function startCurl(int $id, string $method, string $url, ?string $body, array $headers, float $timeout): void
    {
        $this->multi ??= curl_multi_init();
        $curl = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT_MS => (int)($timeout * 1000),
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a redirect: the headers of the last answer only
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        curl_multi_add_handle($this->multi, $curl);
        $this->running[$id] = ['curl' => $curl, 'url' => $url, 'headers' => &$responseHeaders];
        curl_multi_exec($this->multi, $active);
    }

    private function pollCurl(): void
    {
        do {
            $status = curl_multi_exec($this->multi, $active);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        while (($info = curl_multi_info_read($this->multi)) !== false) {
            $curl = $info['handle'];
            foreach ($this->running as $id => $request) {
                if (($request['curl'] ?? null) !== $curl) {
                    continue;
                }
                unset($this->running[$id]);
                if ($info['result'] !== CURLE_OK) {
                    $this->fail($id, curl_error($curl) ?: curl_strerror($info['result']), $request['url']);
                } else {
                    $this->succeed($id, new HttpResponse((int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                        (string)curl_multi_getcontent($curl), $request['headers'], (string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL)));
                }
                curl_multi_remove_handle($this->multi, $curl);
                break;
            }
        }
    }

    // --- desktop: non-blocking sockets (no curl) --------------------------------------------------

    private function startStream(int $id, string $method, string $url, ?string $body, array $headers, float $timeout, int $redirects): void
    {
        $parts = parse_url($url);
        if (!isset($parts['host'])) {
            $this->fail($id, 'bad URL', $url);
            return;
        }
        $tls = strtolower($parts['scheme'] ?? 'http') === 'https';
        $port = (int)($parts['port'] ?? ($tls ? 443 : 80));
        $context = stream_context_create(['ssl' => ['peer_name' => $parts['host'], 'SNI_enabled' => true]]);
        $errno = 0;
        $error = '';
        // the DNS lookup itself blocks (no async resolver in PHP): usually a few ms, cached by the system
        $stream = @stream_socket_client("tcp://{$parts['host']}:$port", $errno, $error, $timeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $context);
        if ($stream === false) {
            $this->fail($id, "connect: $error ($errno)", $url);
            return;
        }
        stream_set_blocking($stream, false);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $head = "$method $path HTTP/1.1\r\nHost: $host\r\nUser-Agent: " . self::USER_AGENT
            . "\r\nAccept-Encoding: identity\r\nConnection: close\r\n";
        foreach ($headers as $name => $value) {
            $head .= "$name: $value\r\n";
        }
        if ($body !== null) {
            $head .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        $this->running[$id] = [
            'stream' => $stream, 'url' => $url, 'tls' => $tls, 'phase' => 'connect', 'out' => $head . "\r\n" . ($body ?? ''),
            'in' => '', 'deadline' => microtime(true) + $timeout, 'timeout' => $timeout,
            'request' => [$method, $body, $headers], 'redirects' => $redirects,
        ];
    }

    private function pollStream(int $id): void
    {
        $r = &$this->running[$id];
        $stream = $r['stream'];
        if (microtime(true) > $r['deadline']) {
            fclose($stream);
            unset($this->running[$id]);
            $this->fail($id, 'timeout', $r['url']);
            return;
        }
        if ($r['phase'] === 'connect') {
            $read = null;
            $write = [$stream];
            $except = null;
            if (@stream_select($read, $write, $except, 0) !== 1) {
                return;
            }
            if (stream_socket_get_name($stream, true) === false) {
                fclose($stream);
                unset($this->running[$id]);
                $this->fail($id, 'connection refused', $r['url']);
                return;
            }
            $r['phase'] = $r['tls'] ? 'tls' : 'write';
        }
        if ($r['phase'] === 'tls') {
            $done = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($done === 0) {
                return;
            }
            if ($done === false) {
                fclose($stream);
                unset($this->running[$id]);
                $this->fail($id, 'TLS handshake failed', $r['url']);
                return;
            }
            $r['phase'] = 'write';
        }
        if ($r['phase'] === 'write') {
            $written = @fwrite($stream, $r['out']);
            if ($written === false) {
                fclose($stream);
                unset($this->running[$id]);
                $this->fail($id, 'write failed', $r['url']);
                return;
            }
            $r['out'] = (string)substr($r['out'], $written);
            if ($r['out'] !== '') {
                return;
            }
            $r['phase'] = 'read';
        }
        while (($chunk = @fread($stream, 65536)) !== false && $chunk !== '') {
            $r['in'] .= $chunk;
        }
        if (!feof($stream)) {
            return;
        }
        fclose($stream);
        $request = $r;
        unset($r, $this->running[$id]);
        $this->finishStream($id, $request);
    }

    private function finishStream(int $id, array $request): void
    {
        $split = strpos($request['in'], "\r\n\r\n");
        if ($split === false || !preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $request['in'], $m)) {
            $this->fail($id, 'bad HTTP answer', $request['url']);
            return;
        }
        $status = (int)$m[1];
        $headers = [];
        foreach (array_slice(explode("\r\n", substr($request['in'], 0, $split)), 1) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        $body = substr($request['in'], $split + 4);
        if (str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked')) {
            $body = self::unchunk($body);
        }
        if (in_array($status, [301, 302, 303, 307, 308], true) && isset($headers['location']) && $request['redirects'] < 5) {
            [$method, $sentBody, $sentHeaders] = $request['request'];
            if ($status === 303 || (in_array($status, [301, 302], true) && $method === 'POST')) {
                [$method, $sentBody] = ['GET', null];
            }
            $this->startStream($id, $method, self::resolveUrl($request['url'], $headers['location']), $sentBody, $sentHeaders,
                $request['timeout'], $request['redirects'] + 1);
            return;
        }
        $this->succeed($id, new HttpResponse($status, $body, $headers, $request['url']));
    }

    private static function unchunk(string $body): string
    {
        $out = '';
        $offset = 0;
        while (($eol = strpos($body, "\r\n", $offset)) !== false) {
            $size = hexdec(trim(explode(';', substr($body, $offset, $eol - $offset))[0]));
            if ($size <= 0) {
                break;
            }
            $out .= substr($body, $eol + 2, (int)$size);
            $offset = $eol + 2 + (int)$size + 2;
        }
        return $out;
    }

    private static function resolveUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($location, '/')) {
            return $root . $location;
        }
        $dir = rtrim(dirname($p['path'] ?? '/'), '/');
        return $root . $dir . '/' . $location;
    }

    // --- results ---------------------------------------------------------------------------------

    private function succeed(int $id, HttpResponse $response): void
    {
        $task = $this->tasks[$id] ?? null;
        unset($this->tasks[$id]);
        $task?->settle($response);
    }

    private function fail(int $id, string $message, string $url): void
    {
        $task = $this->tasks[$id] ?? null;
        unset($this->tasks[$id]);
        $task?->settle(null, new HttpError("HTTP $url: $message", $url));
    }
}
