<?php

declare(strict_types=1);

namespace JumPHP\Standalone\Patch;

use Package\Target\php;
use StaticPHP\Attribute\Package\BeforeStage;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Exception\BuildFailureException;
use StaticPHP\Package\PackageInstaller;

/**
 * Windows: the exported games and apps are GUI programs (tools/standalone.sh writes SUBSYSTEM:WINDOWS into the
 * executable: no console window beside them). PHP gives CREATE_NO_WINDOW to the programs it starts (proc_open,
 * popen / exec) except under the cli and micro SAPIs, which share their console with them; a micro without a
 * console (a GUI program) would then open a console window for every git / docker / cmd it runs (the JumPHP IDE).
 * Here: CREATE_NO_WINDOW for micro too when it has no console (GetConsoleWindow() == NULL); in a terminal, as before.
 */
class WindowsGui
{
    private const FILES = ['ext/standard/proc_open.c', 'TSRM/tsrm_win32.c'];

    #[BeforeStage('php', [php::class, 'buildconfForWindows'])]
    #[PatchDescription('micro without a console (GUI program): CREATE_NO_WINDOW for the programs it starts')]
    public function noConsoleForChildren(PackageInstaller $installer): void
    {
        $source = $installer->getTargetPackage('php')->getSourceDir();
        foreach (self::FILES as $file) {
            $path = "$source/$file";
            $code = (string)file_get_contents($path);
            if (str_contains($code, 'GetConsoleWindow()')) {
                continue;
            }
            $fixed = preg_replace('/strcmp\(sapi_module\.name, "micro"\) != 0\)/', '(strcmp(sapi_module.name, "micro") != 0 || GetConsoleWindow() == NULL))', $code, -1, $n);
            if ($n !== 1) {
                throw new BuildFailureException("$file: its CREATE_NO_WINDOW condition changed (docker/standalone/registry/src/Patch/WindowsGui.php)");
            }
            file_put_contents($path, $fixed);
        }
    }
}
