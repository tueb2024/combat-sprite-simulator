// A desktop app (game.yaml ui: webview) in a web page: what Tauri does on the desktop, done in HTML for
// JumPHP\Tauri\BrowserRuntime (PHP in WebAssembly). PHP's native commands arrive as bridge messages
// {t: 'tauri', cmd, ...}; the answers go back as the events Tauri sends ({type: 'invoke' | 'dialog' | 'menu'...}).
//
// - windows: the main one fills the page (under the menu bar), the others float over it; each is a frame showing
//   the app's page from app/ (tools/render-web.php copied the pages there, with jumphp-app-frame.js first);
// - menus: a bar at the top (the main window's or the app's), their shortcuts; check items;
// - dialogs: message / confirm in HTML; open: the browser's file picker, the files written into PHP's /tmp/open;
//   save: PHP writes into /tmp/save, the page downloads the file right after the frame that wrote it;
// - notifications (Notification API), links (a new tab), clipboard (navigator.clipboard).
// What a page can not do (tray icon, system-wide shortcuts, file paths of the computer) does nothing, quietly.
// In a native phone app (bin/jumphp android / apple of a desktop app: this page in a WebView / WKWebView), the app's
// shell does what a page can not: saving a file (the system's "save as"), notifications, links, clipboard, sharing.

// the native shell around this page, if any: Android's window.JumPHPAndroid, iOS's webkit.messageHandlers.jumphp
const shell = (() => {
  const a = window.JumPHPAndroid;
  if (a) {
    return {save: (name, bytes) => a.save(name, base64(bytes)), notify: (t, b) => a.notify(t, b), open: (u) => a.openUrl(u),
      share: (t, u) => a.share(t, u), copy: (t) => a.copy(t), paste: () => Promise.resolve(a.paste()), exit: () => a.exit()};
  }
  const i = window.webkit?.messageHandlers?.jumphp;
  if (i) {
    const post = (op, data = {}) => i.postMessage({op, ...data});
    return {save: (name, bytes) => post('save', {name, data: base64(bytes)}), notify: (title, body) => post('notify', {title, body}),
      open: (url) => post('open', {url}), share: (text, url) => post('share', {text, url}), copy: (text) => post('copy', {text}),
      paste: () => (navigator.clipboard?.readText ? navigator.clipboard.readText() : Promise.reject(new Error('no clipboard'))),
      exit: () => post('exit')};
  }
  return null;
})();
function base64(bytes) {
  let bin = '';
  for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
  return btoa(bin);
}
if (shell && !navigator.share) navigator.share = (d = {}) => Promise.resolve(shell.share(String(d.text || d.title || ''), String(d.url || '')));

