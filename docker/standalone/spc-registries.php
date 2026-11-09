<?php
// StaticPHP 3.0.0-alpha1: the packages and artifacts of a registry loaded after the core one (SPC_REGISTRIES) are
// never instantiated (the loaders build their instances once, on the first registry). Built for each registry here.
foreach (['src/StaticPHP/Registry/ArtifactLoader.php' => 'artifacts', 'src/StaticPHP/Registry/PackageLoader.php' => 'packages'] as $file => $var) {
    $code = file_get_contents($file);
    $old = "if (self::\${$var} !== null) {\n            return;\n        }";
    if (!str_contains($code, $old)) {
        fwrite(STDERR, "$file: not patched (StaticPHP changed)\n");
        exit(1);
    }
    $code = str_replace($old, '', $code);
    $code = preg_replace('/(foreach \((?:ArtifactConfig|PackageConfig)::getAll\(\) as \$name => \$item\) \{)/', "$1\n            if (isset(self::\${$var}[\$name])) {\n                continue;\n            }", $code, 1, $n);
    if ($n !== 1) {
        fwrite(STDERR, "$file: not patched\n");
        exit(1);
    }
    file_put_contents($file, $code);
}
