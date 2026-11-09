<?php

namespace JumPHP\Tests\Gui;

use JumPHP\Gui\Button;
use JumPHP\Gui\CheckBox;
use JumPHP\Gui\ComboBox;
use JumPHP\Gui\Container;
use JumPHP\Gui\Form;
use JumPHP\Gui\FormLoader;
use JumPHP\Gui\ListBox;
use JumPHP\Gui\Pointer;
use JumPHP\Gui\Radio;
use JumPHP\Gui\TextBox;
use PHPUnit\Framework\TestCase;

final class TestForm extends Form
{
    /** @var list<string> */
    public array $calls = [];

    public function onOkClick(Button $sender): void
    {
        $this->calls[] = 'ok:' . $sender->props['id'];
    }

    public function onNameSubmit(TextBox $sender, string $text): void
    {
        $this->calls[] = 'submit:' . $text;
    }

    public function onColourChange(ComboBox $sender, int $index, string $item): void
    {
        $this->calls[] = "colour:$index:$item";
    }
}

final class FormTest extends TestCase
{
    /** @param list<array<string, mixed>> $children */
    private function form(array $children, array $settings = []): TestForm
    {
        $form = new TestForm();
        $form->build(['children' => $children, 'padding' => 0, 'gap' => 10] + $settings);
        $form->layoutTo(800, 600);
        return $form;
    }

    public function testAColumnPutsTheWidgetsOneUnderTheOther(): void
    {
        $form = $this->form([
            ['type' => 'button', 'id' => 'a', 'text' => 'A'],
            ['type' => 'textbox', 'id' => 'b'],
        ]);
        $a = $form->widget('a');
        $b = $form->widget('b');
        self::assertSame(0.0, $a->y);
        self::assertSame(36.0, $a->h);
        self::assertSame(46.0, $b->y);
        self::assertSame(800.0, $b->w, 'a text box takes the width of the column');
        self::assertLessThan(800, $a->w, 'a button keeps its own width');
    }

    public function testASpacerPushesTheNextButtonToTheEndOfARow(): void
    {
        $form = $this->form([
            ['type' => 'row', 'gap' => 0, 'children' => [
                ['type' => 'button', 'id' => 'left', 'text' => 'L', 'width' => 100],
                ['type' => 'spacer'],
                ['type' => 'button', 'id' => 'right', 'text' => 'R', 'width' => 100],
            ]],
        ]);
        self::assertSame(0.0, $form->widget('left')->x);
        self::assertSame(700.0, $form->widget('right')->x);
    }

    public function testTwoGrowingWidgetsShareTheFreeRoom(): void
    {
        $form = $this->form([
            ['type' => 'row', 'gap' => 0, 'children' => [
                ['type' => 'textbox', 'id' => 'a', 'width' => 100, 'grow' => 1],
                ['type' => 'textbox', 'id' => 'b', 'width' => 100, 'grow' => 3],
            ]],
        ]);
        self::assertSame(250.0, $form->widget('a')->w);
        self::assertSame(550.0, $form->widget('b')->w);
    }

    public function testAGridFollowsTheWidthOfTheWindow(): void
    {
        $children = [['type' => 'grid', 'gap' => 0, 'children' => [
            ['type' => 'button', 'id' => 'a', 'span' => ['xs' => 12, 'sm' => 6, 'lg' => 4], 'width' => 'fill'],
            ['type' => 'button', 'id' => 'b', 'span' => ['xs' => 12, 'sm' => 6, 'lg' => 4], 'width' => 'fill'],
            ['type' => 'button', 'id' => 'c', 'span' => ['xs' => 12, 'sm' => 6, 'lg' => 4], 'width' => 'fill'],
        ]]];
        $phone = $this->form($children);
        $phone->layoutTo(390, 800);
        self::assertSame('xs', $phone->breakpoint());
        self::assertSame(390.0, $phone->widget('a')->w);
        self::assertGreaterThan($phone->widget('a')->y, $phone->widget('b')->y, 'one per line on a phone');

        $tablet = $this->form($children);
        $tablet->layoutTo(800, 800);
        self::assertSame('sm', $tablet->breakpoint());
        self::assertSame(400.0, $tablet->widget('b')->x, 'two per line on a tablet');
        self::assertSame($tablet->widget('a')->y, $tablet->widget('b')->y);

        $desktop = $this->form($children);
        $desktop->layoutTo(1500, 800);
        self::assertSame(1000.0, $desktop->widget('c')->x, 'three per line on a big screen');
    }

    public function testHiddenOnPhonesMeansNotLaidOutThere(): void
    {
        $form = $this->form([
            ['type' => 'label', 'id' => 'side', 'text' => 'Side', 'hide' => ['xs']],
            ['type' => 'label', 'id' => 'main', 'text' => 'Main'],
        ]);
        $form->layoutTo(390, 600);
        self::assertSame(0.0, $form->widget('main')->y);
        self::assertFalse($form->widget('side')->isShown('xs'));
    }

