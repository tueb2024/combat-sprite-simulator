<?php
/**
 * The subset of GitHub's `gh` that JumPHP uses, for a project on git.jumphp.net (a Forgejo/Gitea forge). Called by
 * tools/bin/gh (which sends GitHub projects to the real gh): FORGE_HOST says which forge, FORGE_REPO the project's repository.
 *
 *   release create|view|list|upload|download|edit|delete   run list|view|watch|download   workflow run|list
 *   repo create|view   secret set|list   auth status|login   api
 *
 * Same flags and, with --json/-q, the same GitHub-shaped fields and a small jq subset (.a  .[].a  .[0].a  .a[].b).
 * No dependency: PHP 8.1+ (curl extension or streams). Never prints a token.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

final class Forge
{
    public string $host;
    public string $api;

    public function __construct(string $host)
    {
        $this->host = $host;
        $this->api = "https://$host/api/v1";
    }

    public function token(): ?string
    {
        if (($t = getenv('JUMPHP_FORGE_TOKEN')) !== false && $t !== '') {
            return $t;
        }
        $home = getenv('HOME') ?: '';
        foreach ([$home . '/.config/jumphp/forge-tokens/' . $this->host] as $file) {
            if (is_file($file) && ($t = trim((string)file_get_contents($file))) !== '') {
                return $t;
            }
        }
        foreach ([$home . '/.git-credentials', '/tmp/.git-credentials'] as $file) {
            if (!is_file($file)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (preg_match('#^https?://[^:@/]+:([^@/]+)@' . preg_quote($this->host, '#') . '(?::\d+)?$#', trim($line), $m)) {
                    return rawurldecode($m[1]);
                }
            }
        }
        return null;
    }

    /** @return array{0: int, 1: string} status, body */
    public function request(string $method, string $path, mixed $body = null, array $headers = [], ?string $uploadFile = null, ?string $saveTo = null): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->api . $path;
        $h = ['Accept: application/json', 'User-Agent: jumphp-gh'];
        if ($t = $this->token()) {
            $h[] = 'Authorization: token ' . $t;
        }
        $payload = null;
        if ($uploadFile !== null) {
            $payload = ['attachment' => new CURLFile($uploadFile, null, basename($uploadFile))];
        } elseif ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $h[] = 'Content-Type: application/json';
        }
        $ch = curl_init($url);
        $out = $saveTo !== null ? fopen($saveTo, 'wb') : null;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge($h, $headers),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 3600,
            CURLOPT_RETURNTRANSFER => $out === null,
        ] + ($payload !== null ? [CURLOPT_POSTFIELDS => $payload] : []) + ($out !== null ? [CURLOPT_FILE => $out] : []));
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($response === false) {
            fwrite(STDERR, 'gh: ' . curl_error($ch) . "\n");
            $status = 0;
        }
        if ($out) {
            fclose($out);
        }
        return [$status, is_string($response) ? $response : ''];
    }

    public function json(string $method, string $path, mixed $body = null): ?array
    {
        [$status, $text] = $this->request($method, $path, $body);
        if ($status >= 400 || $status === 0) {
            $msg = (json_decode($text, true)['message'] ?? $text);
            fwrite(STDERR, "gh: HTTP $status on $method $path: " . substr((string)$msg, 0, 300) . "\n");
            return null;
        }
        $data = json_decode($text, true);
        return is_array($data) ? $data : [];
    }
}

/** the tiny jq: .a  .[].a  .[0].a  .a[].b  (strings are printed raw, like gh -q) */
function jq(mixed $data, string $expr): void
{
    preg_match_all('/\.?([A-Za-z_]\w*)|\[(\d*)\]/', $expr, $tokens, PREG_SET_ORDER);
    $values = [$data];
    foreach ($tokens as $t) {
        $next = [];
        foreach ($values as $v) {
            if (isset($t[2]) && array_key_exists(2, $t)) {
                if ($t[2] === '') {
                    foreach ((array)$v as $item) {
                        $next[] = $item;
                    }
                } elseif (is_array($v) && isset($v[(int)$t[2]])) {
                    $next[] = $v[(int)$t[2]];
                }
            } elseif (is_array($v) && array_key_exists($t[1], $v)) {
                $next[] = $v[$t[1]];
            }
        }
        $values = $next;
    }
    foreach ($values as $v) {
        echo is_scalar($v) ? (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v) : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    }
}

