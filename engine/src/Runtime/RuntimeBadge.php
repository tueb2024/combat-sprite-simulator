<?php

namespace JumPHP\Runtime;

use JumPHP\Gui\Pointer;
use JumPHP\Gui\Theme;
use JumPHP\Gui\Widget;
use JumPHP\Net\NoRuntime;
use JumPHP\Net\RuntimeLink;
use JumPHP\Renderer\Renderer;

/**
 * The status light of the JumPHP runtime, for a Gui form (docs/runtime.md). Put it next to the connection fields:
 *
 *   - type: label ... / `{type: runtimebadge}` is not a built-in type: write  {class: JumPHP\Runtime\RuntimeBadge, id: runtime}
 *
 *   green   "Local network active"          the runtime runs and this application is authorised: connections leave from the user's network
 *   orange  "Allow this app"                the runtime is installed: a click asks the user to authorise this application (pair)
 *   red     "Install the JumPHP runtime"    nothing listens: a click opens the guide (RuntimeGuide)
 *   grey    "Server network"                the server's bridge is enough (not in a browser, a browser that blocks it, net_mode server), or still looking
 *
 * When the runtime is ready a small two-part switch "Local | Server" sits at its right: the user can go back to the server's proxy for the new
 * connections even though the local network is available (kept in the options, `net_use_server`). Props: `switch` (false hides it),
 * `text` (overrides the label), `compact` (the light alone). Events: click (always, after its own action), guide (the guide was
 * opened), paired (bool granted). `setRuntime()` gives another RuntimeLink than the engine's (tests).
 *
 * @property string $text
 * @property bool $compact
 */
class RuntimeBadge extends Widget
{
    public const DEFAULTS = ['text' => '', 'compact' => false, 'size' => null, 'switch' => true];

    public const GREEN = '#2e9e5b';
    public const ORANGE = '#e8912d';
    public const RED = '#d64545';
    public const GREY = '#9aa0aa';

    private ?RuntimeLink $runtime = null;

    public static function events(): array
    {
        return ['click', 'guide', 'paired', 'switch'];
    }

    public function setRuntime(?RuntimeLink $runtime): void
    {
        $this->runtime = $runtime;
    }

    public function runtime(): RuntimeLink
    {
        return $this->runtime ?? $this->form?->engineOrNull()?->runtime() ?? new NoRuntime();
    }

    /**
     * What a state looks like.
     *
     * @return array{kind: string, color: string, text: string}
     */
    public static function look(string $state, string $netMode = 'auto'): array
    {
        if ($netMode === 'server') {
            return ['kind' => 'grey', 'color' => self::GREY, 'text' => 'Server network'];
        }
        return match ($state) {
            RuntimeLink::READY => ['kind' => 'green', 'color' => self::GREEN, 'text' => 'Local network active'],
            RuntimeLink::RUNNING => ['kind' => 'orange', 'color' => self::ORANGE, 'text' => 'Allow this app on your network'],
            RuntimeLink::REFUSED => ['kind' => 'orange', 'color' => self::ORANGE, 'text' => 'Refused - click to ask again'],
            RuntimeLink::MISSING => ['kind' => 'red', 'color' => self::RED, 'text' => 'Local network off - install the runtime'],
            RuntimeLink::UNKNOWN, RuntimeLink::CHECKING => ['kind' => 'grey', 'color' => self::GREY, 'text' => 'Looking for the runtime...'],
            default => ['kind' => 'grey', 'color' => self::GREY, 'text' => 'Server network'],
        };
    }

    private function client(): ?RuntimeClient
    {
        $r = $this->runtime();
        return $r instanceof RuntimeClient ? $r : null;
    }

    /** The switch shows when the runtime is ready (so the choice is real) and the badge is not compact. */
    public function switchable(): bool
    {
        return (bool)$this->props['switch'] && !$this->props['compact'] && $this->client() !== null && $this->runtime()->state() === RuntimeLink::READY;
    }

    /** The user's choice, from the switch (kept for the next visit). */
    public function setUseServer(bool $on): void
    {
        $this->client()?->setUseServer($on);
        $this->form?->engineOrNull()?->options->set('net_use_server', $on);
        $this->emit('switch', $on);
    }

    /** The look now. @return array{kind: string, color: string, text: string} */
    public function current(): array
    {
        $netMode = $this->runtime === null ? ($this->form?->engineOrNull()?->config->netMode() ?? 'auto') : 'auto';
        if ($this->switchable() && $this->client()?->usesServer()) {
            return ['kind' => 'grey', 'color' => self::GREY, 'text' => $this->tr((string)$this->props['text'] !== '' ? (string)$this->props['text'] : 'Server proxy (your choice)')];
        }
        $look = self::look($this->runtime()->state(), $netMode);
        $look['text'] = $this->tr((string)$this->props['text'] !== '' ? (string)$this->props['text'] : $look['text']);
        return $look;
    }

