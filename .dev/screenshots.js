// Regenerates the wordpress.org screenshots from a seeded test stack.
//
// Runs headless Chrome inside a container rather than driving the developer's own
// browser, for one reason worth writing down: a browser with Chrome's auto-dark turned
// on renders wp-admin dark and overrides the page's own colour-scheme declaration, so
// every capture came out dark no matter what the page asked for. A container has no such
// setting, which also makes the output the same for whoever runs this.
const puppeteer = require('puppeteer');

const BASE = process.env.SHOT_BASE || 'https://example.com';
const OUT = process.env.SHOT_OUT || '/out';
const USER = process.env.SHOT_USER || 'admin';
const PASS = process.env.SHOT_PASS || 'admin';

// Wide enough that the admin does not collapse to its narrow layout, and tall enough
// that the interesting part of each screen fits without scrolling.
const VIEWPORT = { width: 1440, height: 900, deviceScaleFactor: 2 };

// `scrollTo` names the heading the capture should start from. Without it the Audit Log
// page captures the table's filters and clips the rows themselves, which are the one
// thing on that screen worth showing.
const SHOTS = [
  { file: 'screenshot-1.png', path: '/wp-admin/admin.php?page=guarded-mcp-settings', heading: 'Connection' },
  { file: 'screenshot-3.png', path: '/wp-admin/admin.php?page=guarded-mcp-access', heading: 'Access' },
  { file: 'screenshot-4.png', path: '/wp-admin/admin.php?page=guarded-mcp-logs', heading: 'Audit Log',
    scrollTo: 'The audit log' },
  { file: 'screenshot-5.png', path: '/wp-admin/admin.php?page=guarded-mcp-logs&entry=3', heading: 'Audit Log' },
  // No scrollTo on the entry: a single record renders its own page with nothing above
  // it, so the top of the page is already the subject.
];

// The consent screen is not a settings page, so it is reached the way a client reaches it: register
// a client, then open the authorize URL. Captured here rather than kept as a hand-made
// file so it ages with the rest of them.
const CONSENT = { file: 'screenshot-2.png', clientName: 'Claude' };

(async () => {
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--force-color-profile=srgb'],
  });
  const page = await browser.newPage();
  await page.setViewport(VIEWPORT);

  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'networkidle0' });
  await page.type('#user_login', USER);
  await page.type('#user_pass', PASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0' }),
    page.click('#wp-submit'),
  ]);

  // A failed login still renders a page, and a screenshot of the login form is a
  // plausible-looking file. Assert we are actually inside wp-admin before capturing.
  if (!page.url().includes('/wp-admin/')) {
    throw new Error(`login did not land in wp-admin, ended at ${page.url()}`);
  }

  for (const shot of SHOTS) {
    await page.goto(BASE + shot.path, { waitUntil: 'networkidle0' });
    // The screen is identified by its own heading, so a redirect to a permissions
    // error cannot be captured and shipped as a settings screen. Each shot names the
    // heading its page renders, since the five pages no longer share one.
    const heading = await page.$eval('.wrap h1', el => el.textContent.trim()).catch(() => '');
    if (heading !== shot.heading) {
      throw new Error(`${shot.path} rendered "${heading}", not the ${shot.heading} screen`);
    }
    if (shot.scrollTo) {
      const found = await page.evaluate(text => {
        const el = [...document.querySelectorAll('h2, h3')]
          .find(h => h.textContent.trim() === text);
        if (!el) return false;
        // 56 clears the fixed admin bar, which is 32 tall, and leaves a little air.
        // At 24 the heading being scrolled to was itself half hidden behind it.
        window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 56);
        return true;
      }, shot.scrollTo);
      // A missing heading would scroll nowhere and capture the top of the page, which
      // looks like a deliberate choice rather than a broken selector.
      if (!found) {
        throw new Error(`${shot.file}: no heading named "${shot.scrollTo}" to scroll to`);
      }
      await new Promise(r => setTimeout(r, 200));
    }
    await page.screenshot({ path: `${OUT}/${shot.file}`, fullPage: false });
    console.log(`  ${shot.file}  ${shot.path}`);
  }

  // Registration is a public endpoint by design, so this needs no credential and is the
  // same call a real client makes.
  const reg = await page.evaluate(async (base, name) => {
    const res = await fetch(`${base}/wp-json/mcp/v1/oauth/register`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        client_name: name,
        redirect_uris: ['https://claude.ai/api/mcp/auth_callback'],
        grant_types: ['authorization_code', 'refresh_token'],
        response_types: ['code'],
        token_endpoint_auth_method: 'none',
      }),
    });
    return res.json();
  }, BASE, CONSENT.clientName);
  if (!reg.client_id) {
    throw new Error(`client registration did not return a client_id: ${JSON.stringify(reg)}`);
  }

  const authorize = `${BASE}/wp-json/mcp/v1/oauth/authorize`
    + `?response_type=code&client_id=${encodeURIComponent(reg.client_id)}`
    + `&redirect_uri=${encodeURIComponent('https://claude.ai/api/mcp/auth_callback')}`
    + `&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256`
    + `&state=screenshot`;
  await page.goto(authorize, { waitUntil: 'networkidle0' });

  // Approving would redirect to claude.ai, which this container cannot reach and which
  // would capture an error page. The consent screen itself is the subject, so assert we
  // are on it and capture without clicking anything.
  const consentText = await page.evaluate(() => document.body.innerText);
  if (!/allow|authori[sz]e|consent/i.test(consentText)) {
    throw new Error('the authorize URL did not render a consent screen');
  }
  await page.screenshot({ path: `${OUT}/${CONSENT.file}`, fullPage: false });
  console.log(`  ${CONSENT.file}  consent screen`);

  await browser.close();
})().catch(err => {
  console.error(String(err.message || err));
  process.exit(1);
});
