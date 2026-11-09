// The phone's on-screen keyboard for the text fields drawn by PHP (JumPHP\Gui TextBox, an app's editor...).
// - PHP -> page: JsBridge 'textinput' {on: bool}, sent when a widget that takes text gets or loses the focus.
// - The keyboard only opens when a real <input> is focused during a touch: a hidden one is focused when the finger goes
//   up on the canvas while PHP wants text (and at once when the message arrives, which works while the browser still
//   counts the touch as a recent gesture).
// - What is typed in it comes back to the game as the keyboard events SDL already understands (keydown / keypress /
//   keyup on the canvas, like touch.js does for its pad): a diff of the input's text (predictive keyboards replace
//   whole words) becomes characters and backspaces; Enter, arrows, Tab, Escape are forwarded as keys.
export function install(api) {
	const touch = matchMedia('(pointer: coarse)').matches;
	if (!touch) return {};   // a desktop has its own keyboard: SDL reads it directly
	const canvas = api.canvas;
	let want = false;
	let prev = '';

	const input = document.createElement('input');
	input.type = 'text';
	input.setAttribute('autocomplete', 'off');
	input.setAttribute('autocorrect', 'off');
	input.setAttribute('autocapitalize', 'off');
	input.setAttribute('spellcheck', 'false');
	input.setAttribute('enterkeyhint', 'enter');
	input.setAttribute('aria-hidden', 'true');
	input.tabIndex = -1;
	// invisible, but real (16px: iOS zooms smaller fields), at the bottom so that the page does not scroll
	input.style.cssText = 'position:fixed;left:0;bottom:0;width:1px;height:1px;opacity:0;border:0;padding:0;margin:0;font-size:16px;z-index:-1;caret-color:transparent;';
	document.body.appendChild(input);

	const key = (type, k, code, extra = {}) => {
		const e = new KeyboardEvent(type, { key: k, code, bubbles: true, cancelable: true, ...extra });
		canvas.dispatchEvent(e);
	};
	const KEYS = { Enter: ['Enter', 13], Backspace: ['Backspace', 8], Tab: ['Tab', 9], Escape: ['Escape', 27], ArrowLeft: ['ArrowLeft', 37], ArrowRight: ['ArrowRight', 39], ArrowUp: ['ArrowUp', 38], ArrowDown: ['ArrowDown', 40], Delete: ['Delete', 46] };
	const press = (name) => {
		const [code, keyCode] = KEYS[name];
		key('keydown', name, code, { keyCode });
		key('keyup', name, code, { keyCode });
	};
	const typeChar = (c) => {
		key('keydown', c, '');
		key('keypress', c, '', { charCode: c.codePointAt(0) });
		key('keyup', c, '');
	};

	// the input's text changed (a letter, a replaced word, a deletion): the difference goes to the game
	const sync = () => {
		const now = input.value;
		let i = 0;
		while (i < prev.length && i < now.length && prev[i] === now[i]) i++;
		for (let n = prev.length - i; n > 0; n--) press('Backspace');
		for (const c of now.slice(i)) typeChar(c);
		prev = now;
		if (prev.length > 200 && !input.matches(':focus-visible')) reset();
	};
	const reset = () => { input.value = ''; prev = ''; };

	input.addEventListener('input', sync);
	input.addEventListener('keydown', (e) => {
		const name = e.key;
		if (name === 'Enter' || name === 'Tab' || name === 'Escape' || name.startsWith('Arrow')) {
			e.preventDefault();
			press(name);
			if (name === 'Enter') reset();
		} else if ((name === 'Backspace' || name === 'Delete') && input.value === '') {
			e.preventDefault();   // nothing typed here to delete: the game's own text is what shrinks
			press(name);
		}
	});
	input.addEventListener('blur', () => { reset(); });

	const open = () => {
		if (want && document.activeElement !== input) {
			try { input.focus({ preventScroll: true }); } catch (e) { /* not allowed now: the next touch does it */ }
		}
	};
	const close = () => { if (document.activeElement === input) input.blur(); };

	// turning the phone with the keyboard open: Chrome keeps the old page height until the keyboard is closed, and the app is then
	// drawn in a band with black under it; closing it lets the page measure itself again (a tap in the field opens it again)
	const turned = () => { if (document.activeElement === input) input.blur(); };
	screen.orientation?.addEventListener('change', turned);
	addEventListener('orientationchange', turned);

	api.on('textinput', (msg) => {
		want = !!msg.on;
		want ? open() : close();
	});
	// the touch that focuses a text field: the keyboard may only open from a gesture, and PHP knows about the focus a frame later
	addEventListener('pointerup', () => { if (want) setTimeout(open, 0); }, true);
	// a tap away from every text field: PHP drops the focus, the keyboard goes
	addEventListener('pointerdown', () => { if (!want) close(); }, true);
	return {};
}
