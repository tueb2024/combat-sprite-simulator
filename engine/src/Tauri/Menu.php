<?php

namespace JumPHP\Tauri;

/**
 * The menus of a desktop app (App::setMenu(), Window::setMenu()), written as PHP arrays:
 *
 *   $app->setMenu([
 *       ['text' => 'File', 'items' => [
 *           ['id' => 'open', 'text' => 'Open...', 'accelerator' => 'CmdOrCtrl+O'],
 *           ['id' => 'wrap', 'text' => 'Wrap lines', 'checked' => true],     // a check item
 *           '-',                                                             // a separator
 *           ['role' => 'quit'],                                              // the system's own item
 *       ]],
 *       ['text' => 'Edit', 'items' => [['role' => 'undo'], ['role' => 'redo'], '-', ['role' => 'cut'], ['role' => 'copy'], ['role' => 'paste']]],
 *   ]);
 *   $app->on('menu', fn (string $item, string $window, ?bool $checked) => ...);
 *
 * Roles: quit about copy cut paste select_all undo redo minimize maximize fullscreen hide hide_others show_all
 * close_window services (some exist on macOS only: elsewhere they do nothing). On macOS there is one menu bar for
 * the whole app: a window's menu becomes the app's, and its first submenu is the one named after the app.
 */
final class Menu
{
    /** @return list<array<string, mixed>> the items as the native side wants them */
    public static function normalize(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($item === '-') {
                $out[] = ['separator' => true];
                continue;
            }
            if (!is_array($item)) {
                throw new \InvalidArgumentException('a menu item is an array or "-"');
            }
            $entry = [];
            foreach (['id', 'text', 'accelerator', 'role'] as $key) {
                if (isset($item[$key])) {
                    $entry[$key] = (string)$item[$key];
                }
            }
            foreach (['enabled', 'checked'] as $key) {
                if (isset($item[$key])) {
                    $entry[$key] = (bool)$item[$key];
                }
            }
            if (isset($item['items'])) {
                $entry['items'] = self::normalize((array)$item['items']);
            } elseif (!isset($entry['role']) && !isset($entry['id'])) {
                throw new \InvalidArgumentException('a menu item needs an id, a role or items');
            }
            $out[] = $entry;
        }
        return $out;
    }
}
