<?php

// Precompiles the service container of a game into <game>/cache/container.php: the browser loads it instead
// of reading YAML and autowiring at each start (~0.5 s saved). Called by tools/stage-web.sh.
use JumPHP\Kernel\Application;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;

$root = rtrim($argv[1] ?? getcwd(), '/');
require $root . '/vendor/autoload.php';

$container = Application::buildContainer($root);
$container->compile();
@mkdir($root . '/cache', 0777, true);
file_put_contents($root . '/cache/container.php', (new PhpDumper($container))->dump(['class' => Application::CACHED_CONTAINER_CLASS]));
echo "container: $root/cache/container.php\n";
