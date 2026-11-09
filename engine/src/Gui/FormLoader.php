<?php

namespace JumPHP\Gui;

/**
 * Form files to widgets. A form file (forms/MainForm.form.yaml, written by the IDE's visual editor):
 *
 *   form:
 *     title: Contacts            # the window's title
 *     theme: light               # or dark; accent: '#3b6ef5', fontSize, radius, controlHeight, padding (dp), zoom
 *     layout: column             # the root container's settings: layout, gap, padding, justify, items, scroll
 *     children:
 *       - {type: label, id: heading, text: Contacts, style: title}
 *       - type: row
 *         children:
 *           - {type: textbox, id: name, placeholder: Name, grow: 1, events: {submit: onNameSubmit}}
 *           - {type: button, id: add, text: Add, style: primary, default: true, events: {click: onAddClick}}
 *       - {type: listbox, id: people, height: fill}
 *
 * Types: the keys of TYPES; `class: App\Gui\Gauge` for a widget of the app (a Widget subclass).
 */
final class FormLoader
{
    public const TYPES = [
        'container' => Container::class, 'label' => Label::class, 'button' => Button::class, 'textbox' => TextBox::class,
        'checkbox' => CheckBox::class, 'radio' => Radio::class, 'combobox' => ComboBox::class, 'listbox' => ListBox::class,
        'slider' => Slider::class, 'progressbar' => ProgressBar::class, 'image' => Image::class, 'video' => Video::class,
        'spacer' => Spacer::class, 'separator' => Separator::class,
    ];

    /** container shortcuts: type => its settings */
    public const LAYOUTS = [
        'column' => ['layout' => 'column'],
        'row' => ['layout' => 'row'],
        'grid' => ['layout' => 'grid'],
        'stack' => ['layout' => 'stack'],
        'panel' => ['layout' => 'column', 'border' => true, 'padding' => 12],
    ];

    /** @param array<string, mixed> $definition */
    public static function widget(array $definition): Widget
    {
        $type = strtolower((string)($definition['type'] ?? 'container'));
        $class = $definition['class'] ?? self::TYPES[$type] ?? (isset(self::LAYOUTS[$type]) ? Container::class : null);
        if (!is_string($class) || !is_subclass_of($class, Widget::class)) {
            throw new \InvalidArgumentException("Unknown widget type '$type'" . (isset($definition['id']) ? " ({$definition['id']})" : ''));
        }
        $children = (array)($definition['children'] ?? []);
        $props = array_diff_key($definition, ['type' => 1, 'class' => 1, 'children' => 1]) + (self::LAYOUTS[$type] ?? []);
        $widget = new $class($props);
        if ($widget instanceof Container) {
            foreach ($children as $child) {
                if (is_array($child)) {
                    $widget->add(self::widget($child));
                }
            }
        }
        return $widget;
    }

    /**
     * What the visual editor knows of each type: its properties with their defaults, its events (the first: the one a
     * double click wires).
     *
     * @return array<string, array{class: string, props: array<string, mixed>, events: list<string>}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::TYPES as $type => $class) {
            $out[$type] = ['class' => $class, 'props' => $class::DEFAULTS + Widget::BASE, 'events' => $class::events()];
        }
        return $out;
    }

    /** The name of the method a double click writes: onSaveClick, onNameChange... */
    public static function handlerName(string $id, string $event): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $id . ' ' . $event) ?: [];
        return 'on' . implode('', array_map(fn (string $w) => ucfirst($w), array_filter($words, fn (string $w) => $w !== '')));
    }
}
