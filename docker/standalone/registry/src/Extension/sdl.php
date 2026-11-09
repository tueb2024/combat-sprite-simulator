<?php

declare(strict_types=1);

namespace JumPHP\Standalone\Extension;

use Package\Target\php;
use StaticPHP\Attribute\Package\BeforeStage;
use StaticPHP\Attribute\Package\Extension;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Exception\BuildFailureException;
use StaticPHP\Package\PhpExtensionPackage;

/** php-sdl: zend_exception_get_default() is gone since PHP 8.5 (as in docker/desktop and docker/engine-wasm). */
#[Extension('sdl')]
class sdl extends PhpExtensionPackage
{
    #[BeforeStage('php', [php::class, 'buildconfForUnix'], 'ext-sdl')]
    #[BeforeStage('php', [php::class, 'buildconfForWindows'], 'ext-sdl')]
    #[PatchDescription('php-sdl on PHP 8.5: zend_ce_exception; no SDLCALL before a call')]
    public function patchForPhp85(): void
    {
        foreach (glob("{$this->getSourceDir()}/src/*.[ch]") ?: [] as $file) {
            $code = (string)file_get_contents($file);
            // + `= SDLCALL SDL_SaveBMP_RW(...)`: a calling convention before a call (__cdecl on Windows: MSVC refuses it)
            $fixed = str_replace(['zend_exception_get_default()', '= SDLCALL SDL_'], ['zend_ce_exception', '= SDL_'], $code);
            if ($fixed !== $code) {
                file_put_contents($file, $fixed);
            }
        }
        // PHP's list of built-in extensions includes the ext/<name>/*.h that name phpext_ (build/print_include.awk):
        // php-sdl's is in src/
        file_put_contents("{$this->getSourceDir()}/php_sdl.h", "/* phpext_sdl_ptr */\n#include \"src/php_sdl.h\"\n");
        $this->patchEvents();
    }

    /** JumPHP's events (wheel, text input): docker/ext/php-sdl-events.patch, as the desktop runtime has it. */
    private function patchEvents(): void
    {
        $dir = $this->getSourceDir();
        if (str_contains((string)file_get_contents("$dir/src/event.c"), 'SDL_TEXTEDITING:')) {
            return;
        }
        $patch = dirname(__DIR__, 4) . '/ext/php-sdl-events.patch';
        exec('cd ' . escapeshellarg($dir) . ' && git apply -p1 ' . escapeshellarg($patch) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new BuildFailureException("php-sdl-events.patch does not apply:\n" . implode("\n", $out));
        }
    }

    #[BeforeStage('php', [php::class, 'buildconfForWindows'], 'ext-sdl')]
    #[PatchDescription('php-sdl on Windows: its config.w32 (registry/w32)')]
    public function configW32(): void
    {
        jumphp_config_w32('sdl', $this->getSourceDir());
    }
}
