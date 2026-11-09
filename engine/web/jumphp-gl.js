// WebGL for PHP (JumPHP\Gl\WebGl): a second canvas over the game. After each PHP frame (postMainLoop) it reads
// the frame's commands (/tmp/jumphp-gl, JSON), runs them on its own WebGL context, then composes:
//   SDL canvas (the 2D picture) + 3D layer (below: through the key colour / above: alpha) -> post shader -> screen
// The SDL canvas stays under it and keeps the mouse / touch events (this one has pointer-events: none).
// Nothing is created until the game uses WebGl: games without it pay nothing.
const FILE = '/tmp/jumphp-gl';
const MODES = { points: 0, lines: 1, line_loop: 2, line_strip: 3, triangles: 4, triangle_strip: 5, triangle_fan: 6 };
const QUAD_VS = 'attribute vec2 a_pos; varying vec2 v_uv; void main() { v_uv = a_pos * 0.5 + 0.5; gl_Position = vec4(a_pos, 0.0, 1.0); }';
const POST_HEADER = `precision mediump float;
varying vec2 v_uv;
uniform sampler2D u_frame;
uniform vec2 u_resolution;
uniform vec4 u_viewport;
uniform vec2 u_view;
uniform float u_time;
`;
// the 3D layer mixed with the 2D picture: u_mode 0 = below (the key colour shows the layer), 1 = above (alpha).
// Below: a pixel equal to the key colour times s (0..1) shows the layer times s, so translucent black over the
// key (pause menus, scene fades) darkens the 3D too.
const COMPOSITE_FS = `uniform sampler2D u_layer;
uniform vec3 u_key;
uniform float u_mode;
void main() {
	vec4 f = texture2D(u_frame, v_uv);
	vec2 lv = (v_uv * u_resolution - u_viewport.xy) / u_viewport.zw;
	bool inside = lv.x >= 0.0 && lv.y >= 0.0 && lv.x <= 1.0 && lv.y <= 1.0;
	vec4 l = inside ? texture2D(u_layer, lv) : vec4(0.0);
	if (u_mode < 0.5) {
		float s = clamp(dot(f.rgb, u_key) / dot(u_key, u_key), 0.0, 1.0);
		gl_FragColor = (inside && s > 0.02 && distance(f.rgb, u_key * s) < 0.02) ? vec4(l.rgb * l.a * s, 1.0) : vec4(f.rgb, 1.0);
	} else {
		gl_FragColor = vec4(mix(f.rgb, l.rgb, l.a), 1.0);
	}
}`;

