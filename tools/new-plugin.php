<?php

// Called by `bin/jumphp plugin:new <id> <title>` inside the new plugin folder (a copy of skeleton-plugin/): sets the
// id, the name, the composer package and a namespace of its own (JumphpPlugins\<Id>\: plugins share one PHP).
[, $id, $title] = $argv + [null, null, null];
if (!$id || !preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
    fwrite(STDERR, "usage: new-plugin.php <id> <title>\n");
    exit(1);
}
$title = $title ?: $id;
$studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $id)));
$namespace = 'JumphpPlugins\\' . $studly;

$yaml = file_get_contents($yamlFile = (is_file('config/project.yaml') ? 'config/project.yaml' : 'config/game.yaml'));
$yaml = preg_replace('/^(\s*id:\s*)\S+/m', '${1}' . $id, $yaml, 1);
$yaml = preg_replace('/^(\s*title:\s*).*/m', '${1}"' . addcslashes($title, '"\\') . '"', $yaml, 1);
file_put_contents($yamlFile, $yaml);

$composer = json_decode(file_get_contents('composer.json'), true);
$composer['name'] = 'jumphp-plugins/' . $id;
$composer['autoload']['psr-4'] = [$namespace . '\\' => 'src/'];
$composer['autoload-dev']['psr-4'] = [$namespace . '\\Tests\\' => 'tests/'];
$composer['extra']['jumphp-ide']['id'] = $id;
$composer['extra']['jumphp-ide']['name'] = $title;
$composer['extra']['jumphp-ide']['class'] = $namespace . '\\TodoPlugin';
file_put_contents('composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$files = [...glob('src/*.php'), ...glob('tests/*.php')];
foreach ($files as $file) {
    file_put_contents($file, str_replace('JumphpPlugins\\SkeletonPlugin', $namespace, file_get_contents($file)));
}
foreach (['AGENTS.md', 'README.md'] as $doc) {
    file_put_contents($doc, str_replace(['{{GAME_ID}}', 'TODO Finder'], [$id, $title], file_get_contents($doc)));
}
echo "plugin '$id' ready ($namespace)\n";
