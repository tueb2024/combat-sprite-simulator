<?php

// bin/jumphp shot <cli app>: the app run in a pseudo-terminal of a given size, keys typed at given times, and
// what the screen shows written as text (the CLI apps' screenshot: open the file to see the screen).
//   php cli-shot.php <out.txt> <columns> <rows> <seconds> <keys JSON> -- <command...>
// keys: [[seconds, "text"], ...]; "\r" Enter, "\u001b[A" up, "\u001b[B" down, "\u001b" Escape, "\u0003" Ctrl+C.
// The screen is rebuilt with symfony/tui's ScreenBuffer when the app has it (vendor/), else the ANSI codes are removed.
[, $out, $columns, $rows, $seconds, $keys] = $argv + [null, null, '80', '24', '3', '[]'];
$command = array_slice($argv, (int)array_search('--', $argv, true) + 1);
if ($out === null || !$command || !in_array('--', $argv, true)) {
    fwrite(STDERR, "usage: cli-shot.php <out.txt> <columns> <rows> <seconds> <keys JSON> -- <command...>\n");
    exit(2);
}
$columns = max(20, (int)$columns);
$rows = max(5, (int)$rows);
$keys = json_decode((string)$keys, true) ?: [];
usort($keys, fn ($a, $b) => $a[0] <=> $b[0]);

$shell = sprintf('stty cols %d rows %d 2>/dev/null; exec %s', $columns, $rows, implode(' ', array_map('escapeshellarg', $command)));
$env = getenv() + ['TERM' => 'xterm-256color', 'COLUMNS' => (string)$columns, 'LINES' => (string)$rows];
$process = proc_open(['sh', '-c', $shell], [0 => ['pty'], 1 => ['pty'], 2 => ['pty']], $pipes, null, $env);
if (!is_resource($process)) {
    fwrite(STDERR, "cannot start the app in a pseudo-terminal\n");
    exit(1);
}
stream_set_blocking($pipes[1], false);
$output = '';
$start = microtime(true);
while (($now = microtime(true) - $start) < (float)$seconds) {
    while ($keys && $keys[0][0] <= $now) {
        fwrite($pipes[0], (string)array_shift($keys)[1]);
    }
    $read = [$pipes[1]];
    $write = $except = null;
    if (@stream_select($read, $write, $except, 0, 50000)) {
        $chunk = @fread($pipes[1], 65536);
        if ($chunk === false || ($chunk === '' && feof($pipes[1]))) {
            if (!proc_get_status($process)['running']) {
                break;
            }
        } else {
            $output .= $chunk;
        }
    } elseif (!proc_get_status($process)['running']) {
        break;
    }
}
$status = proc_get_status($process);
if ($status['running']) {
    proc_terminate($process);
}
proc_close($process);

if (is_file('vendor/autoload.php')) {
    require 'vendor/autoload.php';    // the app's: symfony/tui
}
if (class_exists(Symfony\Component\Tui\Terminal\ScreenBuffer::class)) {
    $buffer = new Symfony\Component\Tui\Terminal\ScreenBuffer($columns, $rows);
    $buffer->write($output);
    $screen = $buffer->getScreen();
} else {
    $screen = (string)preg_replace('/\x1b(\[[0-9;?]*[ -\/]*[@-~]|\][^\x07]*\x07|[()][A-Z0-9]|[=>78])/', '', $output);
    $screen = implode("\n", array_slice(explode("\n", str_replace("\r", '', $screen)), -$rows));
}
@mkdir(dirname($out), 0777, true);
file_put_contents($out, rtrim($screen) . "\n");
echo $screen, "\n";
exit($status['running'] ? 0 : (int)$status['exitcode']);
