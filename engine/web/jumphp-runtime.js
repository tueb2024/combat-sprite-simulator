// The JumPHP runtime in the page (extension jumphp/runtime, docs/runtime.md, docs/runtime-protocol.md): a small program on the user's
// computer that connects from their own network. PHP sends {t: 'runtime', op: 'probe' | 'pair' | 'status' | 'wake', ...}; the page talks to
// ws://127.0.0.1:47820..47829/v1 (400 ms each, in order) and answers with a JsBridge 'runtime' event:
//   {ev: 'env', os}                                   once, at start: the browser's system (linux | windows | macos | android | ios | other)
//   {ev: 'hello', port, v, runtime, os, granted, apps}  the runtime answered (probe); apps = the applications this origin authorised
//   {ev: 'pending'}                                   the browser is asking the user for the local network permission (the probe waits)
//   {ev: 'missing'} | {ev: 'blocked', why}            nothing listens | the browser forbids ws://127.0.0.1 from this page
//   {ev: 'status', granted: [apps]}                   the apps the user authorised from this origin
//   {ev: 'paired', app, granted, always}              the user's answer to a pair request
//   {ev: 'error', op, text}
// Nothing here is sent to jumphp.net, and the runtime only accepts the pages of the origins it knows.
const PORTS = [47820, 47821, 47822, 47823, 47824, 47825, 47826, 47827, 47828, 47829];
const TIMEOUT = 400;        // a port that neither opens nor refuses within that time is waiting for the browser's permission question
const PENDING = 60000;      // how long that question may stay unanswered before we give up (the socket stays open meanwhile)
const HELLO_WAIT = 2000;    // an open socket must say hello by then

function systemName() {
	const ua = navigator.userAgent || '';
	const platform = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '';
	if (/android/i.test(ua)) return 'android';
	if (/iphone|ipad|ipod/i.test(ua)) return 'ios';
	if (/win/i.test(platform)) return 'windows';
	if (/mac/i.test(platform)) return 'macos';
	if (/linux|x11|cros/i.test(platform + ua)) return 'linux';
	return 'other';
}

/**
 * Opens one socket and calls ready(socket, hello) when the runtime says hello; fail(reason) otherwise:
 * 'closed' (refused at once: nothing listens, or the browser said no), 'denied' (it hung more than TIMEOUT, so the browser was ASKING
 * the user for the local network permission, then said no), 'stuck' (the question was not answered in PENDING ms), 'timeout' (opened
 * but no hello), 'blocked' (the browser refuses to even build the socket). pending() is called once when the connection hangs: the
 * browser's permission question is showing. The socket is NOT closed while the question is open: closing it would dismiss the question
 * (and Chrome then remembers the dismissal, or keeps the request pending until it is restarted).
 */
function open(port, ready, fail, pending) {
	let socket;
	let done = false;
	let opened = false;
	let asking = false;
	let timer = null;
	const end = (reason) => {
		if (done) return;
		done = true;
		clearTimeout(timer);
		if (reason) { try { socket.close(); } catch (e) { /* closed */ } fail(reason); }
	};
	timer = setTimeout(() => {
		if (opened || done) return;
		asking = true;
		pending();
		timer = setTimeout(() => end('stuck'), PENDING);
	}, TIMEOUT);
	try {
		// ?lang= : the language this page is shown in; the runtime keeps it the first time (its tray and windows speak it)
		const lang = String(document.documentElement.lang || navigator.language || '').slice(0, 5);
		socket = new WebSocket(`ws://127.0.0.1:${port}/v1` + (lang ? `?lang=${encodeURIComponent(lang)}` : ''));
	} catch (e) {
		end('blocked');
		return;
	}
	socket.onopen = () => {
		opened = true;
		clearTimeout(timer);
		timer = setTimeout(() => end('timeout'), HELLO_WAIT);
	};
	socket.onerror = () => end(asking ? 'denied' : 'closed');
	socket.onclose = () => end(asking ? 'denied' : 'closed');
	socket.onmessage = (event) => {
		if (done || typeof event.data !== 'string') return;
		let message;
		try { message = JSON.parse(event.data); } catch (e) { return; }
		if (message.t === 'hello') { end(null); ready(socket, message); }
	};
}

/** Did the user (or the browser's settings) forbid this page to reach the local network? */
async function permissionDenied() {
	for (const name of ['loopback-network', 'local-network-access']) {
		try { if ((await navigator.permissions.query({ name })).state === 'denied') return true; } catch (e) { /* unknown name */ }
	}
	return false;
}

