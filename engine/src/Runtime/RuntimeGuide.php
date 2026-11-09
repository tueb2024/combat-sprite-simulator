<?php

namespace JumPHP\Runtime;

use JumPHP\Gui\Form;
use JumPHP\Native\Native;
use JumPHP\Net\RuntimeLink;

/**
 * The guide dialog that walks the user through installing the JumPHP runtime. Opened by a click on a red RuntimeBadge, or by hand:
 * `$this->engine->scenes->push(RuntimeGuide::class)` from a scene; the user closes it with Close (or Escape).
 *
 * It says why (the connection leaves from your own IP and network), then four steps: 1 download for your system (Linux, Windows; macOS
 * is coming), 2 install, 3 start it (a tray icon), 4 allow this application. It looks again every 2 seconds and turns green by itself.
 * The system comes from the runtime (when it answers) or the browser (JsBridge); unknown, it shows them all.
 *
 * The files are the ones of the releases tagged runtime-v<version> of https://git.jumphp.net/jumphp/jumPhp (project.yaml
 * `jumphp_runtime: {version, page, releases}`).
 */
final class RuntimeGuide extends Form
{
    public const FILES = [
        'linux' => ['label' => 'Linux', 'installer' => 'runtime-setup-linux-x86_64', 'portable' => 'runtime-linux-x86_64'],
        'windows' => ['label' => 'Windows', 'installer' => 'runtime-setup.exe', 'portable' => 'runtime-windows-x86_64.exe'],
        'android' => ['label' => 'Android', 'installer' => 'jumphp-runtime-android.apk', 'portable' => 'jumphp-runtime-android.apk'],
    ];

    private float $since = 0.0;
    /** the last address opened (tests read it) */
    public ?string $opened = null;
    private ?RuntimeLink $runtime = null;
    private string $step = 'download';

    public function setRuntime(?RuntimeLink $runtime): void
    {
        $this->runtime = $runtime;
    }

    private function runtime(): RuntimeLink
    {
        return $this->runtime ?? $this->engine->runtime();
    }

    /** @return array{version: string, android_version: string, android_help: string, page: string, releases: string} */
    public function settings(): array
    {
        $own = isset($this->engine) ? (array)$this->engine->config->get('jumphp_runtime', []) : [];
        return $own + ['version' => '0.1.6', 'android_version' => '0.3.2', 'android_help' => 'https://jumphp.net/docs/runtime-android', 'page' => 'https://jumphp.net/runtime', 'releases' => 'https://git.jumphp.net/jumphp/jumPhp/releases'];
    }

    /** The address of one file of the runtime release. */
    public function downloadUrl(string $system, bool $portable = false): string
    {
        $s = $this->settings();
        $file = self::FILES[$system][$portable ? 'portable' : 'installer'];
        // the phone's runtime is its own program (an .apk) with its own version: the release runtime-android-v<version>
        return rtrim($s['releases'], '/') . '/download/' . ($system === 'android' ? 'runtime-android-v' . $s['android_version'] : 'runtime-v' . $s['version']) . '/' . $file;
    }

    /** The system to show first: linux | windows | macos, null = show them all. */
    public function system(): ?string
    {
        $os = $this->runtime() instanceof RuntimeClient ? $this->runtime()->os() : null;
        return in_array($os, ['linux', 'windows', 'macos', 'android'], true) ? $os : null;
    }