    public function testAClickCallsTheMethodWiredInTheFormFile(): void
    {
        $form = $this->form([['type' => 'button', 'id' => 'ok', 'text' => 'OK', 'events' => ['click' => 'onOkClick']]]);
        $form->click($form->widget('ok'));
        self::assertSame(['ok:ok'], $form->calls);
    }

    public function testADisabledButtonDoesNothing(): void
    {
        $form = $this->form([['type' => 'button', 'id' => 'ok', 'enabled' => false, 'events' => ['click' => 'onOkClick']]]);
        $form->click($form->widget('ok'));
        self::assertSame([], $form->calls);
    }

    public function testTypingGoesIntoTheFocusedTextBoxAndReturnSubmitsIt(): void
    {
        $form = $this->form([['type' => 'textbox', 'id' => 'name', 'events' => ['submit' => 'onNameSubmit']]]);
        $form->click($form->widget('name'));
        $form->type("Adx\x08a\n");
        self::assertSame('Ada', $form->widget('name')->props['text']);
        self::assertSame(['submit:Ada'], $form->calls);
        $form->key(Pointer::LEFT);
        $form->type('!');
        self::assertSame('Ad!a', $form->widget('name')->props['text']);
    }

    public function testTabMovesTheFocusAndReturnClicksTheDefaultButton(): void
    {
        $form = $this->form([
            ['type' => 'textbox', 'id' => 'a'],
            ['type' => 'textbox', 'id' => 'b'],
            ['type' => 'button', 'id' => 'ok', 'default' => true, 'events' => ['click' => 'onOkClick']],
        ]);
        $form->key(Pointer::TAB);
        self::assertSame($form->widget('a'), $form->focused());
        $form->key(Pointer::TAB);
        self::assertSame($form->widget('b'), $form->focused());
        $form->key(Pointer::TAB, shift: true);
        self::assertSame($form->widget('a'), $form->focused());
        $form->setFocus(null);
        $form->key(Pointer::RETURN);
        self::assertSame(['ok:ok'], $form->calls);
    }

    public function testCheckBoxesTickAndRadiosAreExclusive(): void
    {
        $form = $this->form([
            ['type' => 'checkbox', 'id' => 'news'],
            ['type' => 'radio', 'id' => 'small', 'checked' => true],
            ['type' => 'radio', 'id' => 'big'],
        ]);
        $form->click($form->widget('news'));
        self::assertTrue($form->widget('news')->props['checked']);
        $form->click($form->widget('big'));
        self::assertTrue($form->widget('big')->props['checked']);
        self::assertFalse($form->widget('small')->props['checked']);
        self::assertInstanceOf(CheckBox::class, $form->widget('news'));
        self::assertInstanceOf(Radio::class, $form->widget('big'));
    }

    public function testAComboBoxOpensItsListAndAClickInItChooses(): void
    {
        $form = $this->form([['type' => 'combobox', 'id' => 'colour', 'items' => ['Red', 'Green', 'Blue'], 'events' => ['change' => 'onColourChange']]]);
        /** @var ComboBox $combo */
        $combo = $form->widget('colour');
        $form->click($combo);
        self::assertTrue($combo->open);
        // the third line of the list, under the box
        $rowH = 36 * 0.9;
        $form->clickAt($combo->x + 20, $combo->y + $combo->h + 4 + 4 + 2.5 * $rowH);
        self::assertFalse($combo->open);
        self::assertSame('Blue', $combo->value());
        self::assertSame(['colour:2:Blue'], $form->calls);
    }

    public function testAListBoxSelectsTheLineClickedAndMovesWithTheArrows(): void
    {
        $form = $this->form([['type' => 'listbox', 'id' => 'people', 'items' => ['Ada', 'Grace', 'Alan']]]);
        /** @var ListBox $list */
        $list = $form->widget('people');
        $rh = $list->rowHeight($form->theme);
        $form->clickAt($list->x + 10, $list->y + 1.5 * $rh);
        self::assertSame('Grace', $list->value());
        $form->key(Pointer::DOWN);
        self::assertSame('Alan', $list->value());
    }

    public function testWidgetsAddedFromTheCodeJoinTheForm(): void
    {
        $form = $this->form([['type' => 'column', 'id' => 'box']]);
        /** @var Container $box */
        $box = $form->widget('box');
        $box->add(new Button(['id' => 'late', 'text' => 'Late', 'events' => ['click' => 'onOkClick']]));
        $form->layoutTo(800, 600);
        $form->click($form->widget('late'));
        self::assertSame(['ok:late'], $form->calls);
    }

    public function testTheCatalogListsEveryTypeWithItsEvents(): void
    {
        $catalog = FormLoader::catalog();
        self::assertSame('click', $catalog['button']['events'][0]);
        self::assertSame('change', $catalog['textbox']['events'][0]);
        self::assertArrayHasKey('span', $catalog['listbox']['props']);
        self::assertSame('onSaveClick', FormLoader::handlerName('save', 'click'));
        self::assertSame('onUserNameChange', FormLoader::handlerName('user_name', 'change'));
    }

