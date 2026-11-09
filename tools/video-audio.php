<?php
// bin/jumphp video:replay: the sound of a film. Reads <run>/audio/audio.json (SoundManager's AudioLog: what the game played and
// when) and writes what ffmpeg needs to mix it: <run>/audio.args (one argument a line: the inputs) and <run>/audio.filter (the
// filter graph, output [out]). One input per distinct file whatever the number of plays; a music is cut where another music or a
// stop comes, or at the end. Without any sound: a silent track. Paths of /workspace/... are those of the ffmpeg container's /w/...
// usage: php tools/video-audio.php <run folder> <seconds> [<from>=/workspace <to>=/w]
if ($argc < 3) {
    fwrite(STDERR, "usage: video-audio.php <run> <seconds>\n");
    exit(2);
}
[$run, $seconds] = [rtrim($argv[1], '/'), (float)$argv[2]];
[$from, $to] = [$argv[3] ?? '/workspace', $argv[4] ?? '/w'];
$events = json_decode((string)@file_get_contents("$run/audio/audio.json"), true);
$events = is_array($events) ? $events : [];
$path = fn (string $f): string => str_starts_with($f, "$from/") ? $to . substr($f, strlen($from)) : $f;
usort($events, fn ($a, $b) => $a['t'] <=> $b['t']);

// the plays: each with its file, start, how long (a music: until the next music / stop / the end), loop
$plays = [];
foreach ($events as $i => $e) {
    if ($e['t'] >= $seconds || $e['kind'] === 'stop' || !is_file((string)$e['file'])) {
        continue;
    }
    $end = null;
    if ($e['kind'] === 'music') {
        $end = $seconds;
        foreach (array_slice($events, $i + 1) as $next) {
            if ($next['kind'] === 'music' || $next['kind'] === 'stop') {
                $end = min($seconds, $next['t']);
                break;
            }
        }
    }
    $plays[] = ['file' => $path((string)$e['file']), 'at' => (float)$e['t'], 'volume' => (float)$e['volume'], 'loop' => $e['kind'] === 'music' && !empty($e['loop']), 'length' => $end === null ? null : $end - $e['t']];
}

$args = [];
$filter = [];
if (!$plays) {
    array_push($args, '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=stereo');
    $filter[] = sprintf('[1:a]atrim=0:%.3f[out]', $seconds);
} else {
    $inputs = [];
    foreach ($plays as $p) {
        $key = $p['file'] . '|' . (int)$p['loop'];
        $inputs[$key]['file'] = $p['file'];
        $inputs[$key]['loop'] = $p['loop'];
        $inputs[$key]['plays'][] = $p;
    }
    $labels = [];
    $n = 0;
    $base = 1;          // input 0 of the film is its frames: the sounds start at 1
    foreach ($inputs as $in) {
        array_push($args, ...($in['loop'] ? ['-stream_loop', '-1'] : []), ...['-i', $in['file']]);
        $count = count($in['plays']);
        $copies = array_map(fn ($c) => "s{$n}_$c", range(0, $count - 1));
        $filter[] = "[" . ($base + $n) . ":a]aformat=sample_rates=44100:channel_layouts=stereo," . ($count > 1 ? "asplit=$count" . implode('', array_map(fn ($c) => "[$c]", $copies)) : "anull[{$copies[0]}]");
        foreach ($in['plays'] as $c => $p) {
            $ms = (int)round($p['at'] * 1000);
            $trim = $p['length'] !== null ? sprintf('atrim=0:%.3f,', $p['length']) : '';
            $label = "p{$n}_$c";
            $filter[] = sprintf('[%s]%svolume=%.3f,adelay=%d|%d[%s]', $copies[$c], $trim, $p['volume'], $ms, $ms, $label);
            $labels[] = "[$label]";
        }
        $n++;
    }
    $filter[] = implode('', $labels) . sprintf('amix=inputs=%d:normalize=0:duration=longest,atrim=0:%.3f,alimiter=limit=0.95[out]', count($labels), $seconds);
}
file_put_contents("$run/audio.args", implode("\n", $args) . "\n");
file_put_contents("$run/audio.filter", implode(";\n", $filter) . "\n");
echo count($plays), " sound(s) placed\n";
