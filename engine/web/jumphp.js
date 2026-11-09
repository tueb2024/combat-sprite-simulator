// JumPHP browser runtime: downloads the engine (php-web.wasm) and the game (php-web.data), restores the
// player's files (IndexedDB), boots PHP and lets it run one frame per requestAnimationFrame.
//
// URL parameters: ?net=wss://host/ws (relay server) &room=ABCD &lang=fr &debug=1 &back=<url> (where "quit" goes)
// &workers=N (Web Workers for JumPHP\Async, 0 = none; default: cores - 1, at most 4)
// Bridge with PHP (JumPHP\Bridge\JsBridge), synchronous with the frames: right before each PHP frame
// (emscripten preMainLoop) the page's events are written to /tmp/jumphp-events; right after it (postMainLoop)
// the game's messages (/tmp/jumphp-bridge) are handled and the modules run. WebGL needs no module: PHP draws
// with the OpenGL extension in SDL's own WebGL context (JumPHP\Gl\OpenGl).
// Modules: jumphp-device.js (page life, phone features), jumphp-http.js (fetch),
// jumphp-pwa.js (installable / offline game: sw.js), jumphp-gamepad.js (Gamepad API).
// window.JumPHP.send(type, data) sends a custom event to the game: $engine->bridge->on(type, fn (array $data)).
// Embedding (games portal): the page posts {type: 'jumphp:ready' | 'jumphp:quit' | 'jumphp:bridge', ...}
// to window.parent; window.JumPHP.onBridge(message) receives the game's custom JsBridge messages.
// Remote games (a games site, docs/remote.md): window.JUMPHP_GAME.remote = {manifest: 'manifest.json'}: the
// engine comes from the folder of this script (shared by every game of the site, cached once), the game's
// files one by one from the manifest (jumphp-files.js). Without it: the normal build (php-web.data).
const GAME = window.JUMPHP_GAME || {};
const REMOTE = GAME.remote || null;
const ENGINE = new URL('./', import.meta.url).href;     // a normal build: the folder of the page
const params = new URLSearchParams(location.search);
const $ = (id) => document.getElementById(id);
const canvas = $('canvas');
const TOUCH = matchMedia('(pointer: coarse)').matches;
const loader = $('loader');
const statusEl = $('status');
// what the thing is called in the messages: an application (a tool: kind gui / cli / app, view window, ui webview) or a game
const THING = (GAME.ui === 'webview' || GAME.view === 'window' || (GAME.kind && GAME.kind !== 'game')) ? 'application' : 'game';
const barEl = $('bar').firstElementChild;
const SAVE_DIR = '/game/var';
const BRIDGE_FILE = '/tmp/jumphp-bridge';
const EVENTS_FILE = '/tmp/jumphp-events';
const TASKS_DIR = '/tmp/jumphp-tasks';
const WORKERS = typeof Worker === 'undefined' ? 0
	: Math.max(0, Math.min(8, Number(params.get('workers') ?? Math.min(4, (navigator.hardwareConcurrency || 2) - 1)) || 0));
const lastLines = [];
let bootError = '';
// what PHP said when the game did not start: its error first, then its last lines
const bootOutput = () => [bootError, ...lastLines.filter((line) => line !== bootError)].filter(Boolean).join('\n');

window.JumPHP = window.JumPHP || { onBridge: null, FS: null };

