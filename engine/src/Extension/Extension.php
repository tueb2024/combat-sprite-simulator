<?php

namespace JumPHP\Extension;

use JumPHP\Kernel\Engine;

/**
 * The boot class of an extension (composer.json extra.jumphp.boot): made by the container (autowired), boot() runs
 * once the engine is ready (SDL open, options and language loaded) and before the first scene starts.
 */
interface Extension
{
    public function boot(Engine $engine): void;
}
