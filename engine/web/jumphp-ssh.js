// The OpenSSH client in the page (JumPHP\Net\Ssh): PHP sends {t: 'ssh', op: 'open', id, options}, 'data' {id, data (base64)}, 'end',
// 'kill'; the page runs ssh.wasm in a Web Worker (jumphp-ssh-worker.js: stdin / stdout are the pipe, the network is a raw WebSocket
// to the TCP bridge) and answers with a JsBridge 'ssh' event {id, ev: 'stdout' | 'stderr' | 'exit' | 'error', data | text | code}.
const toBase64 = (bytes) => {
	let binary = '';
	for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
	return btoa(binary);
};
const fromBase64 = (text) => Uint8Array.from(atob(text), (c) => c.charCodeAt(0));

export function install(api) {
	const workers = new Map();
	api.on('ssh', (msg) => {
		const worker = workers.get(msg.id);
		if (msg.op === 'open') {
			let w;
			try {
				w = new Worker(new URL('jumphp-ssh-worker.js', import.meta.url));
			} catch (e) {
				api.send('ssh', { id: msg.id, ev: 'error', text: 'cannot start the SSH client: ' + e.message });
				return;
			}
			workers.set(msg.id, w);
			w.onmessage = (event) => {
				const m = event.data;
				if (m.type === 'stdout') api.send('ssh', { id: msg.id, ev: 'stdout', data: toBase64(new Uint8Array(m.bytes.buffer || m.bytes)) });
				else if (m.type === 'stderr') api.send('ssh', { id: msg.id, ev: 'stderr', text: m.text });
				else if (m.type === 'knownHosts') api.send('ssh', { id: msg.id, ev: 'knownHosts', text: m.text });
				else if (m.type === 'exit') { api.send('ssh', { id: msg.id, ev: 'exit', code: m.code }); w.terminate(); workers.delete(msg.id); }
				else if (m.type === 'error') { api.send('ssh', { id: msg.id, ev: 'error', text: m.message }); w.terminate(); workers.delete(msg.id); }
			};
			w.onerror = (e) => {
				api.send('ssh', { id: msg.id, ev: 'error', text: 'the SSH client failed: ' + (e.message || 'worker error') });
				workers.delete(msg.id);
			};
			w.postMessage({ type: 'start', options: msg.options });
		} else if (worker && msg.op === 'data') {
			const bytes = fromBase64(msg.data);
			worker.postMessage({ type: 'data', bytes: bytes.buffer }, [bytes.buffer]);
		} else if (worker && msg.op === 'end') {
			worker.postMessage({ type: 'end' });
		} else if (worker && msg.op === 'kill') {
			worker.postMessage({ type: 'kill' });
			workers.delete(msg.id);
		}
	});
}