    public function update(float $dt): void
    {
        // the first time it is shown, look for the runtime (a no-op when it already knows or is not in a browser)
        if ($this->runtime()->state() === RuntimeLink::UNKNOWN) {
            $this->runtime()->check();
        }
    }

    public function stretches(string $axis): bool
    {
        return false;
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        $dot = $t->px(14);
        if ($this->props['compact']) {
            return [$dot + $t->px(8), $t->px($t->controlHeight)];
        }
        $font = $t->font($this->props['size'] ?? $t->fontSize * 0.93, true);
        $width = 0.0;
        // the widest label it can show, so the layout does not jump when the state changes
        foreach ([$this->current()['text'], $this->tr('Local network off - install the runtime'), $this->tr('Allow this app on your network')] as $text) {
            $width = max($width, $font->width($text));
        }
        $extra = $this->switchable() ? $t->px(8) + $this->switchWidth($t) : 0.0;
        return [$t->px(12) + $dot + $t->px(8) + $width + $t->px(12) + $extra, $t->px($t->controlHeight)];
    }

    private function switchWidth(Theme $t): float
    {
        $font = $t->font($t->fontSize * 0.8, true);
        return $font->width($this->tr('Local')) + $font->width($this->tr('Server')) + $t->px(36);
    }

    /** where the switch is: [x, width of one part] */
    private function switchBox(Theme $t): array
    {
        $font = $t->font($t->fontSize * 0.8, true);
        $w = $this->switchWidth($t);
        return [$this->x + $this->w - $w - $t->px(6), $font->width($this->tr('Local')) + $t->px(18)];
    }

    public function render(Renderer $r, Theme $t): void
    {
        $look = $this->current();
        $dot = $t->px(14);
        $clickable = in_array($look['kind'], ['orange', 'red'], true);
        if (!$this->props['compact']) {
            $fill = $this->hovered && $clickable ? $t->color('hover') : $t->color('surface');
            $t->frame($r, $this->x, $this->y, $this->w, $this->h, $fill, $t->color('border'), $t->px($t->radius * 2));
        }
        $cx = $this->props['compact'] ? $this->x + $this->w / 2 : $this->x + $t->px(12) + $dot / 2;
        $cy = $this->y + $this->h / 2;
        $t->box($r, $cx - $dot / 2 - $t->px(2), $cy - $dot / 2 - $t->px(2), $dot + $t->px(4), $dot + $t->px(4), \JumPHP\Util\Color::of($look['color'])->withAlpha(70), ($dot + $t->px(4)) / 2);
        $t->box($r, $cx - $dot / 2, $cy - $dot / 2, $dot, $dot, $look['color'], $dot / 2);
        if (!$this->props['compact']) {
            $font = $t->font($this->props['size'] ?? $t->fontSize * 0.93, true);
            $font->draw($r, $look['text'], $cx + $dot / 2 + $t->px(8), $this->y + ($this->h - $font->height()) / 2, 1, $t->color('text'));
        }
        if ($this->switchable()) {
            // the half button: Local | Server, the chosen part filled
            $small = $t->font($t->fontSize * 0.8, true);
            [$sx, $local] = $this->switchBox($t);
            $total = $this->switchWidth($t);
            $h = $this->h - $t->px(10);
            $y = $this->y + $t->px(5);
            $server = $this->client()?->usesServer() ?? false;
            $t->frame($r, $sx, $y, $total, $h, $t->color('surface'), $t->color('border'), $h / 2);
            $t->box($r, $server ? $sx + $local : $sx, $y, $server ? $total - $local : $local, $h, $server ? self::GREY : self::GREEN, $h / 2);
            $ty = $y + ($h - $small->height()) / 2;
            $small->draw($r, $this->tr('Local'), $sx + $t->px(9), $ty, 1, $server ? $t->color('text') : '#ffffff');
            $small->draw($r, $this->tr('Server'), $sx + $local + $t->px(9), $ty, 1, $server ? '#ffffff' : $t->color('text'));
        }
    }

    protected function click(Pointer $p): void
    {
        $theme = $this->form?->theme;
        if ($theme !== null && $this->switchable()) {
            [$sx] = $this->switchBox($theme);
            if ($p->x >= $sx) {
                [, $local] = $this->switchBox($theme);
                $this->setUseServer($p->x >= $sx + $local);
                return;
            }
        }
        $this->activate();
    }

    public function keys(Pointer $p): bool
    {
        return false;
    }

    /** What a click does: asks the authorisation (orange), opens the guide (red), nothing for the others. */
    public function activate(): void
    {
        $state = $this->runtime()->state();
        $look = $this->current();
        if ($look['kind'] === 'orange') {
            $app = $this->form?->engineOrNull()?->config;
            $this->runtime()->pair($app?->id() ?? '', $app?->title() ?? '')
                ->then(fn ($granted) => $this->emit('paired', (bool)$granted));
        } elseif ($look['kind'] === 'red' || ($state === RuntimeLink::MISSING)) {
            $this->form?->engineOrNull()?->scenes->push(RuntimeGuide::class);
            $this->emit('guide');
        }
        $this->emit('click');
    }
}
