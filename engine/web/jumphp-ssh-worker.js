// The worker of OpenSSH's ssh client (ssh.js + ssh.wasm, docker/ssh-wasm; bin/jumphp ssh:build), driven by jumphp-ssh.js (the page).
// It runs the client as a pipe: bytes in -> its stdin, its stdout -> bytes out, its network a raw
// WebSocket to the TCP bridge. A browser has no sockets, no fork and no tty: the module is compiled with a waiting poll()
// (docker/ssh-wasm/poll.js), stdin / stdout are two devices fed from here, the key and the settings are files in its memory.
//
//   const ssh = await JumphpSsh.start(createSsh, {
//     args: ['-W', 'db.internal:3306', '-p', '22', 'me@bastion.example'],   // -W: stdin / stdout are the stream to db.internal:3306
//     websocket: 'wss://jumphp.net/tcp?ticket=...',                           // the TCP bridge (raw mode), to bastion.example:22
//     identity: '-----BEGIN OPENSSH PRIVATE KEY-----...', secret: 'passphrase or password', knownHosts: 'host ssh-ed25519 AAAA...',
//     onStdout: (bytes) => ..., onStderr: (text) => ..., onExit: (code) => ...,
//   });
//   ssh.write(bytes); ssh.end(); ssh.kill();
(function (root) {
  async function start(createSsh, opts) {
    const queue = [];
    let ended = false;
    let exited = false;
    let FS = null;
    const stdin = {
      open() {}, close() {},
      read(stream, buffer, offset, length) {
        if (!queue.length) {
          if (ended) return 0;
          throw new FS.ErrnoError(6);                      // EAGAIN: nothing yet (poll tells when)
        }
        const chunk = queue[0];
        const n = Math.min(length, chunk.length);
        buffer.set(chunk.subarray(0, n), offset);
        if (n === chunk.length) queue.shift(); else queue[0] = chunk.subarray(n);
        return n;
      },
      write() { return 0; },
      poll() { return 4 | (queue.length || ended ? 1 : 0); },
    };
    const stdout = {
      open() {}, close() {},
      read() { return 0; },
      write(stream, buffer, offset, length) { opts.onStdout && opts.onStdout(buffer.slice(offset, offset + length)); return length; },
      poll() { return 4; },
    };
    const decoder = new TextDecoder();
    const env = { HOME: '/home/user', USER: 'user', LOGNAME: 'user', TERM: 'dumb' };
    if (opts.secret) env.JUMPHP_SSH_SECRET = opts.secret;
    if (opts.secrets && opts.secrets.length) env.JUMPHP_SSH_SECRETS = opts.secrets.join('\n');
    const args = [...opts.args];
    const options = {
      noInitialRun: true,
      websocket: { url: opts.websocket, subprotocol: 'binary' },
      print() {},
      printErr: (s) => opts.onStderr && opts.onStderr(s + '\n'),
      preRun: [() => {
        FS = options.FS;
        Object.assign(options.ENV, env);
        FS.mkdirTree('/home/user/.ssh');
        FS.chmod('/home/user/.ssh', 0o700);
        if (opts.identity) {
          FS.writeFile('/home/user/.ssh/id', opts.identity.endsWith('\n') ? opts.identity : opts.identity + '\n', { mode: 0o600 });
        }
        (opts.identities || []).forEach((text, i) => FS.writeFile('/home/user/.ssh/id_' + i, text.endsWith('\n') ? text : text + '\n', { mode: 0o600 }));
        FS.writeFile('/home/user/.ssh/known_hosts', opts.knownHosts || '');
        FS.registerDevice(FS.makedev(70, 0), stdin);
        FS.registerDevice(FS.makedev(70, 1), stdout);
        FS.mkdev('/dev/jhin', 0o666, FS.makedev(70, 0));
        FS.mkdev('/dev/jhout', 0o666, FS.makedev(70, 1));
      }],
      onExit: (code) => {
        exited = true;
        try { opts.onKnownHosts && opts.onKnownHosts(decoder.decode(FS.readFile('/home/user/.ssh/known_hosts'))); } catch (e) {}
        opts.onExit && opts.onExit(code);
      },
    };
    const module = await createSsh(options);
    // the runtime has opened fd 0 / 1 / 2 on its tty: fd 0 and 1 become our two devices
    FS.close(FS.getStream(0));
    FS.close(FS.getStream(1));
    FS.open('/dev/jhin', 0);        // fd 0
    FS.open('/dev/jhout', 1);       // fd 1
    const done = (async () => {
      try { module.callMain(args); } catch (e) { if (!(e && e.name === 'ExitStatus')) opts.onStderr && opts.onStderr(String(e) + '\n'); }
    })();
    return {
      write(bytes) { if (!ended && !exited) queue.push(bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes)); },
      end() { ended = true; },
      kill() { ended = true; exited = true; try { module._emscripten_force_exit && module._emscripten_force_exit(255); } catch (e) {} },
      done,
    };
  }
  const api = { start };
  if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.JumphpSsh = api;
})(typeof self !== 'undefined' ? self : this);

// as a Web Worker: the page sends {type: 'start', options}, {type: 'data', bytes}, {type: 'end'}, {type: 'kill'}
if (typeof importScripts === 'function' && typeof self !== 'undefined' && typeof module === 'undefined') {
  let session = null;
  self.onmessage = async (event) => {
    const m = event.data;
    if (m.type === 'start') {
      try {
        importScripts('ssh.js');
        session = await self.JumphpSsh.start(self.createSsh, Object.assign({}, m.options, {
          onStdout: (bytes) => { self.postMessage({ type: 'stdout', bytes }, [bytes.buffer]); },
          onStderr: (text) => self.postMessage({ type: 'stderr', text }),
          onKnownHosts: (text) => self.postMessage({ type: 'knownHosts', text }),
          onExit: (code) => self.postMessage({ type: 'exit', code }),
        }));
      } catch (e) { self.postMessage({ type: 'error', message: String((e && e.message) || e) }); }
    } else if (m.type === 'data') { session && session.write(new Uint8Array(m.bytes)); }
    else if (m.type === 'end') { session && session.end(); }
    else if (m.type === 'kill') { session && session.kill(); self.close(); }
  };
}
