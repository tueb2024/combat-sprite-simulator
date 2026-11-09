<?php

// Called by `bin/jumphp app:new <id> <title>` inside the new app folder (a copy of skeleton-app/):
// sets the id, the title and the composer package name.
[, $id, $title] = $argv + [null, null, null];
if (!$id || !preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
    fwrite(STDERR, "usage: new-app.php <id> <title>\n");
    exit(1);
}
$title = $title ?: $id;

$yaml = file_get_contents($yamlFile = (is_file('config/project.yaml') ? 'config/project.yaml' : 'config/game.yaml'));
$yaml = preg_replace('/^(\s*id:\s*)\S+/m', '${1}' . $id, $yaml, 1);
$yaml = preg_replace('/^(\s*title:\s*).*/m', '${1}"' . addcslashes($title, '"\\') . '"', $yaml, 1);
file_put_contents($yamlFile, $yaml);

$composer = json_decode(file_get_contents('composer.json'), true);
$composer['name'] = 'jumphp-apps/' . $id;
$composer['description'] = $title;
file_put_contents('composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$html = htmlspecialchars($title, ENT_QUOTES);
foreach (['ui/index.html'] as $page) {
    if (is_file($page)) {
        file_put_contents($page, str_replace('JumPHP App', $html, file_get_contents($page)));
    }
}
foreach (['AGENTS.md', 'README.md'] as $doc) {
    if (is_file($doc)) {
        file_put_contents($doc, str_replace('{{GAME_ID}}', $id, file_get_contents($doc)));
    }
}
echo "app '$id' ready\n";
