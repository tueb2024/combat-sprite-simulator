<?php

// Called by `bin/jumphp extension:new <id> <title>` from the repository root, once skeleton-extension/ is copied to
// extensions/<id> (the package) and games/<id>-demo (its example game): names, namespace, package, config key.
[, $id, $title] = $argv + [null, null, null];
if (!$id || !preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
    fwrite(STDERR, "usage: new-extension.php <id> <title>\n");
    exit(1);
}
$title = $title ?: $id;
$studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $id)));
$key = str_replace('-', '_', $id);
$replace = [
    'JumphpExtensions\\\\SkeletonExtension' => 'JumphpExtensions\\\\' . $studly,     // in JSON
    'JumphpExtensions\\SkeletonExtension' => 'JumphpExtensions\\' . $studly,
    'SkeletonExtension' => $studly,
    'skeleton-extension-demo' => "$id-demo",
    'skeleton-extension' => $id,
    'skeleton_extension' => $key,
    'Skeleton Extension' => $title,
];
foreach (["extensions/$id", "games/$id-demo"] as $root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getPathname();
        if (preg_match('/\.(php|json|yaml|md)$/', $path)) {
            file_put_contents($path, strtr((string)file_get_contents($path), $replace));
        }
    }
}
// the example game finds (and autowires) the samples of the extension where they are, so that the IDE can run one from its file
$demoComposer = "games/$id-demo/composer.json";
$json = json_decode((string)file_get_contents($demoComposer), true) ?: [];
$json['autoload']['psr-4']["App\\Extensions\\$studly\\"] = "../../extensions/$id/doc/samples/";
file_put_contents($demoComposer, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$services = "games/$id-demo/config/services.yaml";
if (is_file($services) && !str_contains((string)file_get_contents($services), 'App\\Extensions')) {
    file_put_contents($services, rtrim((string)file_get_contents($services)) . "\n\n  # the samples of the extension (extensions/$id/doc/samples): scenes the IDE runs from their file, without adopting them into this game\n  App\\Extensions\\$studly\\:\n    resource: '../../../extensions/$id/doc/samples/*'\n");
}
rename("extensions/$id/src/SkeletonExtension.php", "extensions/$id/src/$studly.php");
echo "extension '$id' ready (JumphpExtensions\\$studly), its example game games/$id-demo\n";
