<?php

namespace JumPHP\Gui;

/**
 * What the user did this frame, for the widgets: the pointer (mouse or finger, in view pixels), its button, the keys
 * pressed, the text typed and the wheel. Built by FormScene from the engine's input; tests build one by hand.
 */
final class Pointer
{
    public float $x = -1;
    public float $y = -1;
    /** went down / up this frame, is down */
    public bool $down = false;
    public bool $up = false;
    public bool $held = false;
    /** the right button went down this frame (the context menu of a text field) */
    public bool $right = false;
    /** a second press quickly at the same place */
    public bool $double = false;
    /** @var list<int> SDL key codes pressed this frame (arrows, Return, Tab, Backspace...) */
    public array $keys = [];
    /** @var list<string> characters typed this frame */
    public array $text = [];
    public bool $shift = false;
    public bool $ctrl = false;
    /** the mouse wheel this frame, in notches: > 0 = up (away from the user) */
    public float $wheel = 0;

    public function pressed(int $key): bool
    {
        return in_array($key, $this->keys, true);
    }

    /** SDL key codes (the binding's constants are not always there: the values of SDL 2) */
    public const RETURN = 13;
    public const ESCAPE = 27;
    public const BACKSPACE = 8;
    public const TAB = 9;
    public const DELETE = 127;
    public const SPACE = 32;
    public const RIGHT = 1073741903;
    public const LEFT = 1073741904;
    public const DOWN = 1073741905;
    public const UP = 1073741906;
    public const HOME = 1073741898;
    public const END = 1073741901;
    public const PAGEUP = 1073741899;
    public const PAGEDOWN = 1073741902;
    public const KP_ENTER = 1073741912;
}
