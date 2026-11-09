<?php

// Called by `bin/jumphp new <id> <title>` inside the new game folder (a copy of skeleton/):
// sets the id, the title and the composer package name.
[, $id, $title] = $argv + [null, null, null];
if (!$id || !preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
    fwrite(STDERR, "usage: new-game.php <id> <title>\n");
    exit(1);
}
$title = $title ?: strtoupper($id);

$yaml = file_get_contents($yamlFile = (is_file('config/project.yaml') ? 'config/project.yaml' : 'config/game.yaml'));
$yaml = preg_replace('/^(\s*id:\s*)\S+/m', '${1}' . $id, $yaml, 1);
$yaml = preg_replace('/^(\s*title:\s*).*/m', '${1}"' . addcslashes($title, '"\\') . '"', $yaml, 1);
$yaml = preg_replace('/^(\s*description:\s*).*/m', '${1}""', $yaml, 1);
file_put_contents($yamlFile, $yaml);

$composer = json_decode(file_get_contents('composer.json'), true);
$composer['name'] = 'jumphp-games/' . $id;
$composer['description'] = $title;
file_put_contents('composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$title = str_replace("'", "\\'", strtoupper($title));
$scene = file_get_contents('src/Scene/TitleScene.php');
file_put_contents('src/Scene/TitleScene.php', str_replace("protected string \$title = 'JUMPHP';", "protected string \$title = '$title';", $scene));

foreach (['AGENTS.md', 'README.md'] as $doc) {
    if (is_file($doc)) {
        file_put_contents($doc, str_replace('{{GAME_ID}}', $id, file_get_contents($doc)));
    }
}
echo "game '$id' ready\n";
