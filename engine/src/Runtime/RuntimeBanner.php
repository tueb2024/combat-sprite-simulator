<?php

namespace JumPHP\Runtime;

use JumPHP\Gui\Container;
use JumPHP\Gui\FormLoader;
use JumPHP\Kernel\Platform;
use JumPHP\Net\RuntimeLink;

/**
 * The whole "use your own network" notice of an application, in one line of a form (docs/runtime.md):
 *
 *   - {class: JumPHP\Runtime\RuntimeBanner, id: runtimeBanner}
 *
 * It only shows in a browser, when the JumPHP runtime is missing, waiting for the user's permission or refused, and until the user taps "Not now"
 * (remembered). It says what to do for the system the user is on: on Android a clear message and one button ("Start / install the app": it
 * opens the app, or the install page when it is not there), on a computer a button that opens the install guide (RuntimeGuide), when the
 * runtime is running a button that asks the permission. An iPhone is told the server is used. Props: `text` (overrides the message), `help`
 * (the page of the Android install guide, default https://jumphp.net/docs/runtime-android), `dismissible` (default true).
 */
class RuntimeBanner extends Container
{
    public const DEFAULTS = ['layout' => 'row', 'wrap' => true, 'items' => 'center', 'gap' => 8, 'padding' => 8, 'border' => true, 'background' => '#fff6e5',
        'text' => '', 'help' => 'https://jumphp.net/docs/runtime-android', 'dismissible' => true, 'visible' => false] + Container::DEFAULTS;

    private bool $built = false;
    private ?RuntimeLink $runtime = null;

    public function setRuntime(?RuntimeLink $runtime): void
    {
        $this->runtime = $runtime;
    }

    private function runtime(): RuntimeLink
    {
        return $this->runtime ?? $this->form?->engineOrNull()?->runtime() ?? new \JumPHP\Net\NoRuntime();
    }

    private function build(): void
    {
        $this->built = true;
        $this->add(
            FormLoader::widget(['class' => RuntimeBadge::class, 'id' => $this->props['id'] . 'Badge', 'compact' => true]),
            FormLoader::widget(['type' => 'label', 'id' => $this->props['id'] . 'Text', 'text' => '', 'grow' => 1, 'minWidth' => 200]),
            FormLoader::widget(['type' => 'button', 'id' => $this->props['id'] . 'Go', 'text' => 'Install', 'style' => 'primary']),
            FormLoader::widget(['type' => 'button', 'id' => $this->props['id'] . 'Later', 'text' => 'Not now', 'style' => 'flat']),
        );
        $form = $this->form;
        if ($form !== null) {
            $form->adopt($this);
        }
        $this->child('Go')->on('click', fn () => $this->go());
        $this->child('Later')->on('click', fn () => $this->dismiss());
    }

    private function child(string $suffix): \JumPHP\Gui\Widget
    {
        foreach ($this->children as $c) {
            if ($c->props['id'] === $this->props['id'] . $suffix) {
                return $c;
            }
        }
        throw new \LogicException("RuntimeBanner: no $suffix");
    }

    /** The system the user is on: android | ios | linux | windows | macos | other | null. */
    private function os(): ?string
    {
        $r = $this->runtime();
        return $r instanceof RuntimeClient ? $r->os() : null;
    }

    public function update(float $dt): void
    {
        if (!$this->built && $this->form !== null) {
            $this->build();
        }
        if (!$this->built) {
            return;
        }
        $engine = $this->form?->engineOrNull();
        $state = $this->runtime()->state();
        $dismissed = $engine !== null && (bool)$engine->options->get('runtime_banner_dismissed', false);
        $os = $this->os();
        $iphone = $os === 'ios';
        $show = (Platform::isWasm() || $this->runtime() instanceof ManualRuntime) && !$dismissed && ($iphone || in_array($state, [RuntimeLink::MISSING, RuntimeLink::RUNNING, RuntimeLink::REFUSED], true));
        if ($this->props['visible'] !== $show) {
            $this->props['visible'] = $show;
            $this->form?->invalidate();
        }
        if (!$show) {
            return;
        }
        $android = $os === 'android';
        $go = $this->child('Go');
        $go->props['visible'] = !$iphone;
        $go->props['text'] = match (true) {
            $state === RuntimeLink::MISSING && $android => 'Start / install the app',
            $state === RuntimeLink::MISSING => 'How to install',
            default => 'Allow this app',
        };
        $this->child('Later')->props['visible'] = (bool)$this->props['dismissible'];
        $title = $engine?->config->title() ?? 'This application';
        $L = fn (string $key) => \JumPHP\Util\Lang::t($key, ['title' => $title]);
        $this->child('Text')->props['text'] = (string)$this->props['text'] !== '' ? $L((string)$this->props['text']) : match (true) {
            $iphone => $L('Connections go through the jumphp.net server (the JumPHP runtime does not exist on iPhone).'),
            $state === RuntimeLink::MISSING && $android => $L('Android: to connect from your phone\'s own network (Wi-Fi at home, VPN, a server that only accepts your address), install the free "JumPHP runtime" app, open it once, then come back here. Optional: without it {title} connects through the jumphp.net server.'),
            $state === RuntimeLink::MISSING => $L('Recommended: install the free JumPHP runtime so that {title} connects from your own computer (local network, VPN, servers that only accept your address). Optional: without it, the jumphp.net server connects for you.'),
            $state === RuntimeLink::REFUSED => $L('You refused {title} on this network. Tap "Allow this app" to be asked again.'),
            default => $L('The JumPHP runtime is running: allow {title} to use your network (a question appears: choose Always).'),
        };
    }

    private function go(): void
    {
        $r = $this->runtime();
        $state = $r->state();
        if ($state === RuntimeLink::MISSING) {
            if ($this->os() === 'android' && $r instanceof RuntimeClient) {
                $r->wake((string)$this->props['help']);          // opens the app; if it is not installed, the browser goes to the install page
                return;
            }
            $this->form?->engineOrNull()?->scenes->push(RuntimeGuide::class);
            return;
        }
        $engine = $this->form?->engineOrNull();
        $r->pair($engine?->config->id() ?? '', $engine?->config->title() ?? '');
    }

    private function dismiss(): void
    {
        $this->form?->engineOrNull()?->options->set('runtime_banner_dismissed', true);
        $this->props['visible'] = false;
        $this->form?->invalidate();
    }
}
