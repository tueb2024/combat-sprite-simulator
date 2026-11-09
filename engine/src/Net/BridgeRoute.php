<?php

namespace JumPHP\Net;

use JumPHP\Kernel\GameConfig;

/**
 * Which bridge a browser connection uses, by the network mode (JUMPHP_NET_MODE / project.yaml `net_mode`):
 *  - `server`: always the server's bridge (the address in the ticket answer);
 *  - `auto` (default): the runtime when it is `ready` (installed, started, this application authorised), else the server's;
 *  - `local`: the runtime (running or ready), or an error that says why it is not available.
 * The ticket is always asked from the server; only the address the page then opens changes.
 */
final class BridgeRoute
{
    /** @throws \RuntimeException local mode and no usable runtime */
    public static function resolve(string $mode, RuntimeLink $runtime, string $serverBridge): string
    {
        $state = $runtime->state();
        if ($mode !== GameConfig::NET_LOCAL && method_exists($runtime, 'usesServer') && $runtime->usesServer()) {
            return $serverBridge;           // the user's switch: the server's proxy, the runtime stays available
        }
        return match ($mode) {
            GameConfig::NET_SERVER => $serverBridge,
            GameConfig::NET_LOCAL => in_array($state, [RuntimeLink::READY, RuntimeLink::RUNNING], true) ? $runtime->bridgeUrl()
                : throw new \RuntimeException("the JumPHP runtime is not available (state: $state); install and start it, or set net_mode to auto or server"),
            default => $state === RuntimeLink::READY ? $runtime->bridgeUrl() : $serverBridge,
        };
    }

    /** From the project's settings and the engine's runtime. */
    public static function forConfig(GameConfig $config, RuntimeLink $runtime, string $serverBridge): string
    {
        return self::resolve($config->netMode(), $runtime, $serverBridge);
    }
}