/** @return array{0: list<string>, 1: array<string, mixed>} positional arguments and flags */
function parseArgs(array $argv): array
{
    $withValue = ['-R', '--repo', '-f', '-F', '--field', '--raw-field', '--json', '-q', '--jq', '-L', '--limit', '--workflow', '--ref', '-p', '--pattern',
        '-D', '--dir', '--target', '--title', '-t', '--notes', '-n', '--source', '--remote', '--description', '-d', '-X', '--method', '-H', '--header',
        '-b', '--body', '-i', '--interval', '--notes-file', '-F', '--name'];
    $pos = [];
    $flags = [];
    for ($i = 0; $i < count($argv); $i++) {
        $a = $argv[$i];
        if ($a === '--') {
            $pos = array_merge($pos, array_slice($argv, $i + 1));
            break;
        }
        if (str_starts_with($a, '--') && str_contains($a, '=')) {
            [$k, $v] = explode('=', $a, 2);
            $flags[$k][] = $v;
        } elseif (str_starts_with($a, '-') && strlen($a) > 1 && !ctype_digit(substr($a, 1))) {
            if (in_array($a, $withValue, true)) {
                $flags[$a][] = $argv[++$i] ?? '';
            } else {
                $flags[$a][] = true;
            }
        } else {
            $pos[] = $a;
        }
    }
    return [$pos, $flags];
}

function flag(array $flags, array $names, mixed $default = null): mixed
{
    foreach ($names as $n) {
        if (isset($flags[$n])) {
            return end($flags[$n]);
        }
    }
    return $default;
}

function repoOf(array $flags): string
{
    $r = (string)flag($flags, ['-R', '--repo'], getenv('FORGE_REPO') ?: '');
    if (substr_count($r, '/') === 2) {
        $r = substr($r, strpos($r, '/') + 1); // host/owner/name
    }
    $r = preg_replace('#\.git$#', '', trim($r, '/'));
    if (!preg_match('#^[\w.-]+/[\w.-]+$#', (string)$r)) {
        fwrite(STDERR, "gh: no repository: -R owner/name, or run it in a folder whose remote origin is on this forge\n");
        exit(1);
    }
    return $r;
}

function emit(mixed $data, array $flags, ?callable $shape = null): void
{
    if ($shape) {
        $data = $shape($data, (string)flag($flags, ['--json'], ''));
    }
    if (($q = flag($flags, ['-q', '--jq'])) !== null) {
        jq($data, (string)$q);
        return;
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
}

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, "gh: $msg\n");
    exit($code);
}

function releaseShape(array $r, string $fields): array
{
    $assets = array_map(fn ($a) => ['id' => $a['id'] ?? 0, 'name' => $a['name'] ?? '', 'size' => $a['size'] ?? 0, 'url' => $a['browser_download_url'] ?? '', 'downloadCount' => $a['download_count'] ?? 0], $r['assets'] ?? []);
    $all = ['tagName' => $r['tag_name'] ?? '', 'name' => $r['name'] ?? '', 'body' => $r['body'] ?? '', 'url' => $r['html_url'] ?? '', 'isDraft' => (bool)($r['draft'] ?? false),
        'isPrerelease' => (bool)($r['prerelease'] ?? false), 'createdAt' => $r['created_at'] ?? '', 'publishedAt' => $r['published_at'] ?? '', 'assets' => $assets, 'id' => $r['id'] ?? 0];
    return $fields === '' ? $all : array_intersect_key($all, array_flip(array_map('trim', explode(',', $fields))));
}

