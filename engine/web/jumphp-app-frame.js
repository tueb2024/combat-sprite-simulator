// window.jumphp in the pages of a desktop app run in the browser (bin/jumphp build <app>): the same API as in its
// Tauri windows (tauri/src/bridge.js), through the page that runs PHP (engine/web/jumphp-app.js). Each page of the
// app gets this script first (tools/render-web.php adds it to their <head>).
//
//   const r = await jumphp.invoke('greet', {name: 'Ada'});    // App::command('greet', ...) in PHP
//   jumphp.on('tick', (payload) => ...);                      // $app->send('tick', ...) / $window->send(...)
//   jumphp.send('clicked', {x: 1});                           // $app->on('page:clicked', ...)
(function () {
  if (window.jumphp) return;
  const host = window.parent !== window ? window.parent : null;
  const pending = new Map();
  const listeners = new Map();
  let next = 1;
  const post = (msg) => host && host.postMessage({jumphp: true, ...msg}, location.origin);

  window.jumphp = {
    native: false,
    browser: true,
    invoke(name, payload) {
      const id = next++;
      return new Promise((resolve, reject) => {
        pending.set(id, {resolve, reject});
        post({op: 'invoke', id, name: String(name), payload: payload === undefined ? null : payload});
      });
    },
    send(name, payload) {
      post({op: 'send', name: String(name), payload: payload === undefined ? null : payload});
      return Promise.resolve();
    },
    on(name, fn) {
      if (!listeners.has(name)) listeners.set(name, new Set());
      listeners.get(name).add(fn);
      return () => this.off(name, fn);
    },
    off(name, fn) {
      const set = listeners.get(name);
      if (set) set.delete(fn);
    },
  };

  addEventListener('message', (e) => {
    const msg = e.data;
    if (e.source !== host || !msg || msg.jumphp !== true) return;
    if (msg.op === 'resolve') {
      const p = pending.get(msg.id);
      if (!p) return;
      pending.delete(msg.id);
      if (msg.ok) p.resolve(msg.result);
      else {
        const err = new Error(msg.result && msg.result.message ? msg.result.message : String(msg.result));
        err.data = msg.result;
        p.reject(err);
      }
    } else if (msg.op === 'accels') {
      accels = new Set(msg.list || []);
    } else if (msg.op === 'emit') {
      const set = listeners.get(msg.name);
      if (set) for (const fn of Array.from(set)) { try { fn(msg.payload); } catch (err) { console.error(err); } }
    }
  });

  // the menus' shortcuts work while a page has the focus (window, bubbling: after the page's own handlers; a key
  // the page handled itself is not a menu's), and the browser does not do its own (Ctrl+S: "save the page")
  let accels = new Set();
  const combo = (e) => [e.ctrlKey || e.metaKey ? 'ctrl' : '', e.altKey ? 'alt' : '', e.shiftKey ? 'shift' : '', e.key.toLowerCase()]
    .filter(Boolean).join('+');
  addEventListener('keydown', (e) => {
    if (e.defaultPrevented || !accels.has(combo(e))) return;
    e.preventDefault();
    post({op: 'key', combo: combo(e)});
  });
  // files dropped on the page: their names (the browser gives no paths; their contents go to PHP's /tmp/drop)
  addEventListener('dragover', (e) => e.preventDefault());
  addEventListener('drop', (e) => {
    e.preventDefault();
    const files = Array.from(e.dataTransfer?.files || []);
    if (files.length) post({op: 'drop', files});
  });

  window.dispatchEvent(new Event('jumphp:ready'));
  const loaded = () => window.jumphp.send('__loaded', location.href);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', loaded); else loaded();
})();
