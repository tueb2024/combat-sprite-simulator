<?php

namespace JumPHP\Gui;

/** A widget that opens something over the form (a combo box's list, a text field's context menu): the form tells it where the pointer is and when to close. */
interface Overlay
{
    /** The pointer moves over it ($py: view pixels). */
    public function hoverAt(float $py): void;

    public function close(): void;
}