export function install(api) {
	api.send('runtime', { ev: 'env', os: systemName() });
	let port = null;          // the port that answered last
	let scanning = false;     // a scan is running: a second one would only ask the browser twice

	function connect(ready, fail) {
		const order = port === null ? PORTS : [port, ...PORTS.filter((p) => p !== port)];
		let i = 0;
		let announced = false;
		const pending = () => { if (!announced) { announced = true; api.send('runtime', { ev: 'pending' }); } };
		const next = (why) => {
			if (why === 'blocked' || why === 'denied') { port = null; fail('blocked'); return; }
			if (why === 'stuck') { port = null; fail('missing'); return; }
			if (i >= order.length) {
				port = null;
				permissionDenied().then((denied) => fail(denied ? 'blocked' : 'missing'));
				return;
			}
			const p = order[i++];
			open(p, (socket, hello) => { port = p; ready(socket, hello, p); }, next, pending);
		};
		next('');
	}

	/** Looks for the runtime once and tells PHP (hello | missing | blocked). */
	function probe(then) {
		if (scanning) return;
		scanning = true;
		const finish = (ev) => { scanning = false; api.send('runtime', ev); if (then) then(ev.ev === 'hello'); };
		connect((socket, hello, p) => {
			socket.send(JSON.stringify({ t: 'status' }));
			const wait = setTimeout(() => { socket.close(); }, 3000);
			socket.onmessage = (event) => {
				let m;
				try { m = JSON.parse(event.data); } catch (e) { return; }
				if (m.t !== 'status') return;
				clearTimeout(wait);
				socket.onclose = () => {};
				socket.close();
				finish({ ev: 'hello', port: p, v: hello.v, runtime: hello.runtime, os: hello.os, granted: !!hello.granted, apps: m.granted || [], push: hello.push || '' });
			};
			socket.onclose = () => { clearTimeout(wait); finish({ ev: 'hello', port: p, v: hello.v, runtime: hello.runtime, os: hello.os, granted: !!hello.granted, apps: [], partial: true }); };
		}, (why) => finish(why === 'blocked' ? { ev: 'blocked', why: 'the browser does not allow this page to reach 127.0.0.1' } : { ev: 'missing' }));
	}

	// Coming back to the page (tab switch, the app installer closing, bfcache, network back, the playground's frame asking): look again
	// at once, then a short burst (0.5 s, 1.5 s, 4 s) in case the runtime app is still starting, before the slow timer of PHP takes over.
	// Done here, not in PHP: a hidden tab's frames and timers are paused, these events are not.
	let lastTrigger = 0;
	let burst = [];
	const recheck = () => {
		const now = Date.now();
		if (now - lastTrigger < 300) return;
		lastTrigger = now;
		burst.forEach(clearTimeout);
		probe((found) => { if (!found) burst = [500, 1500, 4000].map((ms) => setTimeout(() => probe(), ms)); });
	};
	document.addEventListener('visibilitychange', () => { if (!document.hidden) recheck(); });
	addEventListener('pageshow', recheck);
	addEventListener('focus', recheck);
	addEventListener('online', recheck);
	addEventListener('message', (event) => {
		if (event.source === window.parent && event.data && event.data.type === 'jumphp:runtime-recheck') recheck();
	});

	api.on('runtime', (msg) => {
		const op = msg.op;
		// wake: on Android, open the runtime app (jumphp-runtime://start turns its relay on); when the app is not installed the browser goes to
		// the fallback page (the install guide). Needs the tap that just happened (a browser only opens an app from a gesture).
		if (op === 'wake') {
			if (systemName() !== 'android') return;
			const fallback = /^https?:\/\//.test(msg.fallback || '') ? ';S.browser_fallback_url=' + encodeURIComponent(msg.fallback) : '';
			const url = 'intent://start#Intent;scheme=jumphp-runtime;package=net.jumphp.runtime' + fallback + ';end';
			// the application runs in a frame: the browser opens an app from the top page (the playground answers jumphp:wake), else from here
			if (window.parent !== window) window.parent.postMessage({ type: 'jumphp:wake', url }, '*');
			else location.href = url;
			return;
		}
		if (op === 'probe') { probe(); return; }
		connect((socket, hello, p) => {
			if (op === 'pair') {
				socket.send(JSON.stringify({ t: 'pair', app: String(msg.app || ''), title: String(msg.title || msg.app || ''), scope: 'tcp' }));
				socket.onmessage = (event) => {
					let m;
					try { m = JSON.parse(event.data); } catch (e) { return; }
					if (m.t === 'paired') { socket.close(); api.send('runtime', { ev: 'paired', app: m.app, granted: !!m.granted, always: !!m.always }); }
					else if (m.t === 'error') { socket.close(); api.send('runtime', { ev: 'error', op, text: m.message || m.code }); }
				};
				socket.onclose = () => {};
			} else {
				socket.close();
			}
		}, (why) => api.send('runtime', why === 'blocked' ? { ev: 'blocked', why: 'the browser does not allow this page to reach 127.0.0.1' } : { ev: 'missing' }));
	});
	return {};
}
