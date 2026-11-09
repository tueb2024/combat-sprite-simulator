<?php

namespace JumPHP\Tests\Gui;

use JumPHP\Gui\Pointer;
use JumPHP\Gui\TextBox;
use PHPUnit\Framework\TestCase;

final class TextBoxEditingTest extends TestCase
{
    private function box(string $text, ?int $cursor = null): TextBox
    {
        $box = new TextBox();
        $box->props['text'] = $text;
        $box->cursor = $cursor ?? mb_strlen($text);
        return $box;
    }

    private function press(TextBox $box, int $key, bool $ctrl = false, bool $shift = false): void
    {
        $p = new Pointer();
        $p->keys = [$key];
        $p->ctrl = $ctrl;
        $p->shift = $shift;
        $box->keys($p);
    }

    private function type(TextBox $box, string $chars): void
    {
        $p = new Pointer();
        $p->text = mb_str_split($chars);
        $box->keys($p);
    }

    public function testCtrlBackspaceDeletesTheWordBeforeTheCursor(): void
    {
        $box = $this->box('hello big world');
        $this->press($box, Pointer::BACKSPACE, ctrl: true);
        self::assertSame('hello big ', $box->props['text']);
        $this->press($box, Pointer::BACKSPACE, ctrl: true);
        self::assertSame('hello ', $box->props['text']);
        $this->press($box, Pointer::BACKSPACE);                 // plain Backspace: one character
        self::assertSame('hello', $box->props['text']);
    }

    public function testCtrlDeleteDeletesTheWordAfterTheCursor(): void
    {
        $box = $this->box('hello big world', 6);
        $this->press($box, Pointer::DELETE, ctrl: true);
        self::assertSame('hello world', $box->props['text']);
    }

    public function testCtrlArrowsJumpByWordsAndPunctuationIsAWordOfItsOwn(): void
    {
        self::assertSame(6, TextBox::nextWord('hello big world', 0));
        self::assertSame(10, TextBox::nextWord('hello big world', 6));
        self::assertSame(10, TextBox::prevWord('hello big world', 15));
        self::assertSame(2, TextBox::prevWord('foo=', 4) - 1);        // "=" first, then "foo"
        self::assertSame(0, TextBox::prevWord('foo=', 3));
    }

    public function testShiftArrowsSelectAndTypingReplacesTheSelection(): void
    {
        $box = $this->box('hello world');
        $this->press($box, Pointer::LEFT, shift: true);
        $this->press($box, Pointer::LEFT, shift: true);
        self::assertSame([9, 11], $box->selection());
        $this->type($box, 'XY!');
        self::assertSame('hello worXY!', $box->props['text']);
        self::assertNull($box->selection());
    }

    public function testCtrlWithShiftSelectsWholeWords(): void
    {
        $box = $this->box('one two three');
        $this->press($box, Pointer::LEFT, ctrl: true, shift: true);
        self::assertSame([8, 13], $box->selection());
        $this->press($box, Pointer::BACKSPACE);                 // the selection goes, not one more character
        self::assertSame('one two ', $box->props['text']);
    }

    public function testCtrlAThenTypingReplacesEverything(): void
    {
        $box = $this->box('old text');
        $this->press($box, ord('a'), ctrl: true);
        self::assertSame([0, 8], $box->selection());
        $this->type($box, 'new');
        self::assertSame('new', $box->props['text']);
    }

    public function testInsertTextReplacesTheSelectionAndKeepsTheMaxLength(): void
    {
        $box = $this->box('abcdef');
        $box->props['maxLength'] = 8;
        $box->anchor = 1;
        $box->cursor = 3;
        $box->insertText("12345\n67");
        self::assertSame('a1234def', $box->props['text']);       // "bc" replaced; four characters fit under maxLength 8
    }

    public function testADoubleClickSelectsTheWord(): void
    {
        $box = $this->box('select this word', 9);
        $p = new Pointer();
        $p->double = true;
        $box->pointerUp($p, true);
        self::assertSame([7, 11], $box->selection());
    }

    public function testTheContextMenuRowsFollowTheSelectionAndTheBoxState(): void
    {
        $box = $this->box('some text');
        $rows = (new \ReflectionMethod($box, 'menuItems'))->invoke($box);
        self::assertSame([['cut', false], ['copy', false], ['paste', true], ['all', true]], $rows);   // nothing selected: no cut / copy
        $box->anchor = 0;
        $rows = (new \ReflectionMethod($box, 'menuItems'))->invoke($box);
        self::assertSame([['cut', true], ['copy', true], ['paste', true], ['all', true]], $rows);
        $box->props['readonly'] = true;
        $rows = (new \ReflectionMethod($box, 'menuItems'))->invoke($box);
        self::assertSame([['cut', false], ['copy', true], ['paste', false], ['all', true]], $rows);   // read only: copy only
        $box->props['readonly'] = false;
        $box->props['password'] = true;
        $rows = (new \ReflectionMethod($box, 'menuItems'))->invoke($box);
        self::assertFalse($rows[1][1]);                                       // a password is never copied
    }

    public function testAWritableTextBoxWantsThePhonesKeyboardButAReadOnlyOneDoesNot(): void
    {
        $box = new TextBox();
        self::assertTrue($box->wantsText());
        $box->props['readonly'] = true;
        self::assertFalse($box->wantsText());
        $box->props['readonly'] = false;
        $box->props['enabled'] = false;
        self::assertFalse($box->wantsText());
        self::assertFalse((new \JumPHP\Gui\Button())->wantsText());
    }
}