const css = `
#jumphp-app { position: fixed; inset: 0; display: flex; flex-direction: column; background: #fff; color: #222;
  padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left); box-sizing: border-box;
  font: 14px/1.4 system-ui, sans-serif; z-index: 1; }
#jumphp-app[hidden] { display: none; }
#jumphp-menubar { display: flex; gap: 2px; padding: 2px 6px; background: #f3f3f3; border-bottom: 1px solid #ddd;
  user-select: none; flex: none; }
#jumphp-menubar:empty { display: none; }
.jm-top { position: relative; }
.jm-top > button { background: none; border: 0; padding: 4px 8px; border-radius: 4px; font: inherit; cursor: default; color: inherit; }
.jm-top > button:hover, .jm-top.open > button { background: #e2e2e2; }
.jm-list { position: absolute; left: 0; top: 100%; min-width: 200px; background: #fff; border: 1px solid #ccc;
  box-shadow: 0 6px 18px rgba(0,0,0,.15); border-radius: 6px; padding: 4px 0; display: none; z-index: 30; }
.jm-top.open > .jm-list { display: block; }
.jm-item { display: flex; justify-content: space-between; gap: 24px; padding: 5px 14px; cursor: default; white-space: nowrap; }
.jm-item:hover { background: #2f6fed; color: #fff; }
.jm-item.off { opacity: .45; pointer-events: none; }
.jm-item .jm-accel { opacity: .6; }
.jm-sep { height: 1px; background: #e4e4e4; margin: 4px 0; }
#jumphp-windows { position: relative; flex: 1; min-height: 0; }
.jw { position: absolute; display: flex; flex-direction: column; background: #fff; }
.jw.main { inset: 0; }
.jw.float { border: 1px solid #bbb; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,.25); overflow: hidden; z-index: 10; }
.jw-title { display: flex; align-items: center; gap: 8px; padding: 4px 6px 4px 12px; background: #eee; cursor: move; user-select: none; font-size: 13px; }
.jw-title span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.jw-title button { border: 0; background: none; font-size: 16px; cursor: pointer; line-height: 1; padding: 2px 6px; border-radius: 4px; }
.jw-title button:hover { background: #ddd; }
.jw.main .jw-title { display: none; }
.jw iframe { flex: 1; border: 0; width: 100%; background: #fff; }
.jw[hidden] { display: none; }
#jumphp-dialogs { position: fixed; inset: 0; display: none; align-items: center; justify-content: center;
  background: rgba(0,0,0,.35); z-index: 40; padding: 16px; }
#jumphp-dialogs.on { display: flex; }
.jd { background: #fff; color: #222; border-radius: 10px; padding: 18px 20px; max-width: 420px; width: 100%;
  box-shadow: 0 16px 40px rgba(0,0,0,.3); }
.jd h2 { margin: 0 0 8px; font-size: 16px; }
.jd p { margin: 0 0 16px; white-space: pre-wrap; }
.jd .jd-buttons { display: flex; justify-content: flex-end; gap: 8px; }
.jd button { font: inherit; padding: 6px 14px; border-radius: 6px; border: 1px solid #bbb; background: #f6f6f6; cursor: pointer; }
.jd button.primary { background: #2f6fed; border-color: #2f6fed; color: #fff; }
#jumphp-toasts { position: fixed; right: 12px; bottom: 12px; display: flex; flex-direction: column; gap: 8px; z-index: 50; }
.jt { background: #222; color: #fff; padding: 10px 14px; border-radius: 8px; max-width: 320px; box-shadow: 0 6px 18px rgba(0,0,0,.3); }
.jt b { display: block; }
#jumphp-closed { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: #111; color: #eee; z-index: 60; font: 16px system-ui, sans-serif; }
`;

