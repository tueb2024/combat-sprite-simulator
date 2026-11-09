<?php

// bin/jumphp plugin:pack <plugin>: dist/<id>-<version>.zip of the plugin's folder (JumPHP\Ide\Plugin\PluginPackage:
// the folder at the zip's root, without vendor/, var/, tests/, dist/), what the IDE installs and jumphp.net publishes.
// Run in the plugin's folder (its vendor/ has jumphp/ide).
use JumPHP\Ide\Plugin\PluginPackage;

$dir = realpath($argv[1] ?? '.');
require "$dir/vendor/autoload.php";
try {
    $zip = PluginPackage::pack($dir);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
printf("%s (%d files, %d KB)\nInstall it in the JumPHP IDE (Tools > Plugins…) or publish it there on jumphp.net.\n", $zip['file'], $zip['files'], intdiv($zip['size'] + 1023, 1024));
