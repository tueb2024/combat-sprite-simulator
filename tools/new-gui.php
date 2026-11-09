<?php

// Called by `bin/jumphp gui:new <id> <title> <mobile>` inside the new app folder (a copy of skeleton-gui/):
// sets the id, the title, the composer package name; a phone app (mobile 1) gets a portrait window.
[, $id, $title, $mobile] = $argv + [null, null, null, '0'];
if (!$id || !preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
    fwrite(STDERR, "usage: new-gui.php <id> <title> [mobile]\n");
    exit(1);
}
$title = $title ?: $id;

$yaml = file_get_contents($yamlFile = (is_file('config/project.yaml') ? 'config/project.yaml' : 'config/game.yaml'));
$yaml = preg_replace('/^(\s*id:\s*)\S+/m', '${1}' . $id, $yaml, 1);
$yaml = preg_replace('/^(\s*title:\s*).*/m', '${1}"' . addcslashes($title, '"\\') . '"', $yaml, 1);
if ($mobile === '1') {
    // a phone app: portrait, the size of a phone; the native apps (bin/jumphp android / apple) keep it
    $yaml = preg_replace('/^(\s*width:\s*)\d+/m', '${1}390', $yaml, 1);
    $yaml = preg_replace('/^(\s*height:\s*)\d+/m', '${1}844', $yaml, 1);
    $yaml = preg_replace('/^(\s*kind:\s*gui.*)$/m', "\$1\n  orientation: portrait         # phones: held upright", $yaml, 1);
    $yaml = preg_replace('/^(\s*pwa:.*)$/m', '', $yaml);
    $yaml .= "  pwa: {display: standalone, orientation: portrait}   # installable from the browser, as an app\n";
}
file_put_contents($yamlFile, $yaml);

$form = file_get_contents('forms/MainForm.form.yaml');
file_put_contents('forms/MainForm.form.yaml', preg_replace('/^(\s*title:\s*)Contacts$/m', '${1}' . (preg_match('/^[\w ]+$/', $title) ? $title : json_encode($title)), $form, 1));

$composer = json_decode(file_get_contents('composer.json'), true);
$composer['name'] = 'jumphp-apps/' . $id;
$composer['description'] = $title;
file_put_contents('composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

foreach (['AGENTS.md', 'README.md'] as $doc) {
    if (is_file($doc)) {
        file_put_contents($doc, str_replace(['{{GAME_ID}}', '{{TITLE}}'], [$id, $title], file_get_contents($doc)));
    }
}
echo ($mobile === '1' ? 'phone ' : '') . "app '$id' ready\n";