export function install(api) {
  if ((api.GAME.ui || '') !== 'webview') return {};
  const FS = () => api.FS();
  const send = (event) => api.send('tauri', event);
  const el = (tag, props = {}, ...children) => {
    const e = Object.assign(document.createElement(tag), props);
    for (const c of children) e.append(c);
    return e;
  };

  const style = el('style', {textContent: css});
  document.head.append(style);
  // phones: let the page reach the screen's edges, the padding above keeps it out of the notch / status bar
  const vp = document.querySelector('meta[name=viewport]');
  if (vp && !/viewport-fit/.test(vp.content)) vp.content += ', viewport-fit=cover';
  const root = el('div', {id: 'jumphp-app', hidden: true});
  const menubar = el('div', {id: 'jumphp-menubar'});
  const area = el('div', {id: 'jumphp-windows'});
  const dialogs = el('div', {id: 'jumphp-dialogs'});
  const toasts = el('div', {id: 'jumphp-toasts'});
  const closed = el('div', {id: 'jumphp-closed', textContent: 'The app was closed.'});
  root.append(menubar, area);
  document.body.append(root, dialogs, toasts, closed);
  for (const id of ['stage', 'toolbar']) { const e = document.getElementById(id); if (e) e.style.display = 'none'; }

  const windows = new Map();       // label -> {box, frame, title}
  const labelOf = (source) => { for (const [label, w] of windows) if (w.frame.contentWindow === source) return label; return null; };
  const toFrame = (label, msg) => windows.get(label)?.frame.contentWindow?.postMessage({jumphp: true, ...msg}, location.origin);
  let started = false;

  // --- windows -------------------------------------------------------------------------------------------
  function createWindow(c) {
    const label = c.window || 'main';
    windows.get(label)?.box.remove();
    const main = label === 'main';
    const box = el('div', {className: 'jw ' + (main ? 'main' : 'float')});
    const titleText = el('span', {textContent: c.title || ''});
    const closeBtn = el('button', {textContent: '×', title: 'Close'});
    const bar = el('div', {className: 'jw-title'}, titleText, closeBtn);
    const url = String(c.url || 'index.html').replace(/^\/+/, '');
    const frame = el('iframe', {src: /^https?:/.test(url) ? url : 'app/' + url, title: c.title || label});
    box.append(bar, frame);
    if (!main) {
      const w = Math.min(Number(c.width) || 400, innerWidth - 24), h = Math.min(Number(c.height) || 300, innerHeight - 60);
      Object.assign(box.style, {width: w + 'px', height: h + 'px',
        left: (c.x !== undefined ? Number(c.x) : (innerWidth - w) / 2) + 'px', top: (c.y !== undefined ? Number(c.y) : 40) + 'px'});
      dragBy(bar, box);
      closeBtn.onclick = () => send({type: 'window', window: label, event: 'close-requested'});
    }
    if (c.visible === false) box.hidden = true;
    area.append(box);
    windows.set(label, {box, frame, title: titleText});
    if (main && c.title) document.title = c.title;
    if (accels.length) frame.addEventListener('load', () => toFrame(label, {op: 'accels', list: accels.map((a) => a.combo)}));
  }
  function dragBy(handle, box) {
    handle.addEventListener('pointerdown', (e) => {
      if (e.target.tagName === 'BUTTON') return;
      const sx = e.clientX - box.offsetLeft, sy = e.clientY - box.offsetTop;
      const move = (m) => { box.style.left = (m.clientX - sx) + 'px'; box.style.top = Math.max(0, m.clientY - sy) + 'px'; };
      const up = () => { removeEventListener('pointermove', move); removeEventListener('pointerup', up); };
      addEventListener('pointermove', move);
      addEventListener('pointerup', up);
    });
  }
  function closeWindow(label) {
    const w = windows.get(label);
    if (!w) return;
    w.box.remove();
    windows.delete(label);
    send({type: 'window', window: label, event: 'destroyed'});
    if (label === 'main' || windows.size === 0) send({type: 'exit-requested'});
  }

  // --- menus ---------------------------------------------------------------------------------------------
  const menus = {};                // window label ('*': the app's) -> items
  let accels = [];                 // [{combo, window, item}]
  const accelCombo = (a) => a.split('+').map((p) => p.trim().toLowerCase())
    .map((p) => (p === 'cmdorctrl' || p === 'commandorcontrol' || p === 'cmd' || p === 'command' || p === 'control' ? 'ctrl' : p))
    .sort((x, y) => ['ctrl', 'alt', 'shift'].indexOf(x) - ['ctrl', 'alt', 'shift'].indexOf(y) || 0)
    .join('+');
  function renderMenus() {
    const target = menus.main ? 'main' : '*';
    const items = menus[target] || [];
    menubar.replaceChildren();
    accels = [];
    const add = (list, into) => {
      for (const it of list) {
        if (it.separator) { into.append(el('div', {className: 'jm-sep'})); continue; }
        if (it.items) { for (const sub of it.items) if (!sub.separator && !sub.items) entry(sub, into); continue; }
        entry(it, into);
      }
    };
    const entry = (it, into) => {
      if (it.role) {
        const role = {quit: 'Quit', about: 'About', copy: 'Copy', cut: 'Cut', paste: 'Paste', select_all: 'Select all', undo: 'Undo', redo: 'Redo', close_window: 'Close window', fullscreen: 'Fullscreen'}[it.role];
        if (!role) return;
        const row = el('div', {className: 'jm-item'}, el('span', {textContent: it.text || role}));
        row.onclick = () => { closeMenus(); doRole(it.role); };
        into.append(row);
        return;
      }
      const label = (it.checked !== undefined ? (it.checked ? '✓ ' : '  ') : '') + (it.text || it.id);
      const row = el('div', {className: 'jm-item' + (it.enabled === false ? ' off' : '')}, el('span', {textContent: label}),
        el('span', {className: 'jm-accel', textContent: it.accelerator ? it.accelerator.replace(/CmdOrCtrl|CommandOrControl/i, 'Ctrl') : ''}));
      row.onclick = () => { closeMenus(); pick(target, it); };
      into.append(row);
      if (it.accelerator) accels.push({combo: accelCombo(it.accelerator), window: target, item: it});
    };
    for (const top of items) {
      if (!top.items) continue;
      const list = el('div', {className: 'jm-list'});
      add(top.items, list);
      const btn = el('button', {textContent: top.text || ''});
      const wrap = el('div', {className: 'jm-top'}, btn, list);
      btn.onclick = (e) => { e.stopPropagation(); const open = wrap.classList.contains('open'); closeMenus(); if (!open) wrap.classList.add('open'); };
      btn.onmouseenter = () => { if (menubar.querySelector('.jm-top.open') && !wrap.classList.contains('open')) { closeMenus(); wrap.classList.add('open'); } };
      menubar.append(wrap);
    }
    for (const label of windows.keys()) toFrame(label, {op: 'accels', list: accels.map((a) => a.combo)});
  }
  const closeMenus = () => menubar.querySelectorAll('.jm-top.open').forEach((m) => m.classList.remove('open'));
  addEventListener('click', closeMenus);
  function pick(window, it) {
    if (it.enabled === false) return;
    if (it.checked !== undefined) { it.checked = !it.checked; renderMenus(); }
    send({type: 'menu', window, item: it.id, checked: it.checked === undefined ? null : it.checked});
  }
  function findItem(list, id) {
    for (const it of list || []) {
      if (it.id === id) return it;
      const sub = it.items && findItem(it.items, id);
      if (sub) return sub;
    }
    return null;
  }
  function doRole(role) {
    const doc = windows.get('main')?.frame.contentDocument;
    if (role === 'quit' || role === 'close_window') send({type: 'window', window: 'main', event: 'close-requested'});
    else if (role === 'fullscreen') api.isFullscreen() ? api.exitFullscreen() : api.enterFullscreen();
    else if (doc && ['copy', 'cut', 'paste', 'undo', 'redo'].includes(role)) doc.execCommand(role);
    else if (doc && role === 'select_all') doc.execCommand('selectAll');
  }
  const onKey = (combo) => {
    const a = accels.find((x) => x.combo === combo);
    if (a) pick(a.window, a.item);
    for (const s of shortcuts) if (s.combo === combo) send({type: 'shortcut', accelerator: s.accelerator, state: 'pressed'});
  };
  const comboOf = (e) => [e.ctrlKey || e.metaKey ? 'ctrl' : '', e.altKey ? 'alt' : '', e.shiftKey ? 'shift' : '', e.key.toLowerCase()].filter(Boolean).join('+');
  addEventListener('keydown', (e) => {
    const combo = comboOf(e);
    if (accels.some((a) => a.combo === combo) || shortcuts.some((s) => s.combo === combo)) { e.preventDefault(); onKey(combo); }
  });
  let shortcuts = [];

  // --- dialogs -------------------------------------------------------------------------------------------
  const queue = [];
  function modal(title, message, buttons) {
    return new Promise((resolve) => {
      queue.push(() => {
        const box = el('div', {className: 'jd'}, el('h2', {textContent: title || document.title}), el('p', {textContent: message || ''}));
        const row = el('div', {className: 'jd-buttons'});
        buttons.forEach(([text, value, primary]) => {
          const b = el('button', {textContent: text, className: primary ? 'primary' : ''});
          b.onclick = () => { dialogs.classList.remove('on'); dialogs.replaceChildren(); resolve(value); queue.shift(); queue[0]?.(); };
          row.append(b);
        });
        box.append(row);
        dialogs.replaceChildren(box);
        dialogs.classList.add('on');
        row.querySelector('.primary')?.focus();
      });
      if (queue.length === 1) queue[0]();
    });
  }
  const answer = (id, result) => send({type: 'dialog', id, result});
  const writeFile = (dir, name, bytes) => {
    const fs = FS();
    fs.mkdirTree(dir);
    const path = `${dir}/${name.replace(/[\/\\]/g, '_')}`;
    fs.writeFile(path, bytes);
    return path;
  };
  function openFiles(c) {
    const input = el('input', {type: 'file', multiple: !!c.multiple});
    if (c.directory || c.folder) input.webkitdirectory = true;
    const exts = Object.values(c.filters || {}).flat().filter((x) => x && x !== '*');
    if (exts.length) input.accept = exts.map((x) => '.' + String(x).replace(/^\./, '')).join(',');
    input.style.display = 'none';
    document.body.append(input);
    let done = false;
    input.onchange = async () => {
      done = true;
      const paths = [];
      for (const f of Array.from(input.files || [])) {
        paths.push(writeFile('/tmp/open', f.webkitRelativePath || f.name, new Uint8Array(await f.arrayBuffer())));
      }
      input.remove();
      answer(c.id, c.directory || c.folder ? (paths.length ? '/tmp/open' : null) : c.multiple ? paths : (paths[0] ?? null));
    };
    input.addEventListener('cancel', () => { if (!done) { input.remove(); answer(c.id, null); } });
    input.click();
  }
  // save: PHP writes the file into /tmp/save; it is offered as a download right after the frame that wrote it
  api.afterFrame(() => {
    const fs = FS();
    if (!fs || !fs.analyzePath('/tmp/save').exists) return;
    for (const name of fs.readdir('/tmp/save')) {
      if (name === '.' || name === '..') continue;
      const path = `/tmp/save/${name}`;
      const data = fs.readFile(path);
      fs.unlink(path);
      if (shell) { shell.save(name, data); continue; }
      const a = el('a', {href: URL.createObjectURL(new Blob([data])), download: name});
      document.body.append(a);
      a.click();
      setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }
  });

  function toast(title, body) {
    const t = el('div', {className: 'jt'}, el('b', {textContent: title || ''}), body || '');
    toasts.append(t);
    setTimeout(() => t.remove(), 5000);
  }

  // --- the commands --------------------------------------------------------------------------------------
  const handlers = {
    'app.start': (c) => {
      started = true;
      root.hidden = false;
      if (c.title) document.title = c.title;
      document.getElementById('loader')?.style.setProperty('display', 'none');
      send({type: 'ready'});
    },
    'app.exit': () => {
      window.saveUserFiles?.();
      if (shell) { setTimeout(() => shell.exit(), 300); return; }
      root.hidden = true;
      closed.style.display = 'flex';
    },
    'app.tick': () => {}, 'app.emit': () => {},
    'window.create': createWindow,
    'window.close': (c) => closeWindow(c.window || 'main'),
    'window.set_title': (c) => {
      const w = windows.get(c.window || 'main');
      if (w) w.title.textContent = c.title || '';
      if ((c.window || 'main') === 'main') document.title = c.title || '';
    },
    'window.show': (c) => { const w = windows.get(c.window || 'main'); if (w) w.box.hidden = false; },
    'window.hide': (c) => { const w = windows.get(c.window || 'main'); if (w && c.window !== 'main') w.box.hidden = true; },
    'window.focus': (c) => windows.get(c.window || 'main')?.frame.focus(),
    'window.set_size': (c) => { const w = windows.get(c.window || 'main'); if (w && c.window && c.window !== 'main') Object.assign(w.box.style, {width: c.width + 'px', height: c.height + 'px'}); },
    'window.set_position': (c) => { const w = windows.get(c.window || 'main'); if (w && c.window && c.window !== 'main') Object.assign(w.box.style, {left: c.x + 'px', top: c.y + 'px'}); },
    'window.set_fullscreen': (c) => (c.on ? api.enterFullscreen() : api.exitFullscreen()),
    'window.navigate': (c) => { const w = windows.get(c.window || 'main'); if (w) w.frame.src = /^https?:/.test(c.url) ? c.url : 'app/' + String(c.url).replace(/^\/+/, ''); },
    'webview.eval': (c) => { try { windows.get(c.window || 'main')?.frame.contentWindow.eval(c.js); } catch (e) { console.error(e); } },
    'webview.emit': (c) => { for (const label of c.window ? [c.window] : windows.keys()) toFrame(label, {op: 'emit', name: c.name, payload: c.payload}); },
    'invoke.resolve': (c) => toFrame(c.window || 'main', {op: 'resolve', id: c.id, ok: c.ok, result: c.result}),
    'dialog.message': (c) => modal(c.title, c.message, [[c.ok || 'OK', true, true]]).then((r) => answer(c.id, r)),
    'dialog.confirm': (c) => modal(c.title, c.message, [[c.cancel || 'Cancel', false], [c.ok || 'OK', true, true]]).then((r) => answer(c.id, r)),
    'dialog.open': openFiles,
    'dialog.save': (c) => { FS().mkdirTree('/tmp/save'); answer(c.id, '/tmp/save/' + String(c.file_name || 'file').replace(/[\/\\]/g, '_')); },
    'notification.show': (c) => {
      if (shell) return shell.notify(String(c.title || ''), String(c.body || ''));
      if (window.Notification && Notification.permission === 'granted') new Notification(c.title || '', {body: c.body || ''});
      else if (window.Notification && Notification.permission !== 'denied') Notification.requestPermission().then((p) => (p === 'granted' ? new Notification(c.title || '', {body: c.body || ''}) : toast(c.title, c.body)));
      else toast(c.title, c.body);
    },
    'opener.url': (c) => /^(https?|mailto):/.test(c.url || '') && (shell ? shell.open(c.url) : window.open(c.url, '_blank', 'noopener')),
    'opener.path': () => {}, 'opener.reveal': () => {},
    'clipboard.write': (c) => (shell ? shell.copy(String(c.text ?? '')) : navigator.clipboard?.writeText(String(c.text ?? '')).catch(() => {})),
    'clipboard.read': (c) => (shell ? shell.paste() : navigator.clipboard?.readText ? navigator.clipboard.readText() : Promise.reject(new Error('no clipboard')))
      .then((text) => send({type: 'result', id: c.id, result: text}), (e) => send({type: 'result', id: c.id, error: String(e.message || e)})),
    'menu.set': (c) => { menus[c.window && c.window !== '*' ? c.window : '*'] = JSON.parse(JSON.stringify(c.menu || [])); renderMenus(); },
    'menu.remove': (c) => { delete menus[c.window && c.window !== '*' ? c.window : '*']; renderMenus(); },
    'menu.set_item': (c) => {
      const it = findItem(menus[c.window && c.window !== '*' ? c.window : '*'], c.item);
      if (!it) return;
      for (const k of ['enabled', 'checked', 'text']) if (c[k] !== undefined) it[k] = c[k];
      renderMenus();
    },
    'shortcut.register': (c) => { shortcuts.push({accelerator: c.accelerator, combo: accelCombo(c.accelerator)}); },
    'shortcut.unregister': (c) => { shortcuts = shortcuts.filter((s) => s.accelerator !== c.accelerator); },
    'shortcut.unregister_all': () => { shortcuts = []; },
    'tray.create': () => {}, 'tray.set_tooltip': () => {}, 'tray.remove': () => {},
  };
  api.on('tauri', (msg) => {
    const fn = handlers[msg.cmd];
    if (fn) {
      try { fn(msg); } catch (e) { console.error('[app]', msg.cmd, e); if (msg.id !== undefined) send({type: 'result', id: msg.id, error: String(e.message || e)}); }
    } else if (msg.id !== undefined) {
      send({type: 'result', id: msg.id, error: `${msg.cmd}: not in the browser`});
    }
  });

  // --- the pages -> PHP ----------------------------------------------------------------------------------
  addEventListener('message', async (e) => {
    const msg = e.data;
    if (!msg || msg.jumphp !== true || e.origin !== location.origin) return;
    const label = labelOf(e.source);
    if (label === null) return;
    if (msg.op === 'invoke') send({type: 'invoke', window: label, id: msg.id, name: msg.name, payload: msg.payload});
    else if (msg.op === 'send') send({type: 'page', window: label, name: msg.name, payload: msg.payload});
    else if (msg.op === 'key') onKey(msg.combo);
    else if (msg.op === 'drop') {
      const paths = [];
      for (const f of msg.files) paths.push(writeFile('/tmp/drop', f.name, new Uint8Array(await f.arrayBuffer())));
      send({type: 'window', window: label, event: 'drop', paths});
    }
  });

  return {started: () => started};
}