    public function enter(array $params = []): void
    {
        $this->since = 0.0;
        $this->opened = null;
        $this->build($this->definition());
        $this->loaded($params);
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        $title = isset($this->engine) ? $this->engine->config->title() : 'This application';
        $L = fn (string $key) => \JumPHP\Util\Lang::t($key, ['title' => $title]);
        $system = $this->system();
        $phone = $system === 'android';
        $downloads = [];
        foreach (self::FILES as $id => $file) {
            $downloads[] = ['type' => 'button', 'id' => 'dl_' . $id, 'text' => $file['label'] . ($id === 'android' ? ' (.apk)' : ' (installer)'), 'style' => 'primary',
                'events' => ['click' => 'onDownload' . ucfirst($id)], 'visible' => $system === null || $system === $id];
        }
        return [
            'dialog' => true, 'theme' => 'light', 'width' => 520, 'layout' => 'column', 'padding' => 20, 'gap' => 8, 'scroll' => true,
            'children' => [
                ['type' => 'label', 'id' => 'heading', 'text' => 'Use your own network', 'style' => 'subtitle'],
                ['type' => 'label', 'id' => 'why', 'style' => 'muted', 'text' => $L($phone
                    ? '{title} can connect from your own phone and network (your IP, your VPN, a server that only opens to you) through the JumPHP runtime, a small app of your phone. It only connects where you allow, never reads your files, and you can revoke it any time.'
                    : '{title} can connect from your own computer and network (your IP, your VPN, a server that only opens to you) through the JumPHP runtime, a small program that stays in your tray. It only connects where you allow, never reads your files, and you can revoke it any time.')],
                ['type' => 'separator'],
                ['type' => 'label', 'id' => 'step1', 'text' => '1. Download for your system', 'bold' => true],
                ['type' => 'row', 'gap' => 8, 'children' => [...$downloads,
                    ['type' => 'label', 'id' => 'mac', 'text' => 'macOS: coming soon', 'style' => 'muted', 'visible' => $system === null || $system === 'macos'],
                    ['type' => 'button', 'id' => 'others', 'text' => 'Other systems', 'style' => 'flat', 'visible' => $system !== null,
                        'events' => ['click' => 'onOthers']],
                ]],
                ['type' => 'label', 'id' => 'step2', 'text' => '2. Install it', 'bold' => true],
                ['type' => 'label', 'id' => 'install', 'style' => 'muted', 'text' => $this->installText($system)],
                ['type' => 'label', 'id' => 'step3', 'text' => '3. Start it', 'bold' => true],
                ['type' => 'label', 'id' => 'start', 'style' => 'muted', 'text' => $phone
                    ? 'Open "JumPHP runtime" once and allow its notifications: it keeps running in the background (a notification shows it) and starts with your phone afterwards. On some phones (Xiaomi...) also allow "autostart" and "no battery restriction" with its buttons.'
                    : 'Open "JumPHP runtime" from your applications. An icon appears in the notification area; it starts with your session afterwards.'],
                ['type' => 'label', 'id' => 'step4', 'text' => '4. Allow this application', 'bold' => true],
                ['type' => 'label', 'id' => 'allow', 'style' => 'muted', 'text' => $L($phone
                    ? "A notification asks whether {title} may use your phone's network: tap Always (or This time). Your browser may also ask to reach devices on your local network: accept."
                    : 'The runtime asks once whether {title} may use your network. Answer Allow. Your browser may also ask to reach devices on your local network: accept.')],
                ['type' => 'button', 'id' => 'wake', 'text' => 'Start the runtime app', 'style' => 'primary', 'visible' => $phone, 'events' => ['click' => 'onWake']],
                ['type' => 'button', 'id' => 'ask', 'text' => 'Ask for the authorisation now', 'style' => 'default', 'visible' => false, 'events' => ['click' => 'onAsk']],
                ['type' => 'label', 'id' => 'status', 'text' => '', 'bold' => true],
                ['type' => 'separator'],
                ['type' => 'row', 'gap' => 8, 'children' => [
                    ['type' => 'button', 'id' => 'page', 'text' => 'jumphp.net/runtime', 'style' => 'flat', 'events' => ['click' => 'onPage']],
                    ['type' => 'button', 'id' => 'phonehelp', 'text' => 'Android help', 'style' => 'flat', 'visible' => $phone, 'events' => ['click' => 'onPhoneHelp']],
                    ['type' => 'button', 'id' => 'releases', 'text' => 'All releases', 'style' => 'flat', 'events' => ['click' => 'onReleases']],
                    ['type' => 'spacer', 'grow' => 1],
                    ['type' => 'button', 'id' => 'close', 'text' => 'Close', 'default' => true, 'cancel' => true, 'events' => ['click' => 'onClose']],
                ]],
            ],
        ];
    }