// --- the bridge with PHP ---------------------------------------------------------------------------------
let FSref = null;
let booted = false;
let callPhp = null;
const outbox = [];                // events for PHP, in order; a key keeps only the latest one (tilt, resize...)
const handlers = {};              // PHP message type -> [fn]
const afterFrame = [];
const beforeFrame = [];
const gestures = [];
function sendToPhp(type, data = {}, key = null) {
	const event = { ...data, t: type };
	if (key) {
		const i = outbox.findIndex((e) => e.key === key);
		if (i >= 0) outbox.splice(i, 1);
	}
	outbox.push({ key, line: JSON.stringify(event) });
}
function writeEvents() {
	if (!FSref || !outbox.length) return;
	const text = outbox.map((e) => e.line).join('\n') + '\n';
	outbox.length = 0;
	try {
		const old = FSref.analyzePath(EVENTS_FILE).exists ? FSref.readFile(EVENTS_FILE, { encoding: 'utf8' }) : '';
		FSref.writeFile(EVENTS_FILE, old + text);
	} catch (e) { console.error('[bridge]', e); }
}
// PHP handles the events now, between two frames (the tab is being hidden / closed)
function deliverNow() {
	if (!booted || !callPhp) return;
	writeEvents();
	try { callPhp('jumphp_events();'); } catch (e) { console.error('[bridge]', e); }
}
// for things a browser only allows from a user gesture (fullscreen, sensors permission, clipboard)
function onGesture(fn) { gestures.push(fn); }
function runGestures() {
	for (const fn of gestures.splice(0)) { try { fn(); } catch (e) { console.error(e); } }
}
addEventListener('pointerup', runGestures, true);
addEventListener('keydown', runGestures, true);
window.JumPHP.send = (type, data) => sendToPhp(String(type), data || {});
const moduleApi = {
	canvas,
	GAME,
	FS: () => FSref,
	send: sendToPhp,
	now: deliverNow,
	on: (type, fn) => { (handlers[type] ||= []).push(fn); },
	afterFrame: (fn) => afterFrame.push(fn),
	beforeFrame: (fn) => beforeFrame.push(fn),
	gesture: onGesture,
	enterFullscreen: () => enterFullscreen(),
	exitFullscreen: () => exitFullscreen(),
	isFullscreen: () => fullscreenElement(),
};

// SDL's GLES2 renderer calls glCheckFramebufferStatus at each SDL_SetRenderTarget: in WebGL that waits for
// the GPU (up to 65 % of a frame). Our targets are always complete. And emscripten enables every WebGL
// extension at start (100-250 ms each for some): only those SDL uses.
const GL_EXTENSIONS = ['ANGLE_instanced_arrays', 'OES_vertex_array_object', 'WEBGL_draw_buffers', 'OES_element_index_uint', 'OES_standard_derivatives'];
for (const ctx of [window.WebGLRenderingContext, window.WebGL2RenderingContext]) {
	if (!ctx) continue;
	ctx.prototype.checkFramebufferStatus = function () { return 0x8CD5; };
	const supported = ctx.prototype.getSupportedExtensions;
	ctx.prototype.getSupportedExtensions = function () {
		return (supported.call(this) || []).filter((name) => GL_EXTENSIONS.includes(name));
	};
}

const setStatus = (text) => { statusEl.textContent = text; };
const setProgress = (ratio) => { barEl.style.width = `${Math.round(Math.max(0, Math.min(1, ratio)) * 100)}%`; };
// what happened just before a crash (the page's errors, PHP's output): kept, and sent with the crash report
const logBuffer = [];
const note = (kind, text) => {
	logBuffer.push(new Date().toISOString().slice(11, 23) + ' ' + kind + ' ' + String(text).slice(0, 300));
	if (logBuffer.length > 200) logBuffer.shift();
};
for (const level of ['error', 'warn']) {
	const original = console[level].bind(console);
	console[level] = (...args) => {
		try {
			const line = args.map((a) => (a && a.stack) || String(a)).join(' ');
			note(level, line);
			// a PHP exception that ended the game (Application::crashed writes "[jumphp] CRASH: Class: message" then the place and the trace): reported once,
			// a moment later so that the lines after it are in the log that goes with the report
			const crash = /^\[jumphp\] CRASH: ([\w\\]+): ([\s\S]*)$/.exec(line);
			if (crash) setTimeout(() => reportCrash(crash[2], logBuffer.slice(-60).join('\n'), crash[1]), 700);
		} catch (e) {}
		original(...args);
	};
}
addEventListener('error', (event) => note('uncaught', event.message));
addEventListener('unhandledrejection', (event) => note('rejection', (event.reason && event.reason.stack) || String(event.reason)));
// the playground gives the address of its crash reports (window.JUMPHP_GAME.crash_url): the administrator reads them (Administration, MCP)
let crashReported = false;
function reportCrash(text, stack, cls) {
	if (crashReported || !GAME.crash_url) return;
	crashReported = true;
	try {
		fetch(GAME.crash_url, { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
			app: GAME.id, game: GAME.id, source: 'web', version: String(GAME.version || ''), time: new Date().toISOString(),
			os: String(navigator.platform || '').slice(0, 60), php: String((GAME.engine || {}).php || ''), class: cls || 'Browser engine', message: String(text),
			at: location.href, url: location.href, ua: navigator.userAgent, trace: String(stack || new Error().stack || '').split('\n').slice(0, 40), log: logBuffer.slice(-150),
		}) }).catch(() => {});
	} catch (e) {}
}
const fail = (text) => { loader.style.display = 'flex'; loader.classList.add('error'); setStatus(text); reportCrash(text + (bootError ? '\n' + bootError : '')); };
const nextFrame = () => new Promise((resolve) => requestAnimationFrame(() => resolve()));
const notifyParent = (message) => { if (window.parent !== window) window.parent.postMessage(message, '*'); };

