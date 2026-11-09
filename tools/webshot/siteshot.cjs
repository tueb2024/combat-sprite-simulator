// Headless Chrome check of a game's web site (bin/jumphp siteshot): full-page screenshots on a desktop and a
// phone, after scrolling (lazy images load), and fails on JavaScript errors, broken links to files of the site
// (images, scripts, styles: HTTP >= 400), images that did not load and sideways scrolling on the phone.
//   node siteshot.cjs <url> <out dir>
const puppeteer = require('puppeteer');
const [url, out] = process.argv.slice(2);
(async () => {
  const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('JavaScript: ' + e.message));
  page.on('response', (r) => { if (r.status() >= 400 && r.url().startsWith(url)) errors.push(`HTTP ${r.status()}: ${r.url()}`); });
  // a request the browser cancels itself (the page reloads for the phone size, the icon) is not an error
  page.on('requestfailed', (r) => {
    if (r.url().startsWith(url) && !/ERR_ABORTED/.test(r.failure()?.errorText || '')) errors.push(`failed (${r.failure()?.errorText}): ${r.url()}`);
  });
  const visit = async (name, viewport) => {
    await page.setViewport(viewport);
    await page.goto(url, { waitUntil: 'networkidle0' });
    await page.evaluate(async () => {
      for (let y = 0; y < document.body.scrollHeight; y += 300) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 80)); }
      window.scrollTo(0, 0);
    });
    await new Promise((r) => setTimeout(r, 600));
    const report = await page.evaluate(() => ({
      overflow: document.documentElement.scrollWidth - window.innerWidth,
      broken: [...document.images].filter((i) => i.complete && i.naturalWidth === 0).map((i) => i.getAttribute('src')),
      title: document.title,
      description: document.querySelector('meta[name="description"]')?.content || '',
      ogImage: document.querySelector('meta[property="og:image"]')?.content || '',
      h1: [...document.querySelectorAll('h1')].length,
      play: [...document.querySelectorAll('a[href]')].some((a) => /(^|\/)play\/?(\?|$)/.test(a.getAttribute('href'))),
    }));
    await page.screenshot({ path: `${out}/site-${name}.png`, fullPage: true });
    console.log(`${name}: ${JSON.stringify(report)}`);
    if (report.broken.length) errors.push(`${name}: images not loaded: ${report.broken.join(', ')}`);
    if (name === 'phone' && report.overflow > 0) errors.push(`phone: the page scrolls sideways (${report.overflow}px too wide)`);
    if (!report.title) errors.push('no <title>');
    if (!report.description) errors.push('no <meta name="description">');
    if (!report.play) errors.push('no link to the game (href="play/")');
  };
  await visit('desktop', { width: 1280, height: 800 });
  await visit('phone', { width: 390, height: 844, isMobile: true, hasTouch: true });
  await browser.close();
  if (errors.length) { console.error('ERRORS:\n' + [...new Set(errors)].join('\n')); process.exit(1); }
  console.log('site OK');
})().catch((e) => { console.error(e); process.exit(1); });
