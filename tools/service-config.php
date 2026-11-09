<?php
// The service settings of a project (project.yaml / game.yaml `service:`) as bash assignments, for tools/service-commands.sh:
//   php tools/service-config.php <project folder>
require '/workspace/engine/vendor/autoload.php';

$dir = $argv[1] ?? '.';
$config = JumPHP\Kernel\GameConfig::load($dir);
$service = $config->get('service');
$service = is_array($service) ? $service : [];
$args = $service['args'] ?? $service['command'] ?? [];
$args = is_array($args) ? array_map('strval', $args) : preg_split('/\s+/', trim((string)$args), -1, PREG_SPLIT_NO_EMPTY);
$values = [
    'SVC_ID' => (string)$config->get('id'),
    'SVC_NAME' => (string)($service['name'] ?? $config->get('id')),
    'SVC_DESCRIPTION' => (string)($service['description'] ?? $config->get('title')),
    'SVC_RESTART' => (string)($service['restart'] ?? 'on-failure'),
    'SVC_SYSTEM' => isset($service['user']) && $service['user'] === false ? '1' : '0',
    'SVC_DEFINED' => $service === [] ? '0' : '1',
];
foreach ($values as $key => $value) {
    echo $key, '=', escapeshellarg($value), "\n";
}
echo 'SVC_ARGS=(', implode(' ', array_map('escapeshellarg', $args)), ")\n";
