// The game's twa-manifest.json (Bubblewrap's description of the Android app), made from the game's web manifest
// the first time, then refreshed from it (name, icons, colours) keeping what is the app's own: its package,
// its version, its signing key. Run in docker/twa by tools/twa.sh:
//   node twa-manifest.cjs <the game's page, or its web manifest> <twa-manifest.json> <package id> <keystore> [launcher name]
const fs = require('fs');
const { TwaManifest } = require('@bubblewrap/core');

(async () => {
	const [page, out, packageId, keystore, launcherName] = process.argv.slice(2);
	const get = async (url) => {
		const response = await fetch(url, { headers: { 'User-Agent': 'jumphp-twa' } });
		if (!response.ok) throw new Error(`${url}: HTTP ${response.status} (is the game published there?)`);
		return response;
	};
	// the game's page: its <link rel="manifest">; else the web manifest itself
	let url = page;
	let response = await get(url);
	if ((response.headers.get('content-type') || '').includes('html')) {
		const link = (await response.text()).match(/<link[^>]+rel=["']?manifest["']?[^>]*>/i);
		const href = link && link[0].match(/href=["']?([^"' >]+)/i);
		if (!href) throw new Error(`${page}: no <link rel="manifest">: the game is not installable (game.yaml pwa: true)`);
		url = new URL(href[1], response.url).href;
		response = await get(url);
	}
	const webManifest = await response.json();
	let twa;
	if (fs.existsSync(out)) {
		const old = await TwaManifest.fromFile(out);
		twa = await TwaManifest.merge([], new URL(url), webManifest, old);
	} else {
		twa = TwaManifest.fromWebManifestJson(new URL(url), webManifest);
		twa.appVersionCode = 1;
		twa.appVersionName = '1';
		twa.fallbackType = 'customtabs';
		twa.enableNotifications = false;
	}
	twa.packageId = packageId;
	twa.minSdkVersion = 24; // the Trusted Web Activity library (androidbrowserhelper 2.7) needs Android 7
	twa.signingKey = { path: keystore, alias: 'upload' };
	twa.generatorApp = 'jumphp';
	if (launcherName) twa.launcherName = launcherName;
	const error = await twa.validate();
	if (error) throw new Error(`twa-manifest.json: ${error}`);
	await twa.saveToFile(out);
	console.log(`${out}: ${twa.packageId}, "${twa.launcherName}", https://${twa.host}${twa.startUrl}, version ${twa.appVersionName} (${twa.appVersionCode})`);
})().catch((e) => { console.error(String(e.message || e)); process.exit(1); });