// version.json (always fetched fresh): content hashes, so a new build is never hidden by an old cache
const version = await fetch(new URL('version.json', ENGINE), { cache: 'no-store' }).then((r) => (r.ok ? r.json() : {})).catch(() => ({}));
const versioned = (file) => (version[file] ? `${file}?v=${version[file]}` : file);
const engineUrl = (file) => new URL(versioned(file), ENGINE).href;

async function cachedFetch(url) {
	let cache = null;
	// remote games share one engine: one cache for all of them
	try { cache = await caches.open(REMOTE ? 'jumphp-engine' : 'jumphp-' + (GAME.id || 'game')); } catch (e) { /* private mode, http */ }
	const hit = cache && await cache.match(url).catch(() => null);
	if (hit) return hit;
	const response = await fetch(url);
	if (cache && response.ok && url.includes('?v=')) {
		try {
			const path = new URL(url, location.href).pathname;
			for (const old of await cache.keys()) if (new URL(old.url).pathname === path) await cache.delete(old);
			await cache.put(url, response.clone());
		} catch (e) { console.warn('[cache]', e); }
	}
	return response;
}

async function fetchWithProgress(url, onProgress) {
	const response = await cachedFetch(url);
	if (!response.ok) throw new Error(`${url}: HTTP ${response.status}`);
	const total = Number(document.querySelector('meta[name="php-wasm-size"]')?.content) || 0;
	if (!response.body || !total) return response.arrayBuffer();
	const reader = response.body.getReader();
	const chunks = [];
	let received = 0;
	for (;;) {
		const { done, value } = await reader.read();
		if (done) break;
		chunks.push(value);
		received += value.length;
		onProgress(received / total);
	}
	const bytes = new Uint8Array(received);
	let offset = 0;
	for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.length; }
	return bytes.buffer;
}

// a page value inside PHP code: base64 (in a PHP string, "$" or "{$" would run code)
const phpString = (v) => `base64_decode('${btoa(unescape(encodeURIComponent(v == null ? '' : String(v))))}')`;

function prelude() {
	const isMobile = matchMedia('(pointer: coarse)').matches;
	const lang = params.get('lang') || (navigator.language || 'en').slice(0, 2).toLowerCase();
	const lines = [
		`define('JUMPHP_WASM', true);`,
		`define('JUMPHP_CONTAINER_CACHE', true);`,
		`define('JUMPHP_MOBILE', ${isMobile ? 'true' : 'false'});`,
		`define('JUMPHP_LANG', ${phpString(lang)});`,
		`define('JUMPHP_ROOM', ${phpString((params.get('room') || '').toUpperCase())});`,
		`define('JUMPHP_DEBUG', ${params.get('debug') === '1' ? 'true' : 'false'});`,
		`define('JUMPHP_WORKERS', ${WORKERS});`,
		`define('JUMPHP_STANDALONE', ${matchMedia('(display-mode: standalone), (display-mode: fullscreen)').matches || navigator.standalone === true ? 'true' : 'false'});`,
	];
	const net = params.get('net') || GAME.net_url;
	if (net) lines.push(`define('JUMPHP_NET_URL', ${phpString(net)});`);
	const netMode = params.get('net_mode');
	if (netMode === 'auto' || netMode === 'server' || netMode === 'local') lines.push(`define('JUMPHP_NET_MODE', ${phpString(netMode)});`);
	return lines.join('\n') + `\nrequire '/game/vendor/jumphp/engine/bootstrap/wasm.php';\n`;
}

