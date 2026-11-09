<?php

namespace JumPHP\Tauri;

/**
 * A desktop app that also works from the command line (no window): its main class (game.yaml `main`) implements
 * this too, and cli() runs first, before the native runtime is loaded. It returns an exit code to stop there
 * (--help, --version, a silent install...), or null to open the window as usual.
 *
 *   final class Main implements AppMain, AppCli
 *   {
 *       public static function cli(array $args, string $root): ?int
 *       {
 *           if (in_array('--version', $args, true)) { echo "1.0\n"; return 0; }
 *           return null;
 *       }
 *   }
 *
 * Blocking is allowed there (no frame runs yet). Tests call Main::cli() themselves.
 */
interface AppCli
{
    /** @param list<string> $args the command line, without the program */
    public static function cli(array $args, string $root): ?int;
}