    public function testKeysTypeTheCharactersOfTheLayout(): void
    {
        self::assertSame('a', Form::character(97));
        self::assertSame('A', Form::character(97, shift: true));
        self::assertSame('é', Form::character(233));
        self::assertSame('7', Form::character(1073741919));
        self::assertNull(Form::character(Pointer::LEFT));
    }

    public function testAScrolledColumnShowsItsEndAndClicksStillHit(): void
    {
        $children = [];
        for ($i = 0; $i < 30; $i++) {
            $children[] = ['type' => 'button', 'id' => "b$i", 'text' => "B$i", 'events' => ['click' => 'onOkClick']];
        }
        $form = $this->form($children);
        $form->layoutTo(400, 300);
        $form->root->scrollBy(10000);
        self::assertGreaterThan(0, $form->root->scrollY);
        $form->click($form->widget('b29'));
        self::assertSame(['ok:b29'], $form->calls);
    }

    public function testAListThatFillsTakesTheRestOfTheWindow(): void
    {
        $form = $this->form([
            ['type' => 'label', 'id' => 'title', 'text' => 'People'],
            ['type' => 'listbox', 'id' => 'people', 'height' => 'fill'],
        ]);
        $list = $form->widget('people');
        self::assertSame(600.0, $list->y + $list->h);
    }

    public function testALongTextInARowLeavesRoomForTheTickAtTheEnd(): void
    {
        $form = $this->form([
            ['type' => 'row', 'gap' => 0, 'children' => [
                ['type' => 'label', 'id' => 'long', 'text' => str_repeat('A long sentence that wraps. ', 20)],
                ['type' => 'spacer'],
                ['type' => 'checkbox', 'id' => 'dark', 'text' => 'Dark'],
            ]],
        ]);
        $dark = $form->widget('dark');
        self::assertLessThanOrEqual(800.0, $dark->x + $dark->w);
        self::assertGreaterThan(400.0, $dark->x);
        self::assertGreaterThan(1, $form->widget('long')->h / 20, 'the text wraps');
    }

    public function testTheWheelScrollsTheListUnderThePointerThenTheWindow(): void
    {
        $children = [['type' => 'listbox', 'id' => 'people', 'height' => 120, 'items' => array_map(fn (int $i) => "Person $i", range(1, 40))]];
        for ($i = 0; $i < 30; $i++) {
            $children[] = ['type' => 'button', 'id' => "b$i", 'text' => "B$i"];
        }
        $form = $this->form($children);
        $form->layoutTo(400, 300);
        /** @var ListBox $list */
        $list = $form->widget('people');
        $form->wheelAt($list->x + 10, $list->y + 10, -1);
        self::assertGreaterThan(0, $list->scrollY, 'the list scrolls down');
        self::assertSame(0.0, $form->root->scrollY, 'not the window');
        $form->wheelAt(200, 250, -2);
        self::assertGreaterThan(0, $form->root->scrollY, 'over a button: the window scrolls');
        $scrolled = $form->root->scrollY;
        $form->wheelAt(200, 250, 1);
        self::assertLessThan($scrolled, $form->root->scrollY, 'and back up');
    }

    public function testTheWheelScrollsTheOpenListOfAComboBox(): void
    {
        $form = $this->form([['type' => 'combobox', 'id' => 'colour', 'rows' => 3, 'items' => ['Red', 'Green', 'Blue', 'Cyan', 'Pink', 'Gold']]]);
        /** @var ComboBox $combo */
        $combo = $form->widget('colour');
        $form->click($combo);
        $form->wheelAt($combo->x + 10, $combo->y + $combo->h + 20, -2);
        // the first line shown is now the third one: a click on it chooses Blue
        $form->clickAt($combo->x + 20, $combo->y + $combo->h + 4 + 4 + 0.5 * 36 * 0.9);
        self::assertSame('Blue', $combo->value());
    }

    public function testTheTextOfTheSystemsInputMethodIsTyped(): void
    {
        $keyboard = new \JumPHP\Input\Keyboard();
        self::assertFalse($keyboard->hasTextEvents());
        $keyboard->setComposition('^');
        self::assertSame('^', $keyboard->getComposition(), 'a dead key waits');
        $keyboard->addText('ê');
        self::assertSame('ê', $keyboard->getText());
        self::assertSame('', $keyboard->getComposition(), 'typed: nothing composed any more');
        self::assertTrue($keyboard->hasTextEvents());
        $keyboard->resetTransientStates();
        self::assertSame('', $keyboard->getText());

        $form = $this->form([['type' => 'textbox', 'id' => 'name']]);
        $form->click($form->widget('name'));
        $form->type('日本');
        self::assertSame('日本', $form->widget('name')->props['text']);
    }
}
