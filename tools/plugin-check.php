<?php

// bin/jumphp test <plugin>: loads the plugin in the IDE's core on an empty project, as the IDE does; fails on an
// error or a PHP warning. Run in the plugin's folder (its vendor/ has jumphp/ide).
use JumPHP\Ide\Ide;
use JumPHP\Ide\Workspace\LocalWorkspace;

$dir = realpath($argv[1] ?? '.');
require "$dir/vendor/autoload.php";
set_error_handler(function (int $no, string $message, string $file, int $line): bool {
    fwrite(STDERR, "PHP warning: $message ($file:$line)\n");
    exit(1);
});
$project = sys_get_temp_dir() . '/plugin-check-' . getmypid();
@mkdir($project, 0777, true);
$errors = [];
$ide = new Ide(new LocalWorkspace($project), null, function (string $event, array $payload) use (&$errors): void {
    if ($event === 'ide.error') {
        $errors[] = "{$payload['where']}: {$payload['message']} ({$payload['at']})";
    }
});
$loaded = $ide->enablePlugins(null)->load($dir, 'dev');
$c = $ide->plugins->contributions();
foreach ($c['tools'] as $tool) {
    $ide->plugins->view($tool['id']);
}
exec('rm -rf ' . escapeshellarg($project));
if (($loaded['status'] ?? '') !== 'active' || $errors) {
    fwrite(STDERR, ($loaded['error'] ?? '') . "\n" . implode("\n", $errors) . "\n");
    exit(1);
}
printf("%s %s: %d action(s), %d tool window(s), %d run configuration(s), %d provider(s), %d tool(s) for Claude\n", $loaded['name'], $loaded['version'],
    count($c['actions']), count($c['tools']), count($c['run']), count($c['providers']), count($c['mcp']));