// the game's messages (JumPHP\Bridge\JsBridge): one JSON line each
function pollBridge(FS) {
	let text = '';
	try {
		if (!FS.analyzePath(BRIDGE_FILE).exists) return;
		text = FS.readFile(BRIDGE_FILE, { encoding: 'utf8' });
		FS.unlink(BRIDGE_FILE);
	} catch (e) { return; }
	for (const line of text.split('\n')) {
		let msg;
		try { msg = JSON.parse(line); } catch (e) { continue; }
		if (msg.t === 'open' && /^https?:\/\//.test(msg.url || '')) {
			window.open(msg.url, '_blank', 'noopener');
		} else if (msg.t === 'quit') {
			notifyParent({ type: 'jumphp:quit', game: GAME.id });
			window.saveUserFiles?.();
			const back = params.get('back');
			if (back && /^https?:\/\//.test(back)) location.href = back;
			else { $('overlay').querySelector('p').textContent = 'THANKS FOR PLAYING'; $('overlay').style.display = 'flex'; }
		} else if (msg.t === 'share' && navigator.share) {
			navigator.share({ title: GAME.title, text: msg.text || '', url: msg.url || location.href }).catch(() => {});
		} else if (handlers[msg.t]) {
			for (const fn of handlers[msg.t]) { try { fn(msg); } catch (e) { console.error(`[bridge ${msg.t}]`, e); } }
		} else {
			notifyParent({ type: 'jumphp:bridge', game: GAME.id, message: msg });
			window.JumPHP.onBridge?.(msg);
		}
	}
}

// JumPHP\Async in the browser: a pool of Web Workers, each with its own PHP engine (jumphp-worker.js), started
// with the first job. PHP writes jobs to TASKS_DIR/out/<id>.job, results come back as TASKS_DIR/in/<id>.res
// (or .err); TASKS_DIR/disabled tells PHP to run the work itself (no worker could start).
function startTasks(FS, wasmBinary, remote) {
	const slots = [];
	let disabled = false;
	const put = (name, data) => {
		try {
			FS.writeFile(`${TASKS_DIR}/in/${name}.tmp`, data);
			FS.rename(`${TASKS_DIR}/in/${name}.tmp`, `${TASKS_DIR}/in/${name}`);
		} catch (e) { console.error('[tasks]', e); }
	};
	const disable = (why) => {
		if (disabled) return;
		disabled = true;
		console.warn('[tasks] no Web Worker: the game runs its tasks itself.', why || '');
		try { FS.writeFile(`${TASKS_DIR}/disabled`, String(why || '1')); } catch (e) { /* PHP has not created the dir */ }
	};
	const spawn = (slot) => {
		slot.ready = false;
		slot.job = null;
		const worker = slot.worker = new Worker(engineUrl('jumphp-worker.js'), { type: 'module' });
		const dead = (why) => {
			worker.terminate();
			if (slot.job) put(`${slot.job}.err`, why);
			if (slot.ready && ++slot.restarts < 5) return spawn(slot);   // a job crashed it: a fresh one
			slot.dead = true;
			if (slots.every((s) => s.dead)) disable(why);
		};
		worker.onmessage = ({ data }) => {
			if (data.type === 'ready') slot.ready = true;
			else if (data.type === 'result') { put(`${data.id}.res`, data.payload); slot.job = null; }
			else if (data.type === 'crash') dead(data.error);
			else if (data.type === 'failed') dead(data.error);
		};
		worker.onerror = (e) => { e.preventDefault(); dead(e.message || 'worker error'); };
		worker.postMessage({
			type: 'boot',
			moduleUrl: engineUrl('php-web.mjs'),
			dataUrl: new URL(versioned('php-web.data'), location.href).href,
			wasmBinary,
			remote,
		});
	};
	const pump = () => {
		requestAnimationFrame(pump);
		if (disabled) return;
		let jobs;
		try { jobs = FS.readdir(`${TASKS_DIR}/out`).filter((n) => n.endsWith('.job')).sort(); } catch (e) { return; }
		const warm = !slots.length && FS.analyzePath(`${TASKS_DIR}/warm`).exists;
		if (!jobs.length && !warm) return;
		if (!slots.length) {
			if (!WORKERS) return disable('workers=0');
			for (let i = 0; i < WORKERS; i++) slots.push({ restarts: 0 });
			slots.forEach(spawn);
		}
		for (const slot of slots) {
			if (!jobs.length) break;
			if (!slot.ready || slot.dead || slot.job) continue;
			const name = jobs.shift();
			let payload;
			try {
				payload = FS.readFile(`${TASKS_DIR}/out/${name}`);
				FS.unlink(`${TASKS_DIR}/out/${name}`);
			} catch (e) { continue; }   // cancelled by PHP meanwhile
			slot.job = name.slice(0, -4);
			slot.worker.postMessage({ type: 'job', id: slot.job, payload }, [payload.buffer]);
		}
	};
	requestAnimationFrame(pump);
}

const modules = {};
async function loadModules() {
	const list = [['device', 'jumphp-device.js'], ['http', 'jumphp-http.js'], ['pwa', 'jumphp-pwa.js'], ['gamepad', 'jumphp-gamepad.js'], ['keyboard', 'jumphp-keyboard.js'], ['ssh', 'jumphp-ssh.js'], ['runtime', 'jumphp-runtime.js']];
	// a desktop app (game.yaml ui: webview): its pages and native side in HTML (JumPHP\Tauri\BrowserRuntime)
	if (GAME.ui === 'webview') list.push(['app', 'jumphp-app.js']);
	for (const [name, file] of list) {
		try {
			modules[name] = (await import('./' + versioned(file))).install(moduleApi) || {};
		} catch (e) { console.error(`[${file}]`, e); }
	}
}

async function main() {
	const { default: createPhpModule } = await import(engineUrl('php-web.mjs'));
	const manifestUrl = REMOTE ? new URL(REMOTE.manifest || 'manifest.json', location.href).href : null;
	const manifest = REMOTE ? import(engineUrl('jumphp-files.js')).then((files) => files.fetchManifest(manifestUrl)) : null;

	setStatus('Downloading the engine...');
	const wasmBinary = await fetchWithProgress(engineUrl('php-web.wasm'), (r) => setProgress(r * 0.45));

	setStatus(`Loading the ${THING}...`);
	const { ccall, FS } = await createPhpModule({
		canvas,
		wasmBinary,
		locateFile: (path, prefix) => (path === 'php-web.data' ? versioned(path) : prefix + path),
		setStatus(text) {
			const m = /\((\d+)\/(\d+)\)/.exec(text || '');
			if (m) setProgress(0.45 + 0.45 * Number(m[1]) / Number(m[2]));
		},
		print(text) { console.log(text); note('out', text); forwardOutput('out', text); },
		printErr(text) {
			// emscripten noise: PHP maps its scripts with mmap, MEMFS cannot unmap them (harmless)
			if (!text || /munmap\(\) failed/.test(text)) return;
			console.error(text);
			forwardOutput('err', text);
			// the error itself (PHP's first line, "BOOT FAILED: ParseError: ... line 70") is kept before its trace
			if (/BOOT FAILED|Fatal error|Parse error/.test(text)) bootError = String(text);
			lastLines.push(String(text));
			if (lastLines.length > 8) lastLines.shift();
		},
		onAbort(reason) { fail('The engine crashed: ' + reason); },
		// around each PHP frame (emscripten main loop): page events in, game messages and WebGL out
		preMainLoop() {
			for (const fn of beforeFrame) { try { fn(); } catch (e) { console.error(e); } }
			writeEvents();
		},
		postMainLoop() {
			flushOutput(FSref);
			pollBridge(FSref);
			for (const fn of afterFrame) { try { fn(); } catch (e) { console.error(e); } }
		},
	});
	window.JumPHP.FS = FS;
	FSref = FS;
	let remote = null;
	if (REMOTE) {
		remote = { engine: ENGINE, version, manifest: await manifest, manifestUrl };
		const { installRemote } = await import(engineUrl('jumphp-files.js'));
		await installRemote({ FS, ...remote, onProgress: (r) => setProgress(0.45 + 0.45 * r) });
	}
	callPhp = (code) => ccall('zend_eval_string', 'number', ['string', 'number', 'string'], [code, 0, 'jumphp events']);
	// debugging / tests: PHP code between two frames (its echo goes to the console)
	window.JumPHP.php = (code) => { if (booted) callPhp(code); };
	await loadModules();

	// the player's files (saves, options): var/ of the game lives in IndexedDB
	setStatus('Restoring saves...');
	setProgress(0.93);
	FS.mkdirTree(SAVE_DIR);
	FS.mount(FS.filesystems.IDBFS, {}, SAVE_DIR);
	let syncing = false;
	const syncfs = (populate) => new Promise((resolve) => {
		if (syncing && !populate) return resolve();
		syncing = true;
		FS.syncfs(populate, (err) => { syncing = false; if (err) console.error('[save]', err); resolve(); });
	});
	await syncfs(true);
	window.saveUserFiles = () => syncfs(false);
	setInterval(window.saveUserFiles, 2000);
	addEventListener('pagehide', window.saveUserFiles);
	document.addEventListener('visibilitychange', () => document.hidden && window.saveUserFiles());

	setStatus('Starting...');
	setProgress(1);
	await nextFrame();
	await nextFrame();

	try {
		ccall('phpw_run', 'void', ['string'], [prelude()]);
		// back without "unwind": jumphp_frame() was never reached (the boot failed)
		fail(`The ${THING} did not start:\n` + (bootOutput() || 'see the browser console'));
		return;
	} catch (e) {
		// emscripten_set_main_loop(..., simulate_infinite_loop) throws "unwind": the normal case
		if (e !== 'unwind' && !(e && e.name === 'ExitStatus')) {
			fail(e && e.message ? e.message : String(e));
			throw e;
		}
	}

	booted = true;
	await nextFrame();
	loader.style.display = 'none';
	startTasks(FS, wasmBinary, remote);
	if (remote) listenHotReload(FS, ccall, remote);
	window.JumPHPTouch?.start();
	notifyParent({ type: 'jumphp:ready', game: GAME.id });

	// a desktop app shows its pages at once (no sound to unlock, the pages take the focus themselves); an app of forms
	// (view: window) too: it is used, not started
	if (GAME.ui === 'webview') return;
	if (GAME.view === 'window') { canvas.focus(); return; }
	// an application (graphic, terminal...) is used, not started: no "click to play" (kind comes from project.yaml, a game by default)
	const isApp = GAME.kind && GAME.kind !== 'game';
	if (isApp) { canvas.focus(); return; }
	// browsers start audio and keyboard focus after a user gesture: one click / tap / key on the overlay
	const overlay = $('overlay');
	overlay.style.display = 'flex';
	const start = () => {
		overlay.style.display = 'none';
		canvas.focus();
		removeEventListener('keydown', start, true);
		// phones: fullscreen + landscape at once (game.yaml `touch: {fullscreen: false}` turns it off)
		if (TOUCH && (GAME.touch || {}).fullscreen !== false) enterFullscreen();
		// the games' page of the playground (a game without a web site of its own): ?fullscreen=1 in the frame's address, the first click goes fullscreen
		else if (params.get('fullscreen') === '1') enterFullscreen();
	};
	// a touch only allows fullscreen when the finger goes up (pointerup), a mouse when it goes down
	overlay.addEventListener(TOUCH ? 'pointerup' : 'pointerdown', start, { once: true });
	addEventListener('keydown', start, true);
}

// Hot reload (the playground's editor: {type: 'jumphp:hot'} from the parent page, after a file was saved): the
// files that changed are written into /game, then the engine decides (engine/src/Debug/HotReload.php): pictures,
// sounds, translations and keys reloaded in place; data: the scene entered again; code: PHP started again in this
// same engine (phpw_restart: no page reload, no new WebAssembly), in the scene that was playing, with its state
// when the scene keeps it (JumPHP\Scene\HotReloadable). The answer: {type: 'jumphp:hot-done', result, changed}.
function listenHotReload(FS, ccall, remote) {
	let isPlaying = true;
	const hot = async () => {
		const { refreshRemote } = await import(engineUrl('jumphp-files.js'));
		const { manifest, changed } = await refreshRemote({ FS, previous: remote.manifest, manifestUrl: remote.manifestUrl });
		remote.manifest = manifest;
		if (!changed.length) return { result: 'none', changed };
		let result = 'restart';
		if (isPlaying) {
			try { FS.unlink('/tmp/jumphp-hot-result'); } catch (e) { /* none yet */ }
			callPhp(`\\JumPHP\\Debug\\HotReload::apply(${phpString(JSON.stringify(changed))});`);
			try { result = FS.readFile('/tmp/jumphp-hot-result', { encoding: 'utf8' }); } catch (e) { result = 'restart'; }
		}
		if (result === 'restart') {
			if (isPlaying) callPhp('\\JumPHP\\Debug\\HotReload::save(); \\JumPHP\\Debug\\HotReload::shutdown();');
			lastLines.length = 0;
			bootError = '';
			isPlaying = ccall('phpw_restart', 'number', ['string'], [prelude()]) === 1;
			if (!isPlaying) {
				fail(`The ${THING} did not start:\n` + (bootOutput() || 'see the browser console'));
				return { result: 'error', changed, message: bootOutput() };
			}
			loader.classList.remove('error');
			loader.style.display = 'none';
		}
		return { result, changed };
	};
	let queue = Promise.resolve();
	addEventListener('message', (event) => {
		if (event.source !== window.parent || event.data?.type !== 'jumphp:hot') return;
		queue = queue.then(hot).then(
			(answer) => notifyParent({ type: 'jumphp:hot-done', game: GAME.id, ...answer }),
			(e) => notifyParent({ type: 'jumphp:hot-done', game: GAME.id, result: 'error', message: String(e?.message || e) }),
		);
	});
}

// What PHP writes (echo, var_dump, print_r, phpinfo() as HTML, warnings) to the page around the game, when there
// is one (the playground's console): {type: 'jumphp:output', lines: [[stream, text]...]}, ten times a second at
// most, 1000 lines a batch (the rest counted: a game that prints every frame does not flood the page).
const OUTPUT_MAX = 1000;
let outputLines = [];
let outputDropped = 0;
let outputTimer = 0;
// emscripten keeps a line until its newline: what PHP wrote during the frame, sent whole (phpinfo() ends without one)
function flushOutput(FS) {
	if (!FS || window.parent === window) return;
	for (const fd of [1, 2]) {
		try { const stream = FS.getStream(fd); stream?.stream_ops?.fsync?.(stream); } catch (e) { /* not a terminal */ }
	}
}

function forwardOutput(stream, text) {
	if (window.parent === window) return;
	if (outputLines.length < OUTPUT_MAX) outputLines.push([stream, String(text)]);
	else outputDropped++;
	outputTimer ||= setTimeout(() => {
		notifyParent({ type: 'jumphp:output', game: GAME.id, lines: outputLines, dropped: outputDropped });
		outputLines = [];
		outputDropped = 0;
		outputTimer = 0;
	}, 100);
}

// Fullscreen of the whole page (not only the canvas: the on-screen touch controls stay visible), then, on
// phones, the screen is locked in the orientation of the game (Android; iOS has no lock: touch.js asks the
// player to turn the phone)
const fullscreenElement = () => document.fullscreenElement || document.webkitFullscreenElement;
function enterFullscreen() {
	const root = document.documentElement;
	const request = root.requestFullscreen || root.webkitRequestFullscreen;
	if (!request || fullscreenElement()) return Promise.resolve();
	return Promise.resolve(request.call(root, { navigationUI: 'hide' }))
		.then(() => {
			if (!TOUCH || !screen.orientation) return;
			// an application follows the phone (portrait or landscape, and when the user turns it); a game is locked in its own orientation
			if (THING === 'application') {
				try { screen.orientation.unlock(); } catch (e) { /* not locked */ }
				return;
			}
			if (screen.orientation.lock) return screen.orientation.lock((GAME.width || 16) >= (GAME.height || 9) ? 'landscape' : 'portrait');
		})
		.catch(() => {});
}
function exitFullscreen() {
	const exit = document.exitFullscreen || document.webkitExitFullscreen;
	if (exit && fullscreenElement()) Promise.resolve(exit.call(document)).catch(() => {});
}
window.JumPHP.fullscreen = enterFullscreen;

$('btn-full').addEventListener('click', () => {
	fullscreenElement() ? exitFullscreen() : enterFullscreen();
	canvas.focus();
});
// Fullscreen is a button (FULLSCREEN, the playground's bar, F11). A double click only goes fullscreen when a game asks
// for it (project.yaml `double_click_fullscreen: true`) and never in apps: lists, columns, forms use double clicks.
canvas.addEventListener('dblclick', () => { if (GAME.double_click_fullscreen === true && GAME.view !== 'window' && GAME.kind !== 'gui') enterFullscreen(); });
if (GAME.view === 'window') document.documentElement.classList.add('view-window');
$('btn-sound').addEventListener('click', () => {
	// SDL_mixer plays through emscripten's Web Audio context
	const ctx = window.SDL2?.audioContext || window.Module?.SDL2?.audioContext;
	if (ctx) ctx.state === 'running' ? ctx.suspend() : ctx.resume();
	canvas.focus();
});

main().catch((e) => { console.error(e); fail(e && e.message ? e.message : String(e)); });