function uploadAsset(Forge $f, string $repo, int $releaseId, string $file, bool $clobber): void
{
    if (!is_file($file)) {
        fail("no such file: $file");
    }
    $name = basename($file);
    $release = $f->json('GET', "/repos/$repo/releases/$releaseId") ?? [];
    foreach ($release['assets'] ?? [] as $a) {
        if (($a['name'] ?? '') === $name) {
            if (!$clobber) {
                fail("asset $name already exists (use --clobber)");
            }
            $f->request('DELETE', "/repos/$repo/releases/$releaseId/assets/" . $a['id']);
        }
    }
    [$status, $text] = $f->request('POST', "/repos/$repo/releases/$releaseId/assets?name=" . rawurlencode($name), null, [], $file);
    if ($status >= 400 || $status === 0) {
        fail("upload of $name failed: HTTP $status " . substr($text, 0, 200));
    }
}

function runShape(array $r): array
{
    $status = (string)($r['status'] ?? '');
    $inProgress = in_array($status, ['running', 'in_progress'], true);
    $queued = in_array($status, ['waiting', 'queued', 'blocked', 'pending'], true);
    return ['databaseId' => $r['id'] ?? 0, 'status' => $inProgress ? 'in_progress' : ($queued ? 'queued' : 'completed'),
        'conclusion' => ($inProgress || $queued) ? '' : $status, 'workflowName' => $r['workflow_id'] ?? '', 'url' => $r['html_url'] ?? '',
        'headBranch' => $r['head_branch'] ?? '', 'headSha' => $r['head_sha'] ?? '', 'event' => $r['event'] ?? '', 'displayTitle' => $r['display_title'] ?? ($r['title'] ?? '')];
}

/** @return list<array> the runs, newest first */
function runs(Forge $f, string $repo, int $limit = 50): array
{
    $data = $f->json('GET', "/repos/$repo/actions/runs?limit=$limit") ?? [];
    return $data['workflow_runs'] ?? [];
}

function repoFromGit(): ?string
{
    return null;
}

// ---------------------------------------------------------------------------------------------------------------------

$host = getenv('FORGE_HOST') ?: '';
if ($host === '') {
    fail('FORGE_HOST is not set: run it through tools/bin/gh');
}
$forge = new Forge($host);
$argv = $_SERVER['argv'];
array_shift($argv);
$area = array_shift($argv) ?? '';
[$pos, $flags] = parseArgs($argv);
$cmd = array_shift($pos) ?? '';