export function install(api) {
	const source = api.canvas;
	let canvas = null;
	let gl = null;
	let failed = false;
	const res = new Map();          // id -> {kind, ...}
	let layer = null;               // {position, resolution, key}
	let post = null;                // {program, uniforms: {name: [floats]}}
	let frameTex = null;            // the SDL picture
	let layerTarget = null;         // {fb, tex, depth, w, h}
	let compTarget = null;          // composite, when a post shader follows
	let quad = null;
	let compositeProgram = null;
	let placeholder = null;
	let shown = false;

	const error = (message) => { console.error('[webgl]', message); api.send('gl', { error: String(message) }); };

	function init() {
		if (gl || failed) return !!gl;
		canvas = document.createElement('canvas');
		canvas.id = 'gl-canvas';
		canvas.style.cssText = 'position:fixed;pointer-events:none;z-index:1;image-rendering:pixelated;display:none;';
		document.getElementById('stage').appendChild(canvas);
		gl = canvas.getContext('webgl', { alpha: false, antialias: false, depth: true, premultipliedAlpha: false, preserveDrawingBuffer: false });
		if (!gl) { failed = true; error('no WebGL context'); return false; }
		quad = gl.createBuffer();
		gl.bindBuffer(gl.ARRAY_BUFFER, quad);
		gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, -1, 1, 1, -1, 1, 1]), gl.STATIC_DRAW);
		frameTex = texture2d(false);
		placeholder = texture2d(false);
		gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, 1, 1, 0, gl.RGBA, gl.UNSIGNED_BYTE, new Uint8Array(4));
		try {
			compositeProgram = link(QUAD_VS, POST_HEADER + COMPOSITE_FS);
		} catch (e) {
			failed = true; gl = null; error(e.message); return false;
		}
		return true;
	}

	function texture2d(smooth, repeat = false) {
		const t = gl.createTexture();
		gl.bindTexture(gl.TEXTURE_2D, t);
		const filter = smooth ? gl.LINEAR : gl.NEAREST;
		gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, filter);
		gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, filter);
		const wrap = repeat ? gl.REPEAT : gl.CLAMP_TO_EDGE;
		gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, wrap);
		gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, wrap);
		return t;
	}

	function compile(type, src) {
		const s = gl.createShader(type);
		gl.shaderSource(s, src);
		gl.compileShader(s);
		if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) {
			const log = gl.getShaderInfoLog(s);
			gl.deleteShader(s);
			throw new Error((type === gl.VERTEX_SHADER ? 'vertex' : 'fragment') + ' shader: ' + log);
		}
		return s;
	}

	// a linked program with its uniforms (name -> {loc, type}) and attribute locations (cached)
	function link(vs, fs) {
		const p = gl.createProgram();
		const v = compile(gl.VERTEX_SHADER, vs);
		const f = compile(gl.FRAGMENT_SHADER, fs);
		gl.attachShader(p, v);
		gl.attachShader(p, f);
		gl.linkProgram(p);
		gl.deleteShader(v);
		gl.deleteShader(f);
		if (!gl.getProgramParameter(p, gl.LINK_STATUS)) throw new Error('link: ' + gl.getProgramInfoLog(p));
		const uniforms = {};
		for (let i = 0, n = gl.getProgramParameter(p, gl.ACTIVE_UNIFORMS); i < n; i++) {
			const info = gl.getActiveUniform(p, i);
			uniforms[info.name.replace(/\[0\]$/, '')] = { loc: gl.getUniformLocation(p, info.name), type: info.type };
		}
		return { obj: p, uniforms, attribs: {} };
	}

	function setUniform(program, name, value) {
		const u = program.uniforms[name];
		if (!u) return;
		const v = new Float32Array(value);
		switch (u.type) {
			case gl.FLOAT: gl.uniform1fv(u.loc, v); break;
			case gl.FLOAT_VEC2: gl.uniform2fv(u.loc, v); break;
			case gl.FLOAT_VEC3: gl.uniform3fv(u.loc, v); break;
			case gl.FLOAT_VEC4: gl.uniform4fv(u.loc, v); break;
			case gl.FLOAT_MAT2: gl.uniformMatrix2fv(u.loc, false, v); break;
			case gl.FLOAT_MAT3: gl.uniformMatrix3fv(u.loc, false, v); break;
			case gl.FLOAT_MAT4: gl.uniformMatrix4fv(u.loc, false, v); break;
			case gl.INT: case gl.BOOL: case gl.SAMPLER_2D: gl.uniform1iv(u.loc, Int32Array.from(value, Math.round)); break;
			case gl.INT_VEC2: case gl.BOOL_VEC2: gl.uniform2iv(u.loc, Int32Array.from(value, Math.round)); break;
			case gl.INT_VEC3: case gl.BOOL_VEC3: gl.uniform3iv(u.loc, Int32Array.from(value, Math.round)); break;
			case gl.INT_VEC4: case gl.BOOL_VEC4: gl.uniform4iv(u.loc, Int32Array.from(value, Math.round)); break;
		}
	}

	function target(old, w, h, smooth, depth) {
		if (old && old.w === w && old.h === h) return old;
		if (old) { gl.deleteFramebuffer(old.fb); gl.deleteTexture(old.tex); if (old.depth) gl.deleteRenderbuffer(old.depth); }
		const t = { w, h, tex: texture2d(smooth), fb: gl.createFramebuffer(), depth: null };
		gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, w, h, 0, gl.RGBA, gl.UNSIGNED_BYTE, null);
		gl.bindFramebuffer(gl.FRAMEBUFFER, t.fb);
		gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, t.tex, 0);
		if (depth) {
			t.depth = gl.createRenderbuffer();
			gl.bindRenderbuffer(gl.RENDERBUFFER, t.depth);
			gl.renderbufferStorage(gl.RENDERBUFFER, gl.DEPTH_COMPONENT16, w, h);
			gl.framebufferRenderbuffer(gl.FRAMEBUFFER, gl.DEPTH_ATTACHMENT, gl.RENDERBUFFER, t.depth);
		}
		return t;
	}

	// the 3D layer of this frame, bound and cleared on first use
	let frameInfo = null;
	let layerReady = false;
	function bindLayer() {
		if (!layer) return false;
		if (!layerReady) {
			const [, , lw, lh] = frameInfo.lb;
			const [w, h] = layer.resolution === 'native' ? [Math.max(1, lw), Math.max(1, lh)] : frameInfo.view;
			layerTarget = target(layerTarget, w, h, false, true);
			gl.bindFramebuffer(gl.FRAMEBUFFER, layerTarget.fb);
			gl.viewport(0, 0, w, h);
			gl.clearColor(0, 0, 0, 0);
			gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);
			layerReady = true;
		}
		gl.bindFramebuffer(gl.FRAMEBUFFER, layerTarget.fb);
		gl.viewport(0, 0, layerTarget.w, layerTarget.h);
		return true;
	}

	function draw(cmd) {
		const p = res.get(cmd.program);
		if (!p || p.kind !== 'program' || !bindLayer()) return;
		gl.useProgram(p.obj);
		const enabled = [];
		let vertices = 0;
		for (const [name, [id, size, stride, offset]] of Object.entries(cmd.attributes)) {
			const b = res.get(id);
			const loc = p.attribs[name] ??= gl.getAttribLocation(p.obj, name);
			if (!b || loc < 0) continue;
			gl.bindBuffer(gl.ARRAY_BUFFER, b.obj);
			gl.enableVertexAttribArray(loc);
			gl.vertexAttribPointer(loc, size, gl.FLOAT, false, stride * 4, offset * 4);
			enabled.push(loc);
			if (!vertices) vertices = Math.floor((b.length - offset) / (stride || size));
		}
		for (const [name, value] of Object.entries(cmd.uniforms)) setUniform(p, name, value);
		let unit = 0;
		for (const [name, id] of Object.entries(cmd.textures)) {
			const t = res.get(id);
			gl.activeTexture(gl.TEXTURE0 + unit);
			gl.bindTexture(gl.TEXTURE_2D, t && t.ready ? t.obj : placeholder);
			setUniform(p, name, [unit]);
			unit++;
		}
		cmd.depth ? gl.enable(gl.DEPTH_TEST) : gl.disable(gl.DEPTH_TEST);
		if (cmd.cull) { gl.enable(gl.CULL_FACE); gl.cullFace(cmd.cull === 'front' ? gl.FRONT : gl.BACK); } else gl.disable(gl.CULL_FACE);
		if (cmd.blend) { gl.enable(gl.BLEND); gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA); } else gl.disable(gl.BLEND);
		const mode = MODES[cmd.mode] ?? gl.TRIANGLES;
		if (cmd.indices) {
			const ib = res.get(cmd.indices);
			if (ib) {
				gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, ib.obj);
				gl.drawElements(mode, cmd.count || ib.length, gl.UNSIGNED_SHORT, cmd.first * 2);
			}
		} else {
			gl.drawArrays(mode, cmd.first, cmd.count || vertices);
		}
		for (const loc of enabled) gl.disableVertexAttribArray(loc);
		gl.activeTexture(gl.TEXTURE0);
	}

	function loadTexture(t, path) {
		let bytes;
		try { bytes = api.FS().readFile(path); } catch (e) { error('texture not found: ' + path); return; }
		const type = /\.jpe?g$/i.test(path) ? 'image/jpeg' : 'image/png';
		createImageBitmap(new Blob([bytes], { type }), { premultiplyAlpha: 'none', colorSpaceConversion: 'none' }).then((image) => {
			if (!res.has(t.id)) return;
			gl.bindTexture(gl.TEXTURE_2D, t.obj);
			gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
			gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, image);
			t.ready = true;
		}).catch((e) => error('texture ' + path + ': ' + e));
	}

	const pot = (n) => (n & (n - 1)) === 0;

	function run(cmd) {
		switch (cmd.c) {
			case 'program':
				try { res.set(cmd.id, { kind: 'program', ...link(cmd.vs, cmd.fs) }); } catch (e) { error(`program ${cmd.id}: ${e.message}`); }
				break;
			case 'buffer': {
				const kind = cmd.index ? gl.ELEMENT_ARRAY_BUFFER : gl.ARRAY_BUFFER;
				const b = { kind: 'buffer', obj: gl.createBuffer(), index: !!cmd.index, length: cmd.data.length, usage: cmd.dynamic ? gl.DYNAMIC_DRAW : gl.STATIC_DRAW };
				gl.bindBuffer(kind, b.obj);
				gl.bufferData(kind, cmd.index ? new Uint16Array(cmd.data) : new Float32Array(cmd.data), b.usage);
				res.set(cmd.id, b);
				break;
			}
			case 'update': {
				const b = res.get(cmd.id);
				if (!b || b.kind !== 'buffer') break;
				const kind = b.index ? gl.ELEMENT_ARRAY_BUFFER : gl.ARRAY_BUFFER;
				gl.bindBuffer(kind, b.obj);
				gl.bufferData(kind, b.index ? new Uint16Array(cmd.data) : new Float32Array(cmd.data), b.usage);
				b.length = cmd.data.length;
				break;
			}
			case 'texture': {
				const t = { kind: 'texture', id: cmd.id, obj: null, ready: false };
				res.set(cmd.id, t);
				t.obj = texture2d(cmd.smooth, cmd.repeat);
				loadTexture(t, cmd.path);
				break;
			}
			case 'rgba': {
				let t = res.get(cmd.id);
				if (!t || t.kind !== 'texture') {
					t = { kind: 'texture', id: cmd.id, obj: texture2d(cmd.smooth, pot(cmd.w) && pot(cmd.h)), ready: true };
					res.set(cmd.id, t);
				}
				const bytes = Uint8Array.from(atob(cmd.data), (ch) => ch.charCodeAt(0));
				gl.bindTexture(gl.TEXTURE_2D, t.obj);
				gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
				gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, cmd.w, cmd.h, 0, gl.RGBA, gl.UNSIGNED_BYTE, bytes);
				t.ready = true;
				break;
			}
			case 'delete': {
				const r = res.get(cmd.id);
				if (!r) break;
				if (r.kind === 'program') gl.deleteProgram(r.obj);
				else if (r.kind === 'buffer') gl.deleteBuffer(r.obj);
				else if (r.kind === 'texture') gl.deleteTexture(r.obj);
				res.delete(cmd.id);
				break;
			}
			case 'layer':
				layer = cmd.position ? { position: cmd.position, resolution: cmd.resolution, key: cmd.key.map((v) => v / 255) } : null;
				break;
			case 'post':
				if (post) gl.deleteProgram(post.program.obj);
				post = null;
				if (cmd.fs) {
					try { post = { program: link(QUAD_VS, POST_HEADER + cmd.fs), uniforms: { ...cmd.uniforms } }; } catch (e) { error('post shader: ' + e.message); }
				}
				break;
			case 'postUniform':
				if (post) post.uniforms[cmd.name] = cmd.value;
				break;
			case 'disable':
				layer = null;
				if (post) gl.deleteProgram(post.program.obj);
				post = null;
				break;
			case 'clear':
				if (bindLayer()) {
					gl.clearColor(...cmd.color);
					gl.clear(gl.COLOR_BUFFER_BIT | (cmd.depth ? gl.DEPTH_BUFFER_BIT : 0));
				}
				break;
			case 'draw':
				draw(cmd);
				break;
		}
	}

	// a full-screen pass: program over the quad, u_frame = texture, into fb (null: the screen)
	function pass(program, texture, fb, w, h, extra) {
		gl.bindFramebuffer(gl.FRAMEBUFFER, fb);
		gl.viewport(0, 0, w, h);
		gl.disable(gl.DEPTH_TEST);
		gl.disable(gl.CULL_FACE);
		gl.disable(gl.BLEND);
		gl.useProgram(program.obj);
		const loc = program.attribs.a_pos ??= gl.getAttribLocation(program.obj, 'a_pos');
		gl.bindBuffer(gl.ARRAY_BUFFER, quad);
		gl.enableVertexAttribArray(loc);
		gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);
		gl.activeTexture(gl.TEXTURE0);
		gl.bindTexture(gl.TEXTURE_2D, texture);
		setUniform(program, 'u_frame', [0]);
		const [lx, ly, lw, lh] = frameInfo.lb;
		setUniform(program, 'u_resolution', [w, h]);
		setUniform(program, 'u_viewport', [lx, h - ly - lh, lw, lh]);   // y up, like gl_FragCoord
		setUniform(program, 'u_view', frameInfo.view);
		setUniform(program, 'u_time', [frameInfo.time]);
		if (extra) extra(program);
		gl.drawArrays(gl.TRIANGLES, 0, 6);
		gl.disableVertexAttribArray(loc);
	}

	function place() {
		const w = source.width;
		const h = source.height;
		if (canvas.width !== w || canvas.height !== h) { canvas.width = w; canvas.height = h; }
		const r = source.getBoundingClientRect();
		const s = canvas.style;
		const css = [`${r.left}px`, `${r.top}px`, `${r.width}px`, `${r.height}px`];
		if (s.left !== css[0] || s.top !== css[1] || s.width !== css[2] || s.height !== css[3]) {
			[s.left, s.top, s.width, s.height] = css;
		}
	}

	function show(on) {
		if (on === shown || !canvas) return;
		shown = on;
		canvas.style.display = on ? 'block' : 'none';
	}

	// after each PHP frame: the commands, then the composed picture
	api.afterFrame(() => {
		const FS = api.FS();
		let text;
		try {
			if (!FS.analyzePath(FILE).exists) { show(false); return; }
			text = FS.readFile(FILE, { encoding: 'utf8' });
			FS.unlink(FILE);
		} catch (e) { return; }
		if (!init()) return;
		frameInfo = JSON.parse(text);
		layerReady = false;
		for (const cmd of frameInfo.cmds) {
			try { run(cmd); } catch (e) { error(`${cmd.c}: ${e.message}`); }
		}
		if (!layer && !post) { show(false); return; }
		if (layer) bindLayer();   // drawn nothing this frame: still an empty layer
		place();
		const w = canvas.width;
		const h = canvas.height;
		gl.bindTexture(gl.TEXTURE_2D, frameTex);
		gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, true);
		gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, source);
		gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
		let picture = frameTex;
		if (layer) {
			const into = post ? (compTarget = target(compTarget, w, h, false, false)) : null;
			pass(compositeProgram, frameTex, into ? into.fb : null, w, h, (p) => {
				gl.activeTexture(gl.TEXTURE1);
				gl.bindTexture(gl.TEXTURE_2D, layerTarget.tex);
				setUniform(p, 'u_layer', [1]);
				setUniform(p, 'u_key', layer.key);
				setUniform(p, 'u_mode', [layer.position === 'above' ? 1 : 0]);
				gl.activeTexture(gl.TEXTURE0);
			});
			if (into) picture = into.tex;
		}
		if (post) {
			pass(post.program, picture, null, w, h, (p) => {
				for (const [name, value] of Object.entries(post.uniforms)) setUniform(p, name, value);
			});
		}
		show(true);
	});

	return { available: () => typeof WebGLRenderingContext !== 'undefined' };
}
