<?php

// `bin/jumphp debug`: the IDE's debugger (ide/src/Debug) as an MCP server on stdin / stdout, for Claude Code in a
// terminal: `claude mcp add jumphp-debug -- bin/jumphp debug`. It listens for Xdebug on JUMPHP_DEBUG_PORT (9003);
// Claude runs the program itself with JUMPHP_XDEBUG=<port> (bin/jumphp test / shot / php ...), then debug_wait.
// Runs in the desktop image (bin/jumphp starts it), the project mounted as /workspace. docs/ide.md "Debugging".

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'JumPHP\\Ide\\')) {
        $file = __DIR__ . '/../ide/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Debug\Debugger;

$root = getenv('JUMPHP_DEBUG_ROOT') ?: '/workspace';
$port = (int)(getenv('JUMPHP_DEBUG_PORT') ?: 9003);
/** @var list<array{0: string, 1: array}> */
$events = [];
$debugger = new Debugger($root, function (string $event, array $payload) use (&$events): void {
    $events[] = [$event, $payload];
});
$listening = $debugger->listen(getenv('JUMPHP_DEBUG_HOST') ?: '127.0.0.1', $port);
fwrite(STDERR, "[jumphp debug] waiting for Xdebug on port $listening: run JUMPHP_XDEBUG=$listening bin/jumphp ...\n");

$tick = static function () use ($debugger): void {
    $debugger->tick();
};
/** Runs the debugger until $done() or $seconds pass. */
$until = static function (callable $done, float $seconds) use ($tick): bool {
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        $tick();
        if ($done()) {
            return true;
        }
        usleep(10000);
    }
    return false;
};
$settle = static function (Deferred $d, float $seconds = 30.0) use ($until): mixed {
    if (!$until(fn () => $d->isDone(), $seconds)) {
        throw new RuntimeException('Xdebug did not answer in time');
    }
    return $d->result();
};
$paused = static function () use ($debugger): ?array {
    foreach ($debugger->status()['sessions'] as $s) {
        if ($s['paused'] !== null) {
            return $s['paused'];
        }
    }
    return null;
};
$state = static function (int $frame = 0) use ($debugger, $paused, $settle): array {
    $p = $paused();
    if ($p === null) {
        $running = count($debugger->status()['sessions']);
        return ['status' => $running ? 'running (not stopped: debug_wait)' : 'no program connected yet: run it with JUMPHP_XDEBUG=' . $debugger->status()['port'] . ', then debug_wait'];
    }
    $vars = $settle($debugger->variables($p['session'], $frame));
    return ['status' => 'paused', 'path' => $p['path'], 'line' => $p['line'], 'frame' => $frame,
        'exception' => $p['exception'] ?? null,
        'stack' => array_map(fn ($f) => "#{$f['level']} {$f['where']} " . ($f['path'] ? "{$f['path']}:{$f['line']}" : "(outside the project) line {$f['line']}"), $p['stack']),
        'variables' => array_map(fn ($v) => ['name' => $v['name'], 'type' => $v['type'], 'value' => $v['value']] + ($v['children'] ? ['expandable' => true] : []), array_slice($vars, 0, 80))];
};
/** Waits for the program to stop or end after a resume. */
$afterResume = static function (Deferred $d, float $timeout) use ($until, $state): array {
    $until(fn () => $d->isDone(), $timeout);
    if (!$d->isDone()) {
        return ['status' => "still running after {$timeout} s (no breakpoint reached yet): debug_wait"];
    }
    $r = $d->result();
    return ($r['status'] ?? '') === 'break' ? $state() : ['status' => 'the program ended'];
};
$session = static function () use ($paused): int {
    return $paused()['session'] ?? throw new RuntimeException('the program is not stopped: run it with JUMPHP_XDEBUG, then debug_wait');
};