switch ($area) {
    case 'auth':
        if ($cmd === 'login') {
            $stdin = !posix_isatty(STDIN) ? trim((string)stream_get_contents(STDIN)) : '';
            if ($stdin === '') {
                fwrite(STDERR, "Make a token at https://$host/user/settings/applications (scopes: write:repository), paste it here: ");
                $stdin = trim((string)fgets(STDIN));
            }
            $dir = (getenv('HOME') ?: '/tmp') . '/.config/jumphp/forge-tokens';
            @mkdir($dir, 0700, true);
            file_put_contents("$dir/$host", $stdin . "\n");
            chmod("$dir/$host", 0600);
            echo "Token saved for $host\n";
            exit(0);
        }
        $me = $forge->token() ? $forge->json('GET', '/user') : null;
        if (!$me) {
            fwrite(STDERR, "$host\n  X Not logged in: gh auth login (or JUMPHP_FORGE_TOKEN)\n");
            exit(1);
        }
        echo "$host\n  ✓ Logged in to $host as {$me['login']}\n";
        exit(0);

    case 'release':
        $repo = repoOf($flags);
        $tag = $pos[0] ?? '';
        switch ($cmd) {
            case 'create':
                $notes = (string)flag($flags, ['--notes', '-n'], '');
                if (($nf = flag($flags, ['--notes-file', '-F'])) !== null) {
                    $notes = (string)file_get_contents((string)$nf);
                }
                $r = $forge->json('POST', "/repos/$repo/releases", array_filter([
                    'tag_name' => $tag, 'name' => (string)flag($flags, ['--title', '-t'], $tag), 'body' => $notes,
                    'target_commitish' => flag($flags, ['--target']), 'draft' => isset($flags['--draft']), 'prerelease' => isset($flags['--prerelease']),
                ], fn ($v) => $v !== null));
                $r || exit(1);
                foreach (array_slice($pos, 1) as $file) {
                    uploadAsset($forge, $repo, (int)$r['id'], $file, true);
                }
                echo $r['html_url'], "\n";
                exit(0);
            case 'view':
                $r = $forge->request('GET', "/repos/$repo/releases/tags/" . rawurlencode($tag));
                if ($r[0] === 404) {
                    fail("release not found");
                }
                $r[0] >= 400 && fail("HTTP $r[0]");
                emit(json_decode($r[1], true), $flags, fn ($d, $fields) => releaseShape($d, $fields));
                exit(0);
            case 'list':
                $data = $forge->json('GET', "/repos/$repo/releases?limit=" . (int)flag($flags, ['-L', '--limit'], 30)) ?? exit(1);
                emit(array_map(fn ($r) => releaseShape($r, (string)flag($flags, ['--json'], '')), $data), $flags);
                exit(0);
            case 'upload':
                $r = $forge->request('GET', "/repos/$repo/releases/tags/" . rawurlencode($tag));
                $r[0] === 200 || fail("release $tag not found");
                $id = (int)json_decode($r[1], true)['id'];
                foreach (array_slice($pos, 1) as $file) {
                    uploadAsset($forge, $repo, $id, $file, isset($flags['--clobber']));
                }
                exit(0);
            case 'download':
                $path = $tag === '' ? '/repos/' . $repo . '/releases/latest' : "/repos/$repo/releases/tags/" . rawurlencode($tag);
                $r = $forge->json('GET', $path) ?? exit(1);
                $dir = (string)flag($flags, ['-D', '--dir'], '.');
                @mkdir($dir, 0777, true);
                $patterns = $flags['-p'] ?? ($flags['--pattern'] ?? []);
                $n = 0;
                foreach ($r['assets'] ?? [] as $a) {
                    $ok = !$patterns;
                    foreach ($patterns as $p) {
                        $ok = $ok || fnmatch((string)$p, $a['name']);
                    }
                    if (!$ok) {
                        continue;
                    }
                    [$status] = $forge->request('GET', $a['browser_download_url'], null, ['Accept: application/octet-stream'], null, "$dir/{$a['name']}");
                    $status === 200 || fail("download of {$a['name']} failed (HTTP $status)");
                    $n++;
                }
                $n > 0 || fail('no assets match');
                exit(0);
            case 'edit':
                $r = $forge->json('GET', "/repos/$repo/releases/tags/" . rawurlencode($tag)) ?? exit(1);
                $patch = [];
                if (isset($flags['--title']) || isset($flags['-t'])) {
                    $patch['name'] = (string)flag($flags, ['--title', '-t']);
                }
                if (isset($flags['--notes']) || isset($flags['-n'])) {
                    $patch['body'] = (string)flag($flags, ['--notes', '-n']);
                }
                if (isset($flags['--draft'])) {
                    $patch['draft'] = flag($flags, ['--draft']) !== 'false';
                }
                if (isset($flags['--prerelease'])) {
                    $patch['prerelease'] = flag($flags, ['--prerelease']) !== 'false';
                }
                // --latest: a forge's "latest" is its newest published, non-draft, non-prerelease release: nothing to set
                $patch && $forge->json('PATCH', '/repos/' . $repo . '/releases/' . $r['id'], $patch);
                exit(0);
            case 'delete':
                $r = $forge->json('GET', "/repos/$repo/releases/tags/" . rawurlencode($tag)) ?? exit(1);
                $forge->request('DELETE', '/repos/' . $repo . '/releases/' . $r['id']);
                if (isset($flags['--cleanup-tag'])) {
                    $forge->request('DELETE', "/repos/$repo/tags/" . rawurlencode($tag));
                }
                exit(0);
        }
        break;

    case 'workflow':
        $repo = repoOf($flags);
        if ($cmd === 'run') {
            $file = $pos[0] ?? fail('workflow file needed');
            $ref = (string)flag($flags, ['--ref'], '');
            if ($ref === '') {
                $ref = (string)($forge->json('GET', "/repos/$repo")['default_branch'] ?? 'main');
            }
            $inputs = [];
            foreach (array_merge($flags['-f'] ?? [], $flags['-F'] ?? [], $flags['--raw-field'] ?? []) as $kv) {
                if (is_string($kv) && str_contains($kv, '=')) {
                    [$k, $v] = explode('=', $kv, 2);
                    $inputs[$k] = $v;
                }
            }
            [$status, $text] = $forge->request('POST', "/repos/$repo/actions/workflows/" . rawurlencode($file) . '/dispatches', ['ref' => $ref, 'inputs' => (object)$inputs]);
            if ($status >= 400 || $status === 0) {
                fail("could not start $file: HTTP $status " . substr((string)(json_decode($text, true)['message'] ?? $text), 0, 200));
            }
            echo "✓ Created workflow_dispatch event for $file at $ref\n";
            exit(0);
        }
        if ($cmd === 'list') {
            foreach (['.forgejo/workflows', '.gitea/workflows', '.github/workflows'] as $dir) {
                foreach ($forge->json('GET', "/repos/$repo/contents/$dir") ?? [] as $f) {
                    echo $f['name'] ?? '', "\tactive\n";
                }
            }
            exit(0);
        }
        break;

    case 'run':
        $repo = repoOf($flags);
        switch ($cmd) {
            case 'list':
                $wf = flag($flags, ['--workflow', '-w']);
                $list = array_values(array_filter(runs($forge, $repo), fn ($r) => $wf === null || ($r['workflow_id'] ?? '') === $wf));
                $list = array_slice($list, 0, (int)flag($flags, ['-L', '--limit'], 20));
                emit(array_map('runShape', $list), $flags);
                exit(0);
            case 'view':
                $id = (int)($pos[0] ?? 0);
                foreach (runs($forge, $repo, 100) as $r) {
                    if ((int)$r['id'] === $id) {
                        $s = runShape($r);
                        echo "{$s['workflowName']} #$id: {$s['status']} {$s['conclusion']}\n{$s['url']}\n";
                        if (isset($flags['--log-failed']) || isset($flags['--log'])) {
                            echo "(the logs of a forge run are on its page: {$s['url']})\n";
                        }
                        exit(0);
                    }
                }
                fail("run $id not found");
            case 'watch':
                $id = (int)($pos[0] ?? 0);
                $interval = max(2, (int)flag($flags, ['-i', '--interval'], 5));
                while (true) {
                    $found = null;
                    foreach (runs($forge, $repo, 100) as $r) {
                        if ((int)$r['id'] === $id) {
                            $found = runShape($r);
                        }
                    }
                    $found || fail("run $id not found");
                    if ($found['status'] === 'completed') {
                        echo "Run #$id completed: {$found['conclusion']}\n";
                        exit(isset($flags['--exit-status']) && $found['conclusion'] !== 'success' ? 1 : 0);
                    }
                    sleep($interval);
                }
            case 'download':
                $id = (int)($pos[0] ?? 0);
                $dir = (string)flag($flags, ['-D', '--dir'], '.');
                $only = flag($flags, ['-n', '--name']);
                $arts = $forge->json('GET', "/repos/$repo/actions/runs/$id/artifacts") ?? exit(1);
                $n = 0;
                foreach ($arts['artifacts'] ?? $arts as $a) {
                    if (!is_array($a) || ($only !== null && ($a['name'] ?? '') !== $only)) {
                        continue;
                    }
                    $target = $dir . '/' . $a['name'];
                    @mkdir($target, 0777, true);
                    $zip = "$target.zip";
                    [$status] = $forge->request('GET', "/repos/$repo/actions/artifacts/{$a['id']}/zip", null, ['Accept: application/zip'], null, $zip);
                    $status === 200 || fail("artifact {$a['name']}: HTTP $status");
                    if (class_exists('ZipArchive')) {
                        $z = new ZipArchive();
                        $z->open($zip) && $z->extractTo($target) && $z->close();
                    } else {
                        passthru('unzip -o -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($target));
                    }
                    @unlink($zip);
                    $n++;
                }
                $n > 0 || fail('no artifact');
                exit(0);
        }
        break;

    case 'repo':
        switch ($cmd) {
            case 'view':
                $repo = $pos[0] ?? repoOf($flags);
                if (substr_count($repo, '/') === 2) {
                    $repo = substr($repo, strpos($repo, '/') + 1);
                }
                $r = $forge->json('GET', "/repos/$repo") ?? exit(1);
                emit($r, $flags, fn ($d, $fields) => array_intersect_key(
                    ['url' => $d['html_url'], 'name' => $d['name'], 'nameWithOwner' => $d['full_name'], 'visibility' => $d['private'] ? 'PRIVATE' : 'PUBLIC',
                     'description' => $d['description'] ?? '', 'defaultBranchRef' => ['name' => $d['default_branch'] ?? 'main'], 'isPrivate' => (bool)$d['private']],
                    array_flip($fields === '' ? ['url', 'name', 'nameWithOwner', 'visibility'] : array_map('trim', explode(',', $fields)))));
                exit(0);
            case 'create':
                $full = $pos[0] ?? fail('repository name needed');
                [$owner, $name] = str_contains($full, '/') ? explode('/', $full, 2) : [null, $full];
                $me = $forge->json('GET', '/user') ?? exit(1);
                $body = ['name' => $name, 'private' => isset($flags['--private']), 'description' => (string)flag($flags, ['--description', '-d'], ''), 'auto_init' => false];
                $r = $forge->json('POST', $owner !== null && $owner !== $me['login'] ? "/orgs/$owner/repos" : '/user/repos', $body) ?? exit(1);
                echo $r['html_url'], "\n";
                if (isset($flags['--source'])) {
                    $remote = (string)flag($flags, ['--remote'], 'origin');
                    $source = (string)flag($flags, ['--source'], '.');
                    $git = static fn (string $c) => passthru('git -C ' . escapeshellarg($source) . ' ' . $c, $code) === false ? 1 : $code;
                    $git("remote add $remote " . escapeshellarg($r['clone_url'])) && $git("remote set-url $remote " . escapeshellarg($r['clone_url']));
                    if (isset($flags['--push'])) {
                        exit($git("push -u $remote HEAD"));
                    }
                }
                exit(0);
        }
        break;

    case 'secret':
        $repo = repoOf($flags);
        if ($cmd === 'list') {
            foreach (($forge->json('GET', "/repos/$repo/actions/secrets") ?? []) as $s) {
                echo ($s['name'] ?? ''), "\t", ($s['created_at'] ?? ''), "\n";
            }
            exit(0);
        }
        if ($cmd === 'set') {
            $name = $pos[0] ?? fail('secret name needed');
            $value = isset($flags['--body']) || isset($flags['-b']) ? (string)flag($flags, ['--body', '-b']) : rtrim((string)stream_get_contents(STDIN), "\r\n");
            [$status, $text] = $forge->request('PUT', "/repos/$repo/actions/secrets/" . rawurlencode($name), ['data' => $value]);
            $status < 400 && $status > 0 || fail("could not set $name: HTTP $status " . substr($text, 0, 200));
            echo "✓ Set secret $name for $repo\n";
            exit(0);
        }
        break;

    case 'api':
        $path = $cmd;
        $method = strtoupper((string)flag($flags, ['-X', '--method'], isset($flags['-f']) || isset($flags['-F']) ? 'POST' : 'GET'));
        $body = [];
        foreach (array_merge($flags['-f'] ?? [], $flags['-F'] ?? []) as $kv) {
            if (is_string($kv) && str_contains($kv, '=')) {
                [$k, $v] = explode('=', $kv, 2);
                $body[$k] = $v;
            }
        }
        [$status, $text] = $forge->request($method, '/' . ltrim(preg_replace('#^/?(api/v\d/)?#', '', $path), '/'), $body ?: null);
        echo $text, "\n";
        exit($status >= 400 || $status === 0 ? 1 : 0);
}

fail("'$area $cmd' is not supported on $host (the GitHub CLI features JumPHP uses are; see tools/forge.php)");
