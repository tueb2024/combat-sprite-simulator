<?php

namespace JumPHP\Tauri;

use JumPHP\Native\Clipboard;
use JumPHP\Native\Dialog;
use JumPHP\Native\FilePicker;
use JumPHP\Native\Notification;
use JumPHP\Native\Shell;

/**
 * The native features a page can ask for without PHP code of its own (game.yaml app.native_commands: false
 * removes them). They go through PHP (JumPHP\Native), like the app's own commands:
 *
 *   await jumphp.invoke('native.message', {message: 'Saved', title: 'Notes', kind: 'info'});
 *   const yes = await jumphp.invoke('native.confirm', {message: 'Delete?', ok: 'Delete', cancel: 'Keep'});
 *   const file = await jumphp.invoke('native.open', {filters: {Images: ['png', 'jpg']}});  // null: cancelled
 *   const files = await jumphp.invoke('native.open', {multiple: true});
 *   const dir = await jumphp.invoke('native.open', {folder: true});
 *   const out = await jumphp.invoke('native.save', {file_name: 'notes.txt'});
 *   await jumphp.invoke('native.notify', {title: 'Done', body: 'Export finished'});
 *   await jumphp.invoke('native.openUrl', {url: 'https://jumphp.net'});
 *   await jumphp.invoke('native.copy', {text: 'hello'});
 *   const text = await jumphp.invoke('native.paste');
 *   const s = await jumphp.invoke('native.autostart');               // {supported, enabled, atLogin}
 *   await jumphp.invoke('native.autostart', {on: true});             // starts with the computer (an exported app)
 */
final class NativeCommands
{
    public static function register(App $app): void
    {
        $arr = static fn (mixed $p): array => is_array($p) ? $p : [];
        $app->command('native.message', static fn (mixed $p) => Dialog::message((string)($arr($p)['message'] ?? ''), (string)($arr($p)['title'] ?? ''),
            (string)($arr($p)['kind'] ?? 'info')));
        $app->command('native.confirm', static fn (mixed $p) => Dialog::confirm((string)($arr($p)['message'] ?? ''), (string)($arr($p)['title'] ?? ''),
            isset($arr($p)['ok']) ? (string)$arr($p)['ok'] : null, isset($arr($p)['cancel']) ? (string)$arr($p)['cancel'] : null));
        $app->command('native.open', static fn (mixed $p) => FilePicker::open($arr($p)));
        $app->command('native.save', static fn (mixed $p) => FilePicker::save($arr($p)));
        $app->command('native.notify', static fn (mixed $p) => Notification::show((string)($arr($p)['title'] ?? ''), (string)($arr($p)['body'] ?? '')));
        $app->command('native.copy', static fn (mixed $p) => Clipboard::copy((string)($arr($p)['text'] ?? '')));
        $app->command('native.paste', static fn () => Clipboard::paste());
        $app->command('native.openUrl', static fn (mixed $p) => Shell::openUrl((string)($arr($p)['url'] ?? '')));
        $app->command('native.autostart', static function (mixed $p) use ($app, $arr): array {
            $start = $app->autostart();
            if (array_key_exists('on', $arr($p))) {
                $start->set((bool)$arr($p)['on']);
                $app->options->set('autostart_set', true);
            }
            return ['supported' => $start->isSupported(), 'enabled' => $start->isEnabled(), 'atLogin' => $start->startedAtLogin()];
        });
    }
}