$line = ['type' => 'integer', 'minimum' => 1];
$tools = [
    'debug_breakpoints' => ['Sets the breakpoints of a file (all of them: lines not given are removed); a line can have a condition (a PHP expression: it stops only when true). Without a path: lists them. clear_all removes every breakpoint first. Paths relative to the project.',
        ['path' => ['type' => 'string'], 'lines' => ['type' => 'array', 'items' => ['anyOf' => [$line, ['type' => 'object', 'properties' => ['line' => $line, 'condition' => ['type' => 'string']], 'required' => ['line']]]]], 'clear_all' => ['type' => 'boolean']], []],
    'debug_exceptions' => ['Exceptions that stop the program where they are thrown, caught or not ("*": every exception; class names: Error, TypeError, App\\GameOver; []: none). Without classes: lists them.',
        ['classes' => ['type' => 'array', 'items' => ['type' => 'string']]], []],
    'debug_wait' => ['Waits until the program run with JUMPHP_XDEBUG=<port> (bin/jumphp test pong, shot pong, php games/x/tool.php... started by you, in the background) stops at a breakpoint or an exception (then: where, the stack, the variables), or `timeout` seconds pass (default 120).',
        ['timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600]], []],
    'debug_state' => ['Where the program is stopped: file, line, exception, the stack, the variables of a frame (0: where it stopped; 1: its caller...).',
        ['frame' => ['type' => 'integer', 'minimum' => 0]], []],
    'debug_step' => ['Moves the stopped program: step_over, step_into, step_out, run (to the next breakpoint), stop (ends it). Waits until it stops again or ends.',
        ['action' => ['type' => 'string', 'enum' => ['step_over', 'step_into', 'step_out', 'run', 'stop']], 'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600]], ['action']],
    'debug_run_to' => ['Runs the stopped program to a line and waits until it stops there, at a breakpoint met first, or ends.',
        ['path' => ['type' => 'string'], 'line' => $line, 'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600]], ['path', 'line']],
    'debug_eval' => ['Evaluates a PHP expression where the program is stopped ($this->score * 2, count($enemies)...): its value and children.',
        ['expression' => ['type' => 'string']], ['expression']],
    'debug_variables' => ['The variables of a frame, or the children of one of them (name: its full name, e.g. $this->player, $list[3]).',
        ['name' => ['type' => 'string'], 'frame' => ['type' => 'integer', 'minimum' => 0]], []],
    'debug_set_value' => ['Gives a variable of the stopped program a new value: a PHP expression evaluated there (9, "Ada", [1, 2], null).',
        ['name' => ['type' => 'string'], 'value' => ['type' => 'string'], 'frame' => ['type' => 'integer', 'minimum' => 0]], ['name', 'value']],
];

$call = static function (string $name, array $a) use ($debugger, $settle, $until, $state, $afterResume, $session, $paused): mixed {
    $timeout = (float)($a['timeout'] ?? 60);
    switch ($name) {
        case 'debug_breakpoints':
            if (!empty($a['clear_all'])) {
                $debugger->clearBreakpoints();
            }
            if (isset($a['path'])) {
                $debugger->setBreakpoints((string)$a['path'], array_map(fn ($l) => is_array($l) ? $l : ['line' => (int)$l], (array)($a['lines'] ?? [])));
            }
            return ['breakpoints' => $debugger->breakpointList()];
        case 'debug_exceptions':
            return ['exceptions' => isset($a['classes']) ? $debugger->setExceptionBreakpoints(array_map('strval', (array)$a['classes'])) : $debugger->status()['exceptions']];
        case 'debug_wait':
            $until(fn () => $paused() !== null, (float)($a['timeout'] ?? 120));
            return $state();
        case 'debug_state':
            return $state((int)($a['frame'] ?? 0));
        case 'debug_step':
            $how = (string)$a['action'];
            if ($how === 'stop') {
                $debugger->resume($session(), 'stop');
                return ['status' => 'stopped'];
            }
            return $afterResume($debugger->resume($session(), $how), $timeout);
        case 'debug_run_to':
            return $afterResume($debugger->runTo($session(), (string)$a['path'], (int)$a['line']), $timeout);
        case 'debug_eval':
            $r = $settle($debugger->evaluate($session(), (string)$a['expression']));
            return ['type' => $r['type'], 'value' => $r['value'], 'items' => array_map(fn ($v) => ['name' => $v['name'], 'type' => $v['type'], 'value' => $v['value']], array_slice($r['items'] ?? [], 0, 80))];
        case 'debug_variables':
            if (empty($a['name'])) {
                return $state((int)($a['frame'] ?? 0))['variables'] ?? [];
            }
            $r = $settle($debugger->children($session(), (string)$a['name'], (int)($a['frame'] ?? 0)));
            return $r === null ? null : ['name' => $r['fullname'] ?: $a['name'], 'type' => $r['type'], 'value' => $r['value'],
                'items' => array_map(fn ($v) => ['name' => $v['name'], 'fullname' => $v['fullname'], 'type' => $v['type'], 'value' => $v['value']], array_slice($r['items'] ?? [], 0, 200))];
        case 'debug_set_value':
            $r = $settle($debugger->setValue($session(), (string)$a['name'], (string)$a['value'], (int)($a['frame'] ?? 0)));
            return $r === null ? null : ['name' => $r['fullname'] ?: $a['name'], 'type' => $r['type'], 'value' => $r['value']];
    }
    throw new RuntimeException("unknown tool $name");
};

$instructions = "Step debugging of the project's PHP (Xdebug), port $listening. Set breakpoints (debug_breakpoints, paths relative to "
    . "the project) or exceptions to stop at (debug_exceptions), then start the program yourself, in the background, with "
    . "JUMPHP_XDEBUG=$listening (e.g. `JUMPHP_XDEBUG=$listening bin/jumphp test pong`), then debug_wait. Read (debug_state, "
    . "debug_variables, debug_eval), move (debug_step, debug_run_to), try a value (debug_set_value); end with debug_step run or stop.";

$send = static function (array $message): void {
    fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    fflush(STDOUT);
};

stream_set_blocking(STDIN, false);
$buffer = '';
while (true) {
    $read = [STDIN];
    $write = $except = null;
    if (@stream_select($read, $write, $except, 0, 20000) > 0) {
        $chunk = fread(STDIN, 65536);
        if ($chunk === '' && feof(STDIN)) {
            break;     // Claude Code closed it
        }
        $buffer .= (string)$chunk;
    }
    $tick();
    while (($nl = strpos($buffer, "\n")) !== false) {
        $raw = trim(substr($buffer, 0, $nl));
        $buffer = substr($buffer, $nl + 1);
        if ($raw === '') {
            continue;
        }
        $m = json_decode($raw, true);
        if (!is_array($m) || !isset($m['method'])) {
            continue;
        }
        $id = $m['id'] ?? null;
        if ($id === null) {
            continue;      // a notification
        }
        try {
            $result = match ($m['method']) {
                'initialize' => ['protocolVersion' => (string)($m['params']['protocolVersion'] ?? '2025-06-18'), 'capabilities' => ['tools' => new stdClass()],
                    'serverInfo' => ['name' => 'jumphp-debug', 'version' => '1.0'], 'instructions' => $instructions],
                'ping' => new stdClass(),
                'tools/list' => ['tools' => array_map(fn ($name, $t) => ['name' => $name, 'description' => $t[0],
                    'inputSchema' => ['type' => 'object', 'properties' => $t[1] ?: new stdClass(), 'required' => $t[2]]], array_keys($tools), $tools)],
                'tools/call' => (function () use ($m, $call): array {
                    try {
                        $out = $call((string)$m['params']['name'], (array)($m['params']['arguments'] ?? []));
                        return ['content' => [['type' => 'text', 'text' => json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]]];
                    } catch (Throwable $e) {
                        return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
                    }
                })(),
                default => throw new RuntimeException("unknown method {$m['method']}"),
            };
            $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
        } catch (Throwable $e) {
            $send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => $e->getMessage()]]);
        }
    }
}
$debugger->close();