    private function installText(?string $system): string
    {
        $linux = \JumPHP\Util\Lang::t('Linux: make it executable and run it (chmod +x runtime-setup-linux-x86_64 && ./runtime-setup-linux-x86_64).');
        $windows = \JumPHP\Util\Lang::t('Windows: double-click runtime-setup.exe.');
        $android = \JumPHP\Util\Lang::t('Android: open the downloaded file jumphp-runtime-android.apk and allow installing from this browser when your phone asks (it is not on Google Play).');
        return match ($system) {
            'android' => $android,
            'linux' => $linux,
            'windows' => $windows,
            default => "$linux\n$windows\n$android",
        };
    }

    protected function loaded(array $params = []): void
    {
        $this->refresh();
        $this->runtime()->check();
    }

    protected function tick(float $dt): void
    {
        $this->since += $dt;
        if ($this->since >= 2.0) {
            $this->since = 0.0;
            $this->runtime()->check();
        }
        $this->refresh();
    }

    /** The step the user is at, from the runtime's state: download | allow | done. */
    public function step(): string
    {
        return $this->step;
    }

    /** Shows how far the user is. */
    public function refresh(): void
    {
        $state = $this->runtime()->state();
        $this->step = match ($state) {
            RuntimeLink::READY => 'done',
            RuntimeLink::RUNNING, RuntimeLink::REFUSED => 'allow',
            default => 'download',
        };
        $installed = $this->step !== 'download';
        foreach (['step1', 'step2', 'step3'] as $id) {
            $this->label($id)->color = $installed ? RuntimeBadge::GREEN : '';
        }
        $n = ['step1' => 1, 'step2' => 2, 'step3' => 3];
        foreach ($n as $id => $i) {
            $this->label($id)->text = ($installed ? '[done] ' : '') . "$i. " . ['Download for your system', 'Install it', 'Start it'][$i - 1];
        }
        $this->label('step4')->text = ($this->step === 'done' ? '[done] ' : '') . '4. Allow this application';
        $this->label('step4')->color = $this->step === 'done' ? RuntimeBadge::GREEN : '';
        $this->widget('ask')->visible = $this->step === 'allow';
        $status = $this->label('status');
        [$status->text, $status->color] = match ($this->step) {
            'done' => ['Local network active. You can close this window.', RuntimeBadge::GREEN],
            'allow' => [$state === RuntimeLink::REFUSED ? 'You refused. Click "Ask" to be asked again.' : 'The runtime is running. Allow this application in its window.', RuntimeBadge::ORANGE],
            default => ['Waiting for the runtime... (looking again every 2 seconds)', RuntimeBadge::GREY],
        };
        $this->label('close')->text = $this->step === 'done' ? 'Done' : 'Close';
    }

    private function open(string $url): void
    {
        $this->opened = $url;
        Native::provider()->openUrl($url);
    }

    public function onDownloadLinux(mixed $sender = null): void { $this->open($this->downloadUrl('linux')); }
    public function onDownloadWindows(mixed $sender = null): void { $this->open($this->downloadUrl('windows')); }
    public function onDownloadAndroid(mixed $sender = null): void { $this->open($this->downloadUrl('android')); }
    public function onWake(mixed $sender = null): void
    {
        $r = $this->runtime();
        if ($r instanceof RuntimeClient) {
            $r->wake($this->settings()['android_help']);
        }
    }

    public function onPhoneHelp(mixed $sender = null): void { $this->open($this->settings()['android_help']); }
    public function onPage(mixed $sender = null): void { $this->open($this->settings()['page']); }
    public function onReleases(mixed $sender = null): void { $this->open($this->settings()['releases']); }

    public function onOthers(mixed $sender = null): void
    {
        foreach (array_keys(self::FILES) as $id) {
            $this->widget('dl_' . $id)->visible = true;
        }
        $this->widget('mac')->visible = true;
        $this->widget('others')->visible = false;
        $this->label('install')->text = $this->installText(null);
    }

    public function onAsk(mixed $sender = null): void
    {
        $this->runtime()->pair($this->engine->config->id(), $this->engine->config->title());
    }

    public function onClose(mixed $sender = null): void
    {
        $this->close($this->step === 'done');
    }

    /** @return \JumPHP\Gui\Label a label of the guide by its id */
    private function label(string $id): object
    {
        /** @var \JumPHP\Gui\Label $w */
        $w = $this->widget($id);
        return $w;
    }
}
