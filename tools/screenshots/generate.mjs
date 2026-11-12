// Generates the screenshots in screenshots/ for the README.
//
// Starts a throwaway copy of the app (PHP's built-in server with its own SQLite
// database in a temporary folder) filled with the example data in demo/, registers
// an example volunteer for a few shifts and takes screenshots with a headless
// browser. The browser's clock is fixed to Saturday of the demo competition, so every
// run shows the same picture. Your own config.php and database are not touched.
//
// Usage (see README.md, "Screenshots"):
//   cd tools/screenshots && npm ci && npx playwright install chromium
//   npm run screenshots
// Environment: PHP=/path/to/php (default: php), PORT=8790.

import { spawn } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const outDir = join(root, 'screenshots');
const port = Number(process.env.PORT || 8790);
const baseUrl = `http://127.0.0.1:${port}/`;

// Saturday of the demo competition, late morning: some shifts are over, some running.
const now = new Date('2026-06-13T11:20:00+02:00');
const timezoneId = 'Europe/Berlin';

const page = {
  title: 'Summer Trampoline Cup 2026',
  intro: 'Thanks for helping at our trampoline competition! Pick the shifts that suit you: every hour helps. ' +
    'When you arrive, please check in at Registration in the foyer.'
};
const volunteer = { name: 'Alex', email: 'alex@example.org' };
const admin = { name: 'Mara', email: 'mara@example.org' };
// Shifts the example volunteer registers for (they show up yellow).
const volunteerShifts = ['Hall setup', 'Score entry: morning', 'Award ceremony', 'Teardown'];

// A copy of private/ with its own configuration, database and example data.
function preparePrivateDir() {
  const dir = mkdtempSync(join(tmpdir(), 'volunteertable-screenshots-'));
  cpSync(join(root, 'private'), dir, {
    recursive: true,
    filter: (source) => !/[\\/](config\.php|data|seed)$/.test(source)
  });
  cpSync(join(here, 'demo'), join(dir, 'seed'), { recursive: true });
  // base_url: links shown on the Admin tab look like a real deployment's.
  writeFileSync(join(dir, 'config.php'),
    "<?php\nreturn [\n    'db' => ['dsn' => 'sqlite:' . __DIR__ . '/demo.sqlite'],\n" +
    "    'base_url' => 'https://example.org/volunteers',\n    'mail_method' => 'log',\n];\n");
  return dir;
}

function startServer(privateDir) {
  const server = spawn(process.env.PHP || 'php', ['-S', `127.0.0.1:${port}`, '-t', join(root, 'public')], {
    env: { ...process.env, VOLUNTEERTABLE_PRIVATE_DIR: privateDir },
    stdio: ['ignore', 'ignore', 'pipe']
  });
  let log = '';
  server.stderr.on('data', (chunk) => { log += chunk; });
  server.log = () => log;
  return server;
}

async function waitForServer(server) {
  for (let attempt = 0; attempt < 50; attempt++) {
    if (server.exitCode !== null) {
      throw new Error('PHP server stopped:\n' + server.log());
    }
    try {
      const response = await fetch(baseUrl + 'api/app.php?action=version');
      if (response.ok) {
        return;
      }
    } catch {
      // Not listening yet.
    }
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  throw new Error('PHP server did not start:\n' + server.log());
}

async function api(context, action, body) {
  const url = baseUrl + 'api/app.php?action=' + action;
  const response = body === undefined ? await context.request.get(url) : await context.request.post(url, { data: body });
  const result = await response.json();
  if (!response.ok()) {
    throw new Error(`${action} failed: ${response.status()} ${JSON.stringify(result)}`);
  }
  return result;
}

async function newContext(browser, options = {}) {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 800 },
    locale: 'en-US',
    timezoneId,
    colorScheme: 'light',
    ...options
  });
  await context.clock.setFixedTime(now);
  return context;
}

// Always exactly one screen (the context's viewport), like seen on a laptop or phone.
async function screenshot(context, name, hash, options = {}) {
  const tab = await context.newPage();
  await tab.goto(baseUrl + 'index.htm' + hash);
  await tab.waitForSelector('#hero:not([hidden])');
  await tab.waitForLoadState('networkidle');
  await tab.evaluate(() => document.fonts.ready);
  if (options.before) {
    await options.before(tab);
  }
  await tab.waitForTimeout(300);
  await tab.screenshot({ path: join(outDir, name + '.png') });
  await tab.close();
  console.log('screenshots/' + name + '.png');
}

const privateDir = preparePrivateDir();
const server = startServer(privateDir);
let browser;
try {
  await waitForServer(server);
  mkdirSync(outDir, { recursive: true });
  browser = await chromium.launch();

  // The organizer: becomes admin (first visitor), sets the page text, shares the invitation.
  const adminContext = await newContext(browser);
  const { adminLink } = await api(adminContext, 'claim_admin', admin);
  // Like after opening the admin link: the Admin tab shows it as saved in this browser.
  await adminContext.addInitScript((link) => {
    window.localStorage.setItem('volunteertable-admin-link', link);
  }, adminLink);
  await api(adminContext, 'admin_save_page', page);
  const inviteLink = (await api(adminContext, 'my')).inviteLink;
  const inviteToken = new URL(inviteLink, baseUrl).searchParams.get('invite');

  // A volunteer: opens the invitation and registers for a few shifts.
  const volunteerContext = await newContext(browser);
  await api(volunteerContext, 'join', { token: inviteToken });
  const { events } = await api(volunteerContext, 'events');
  for (const name of volunteerShifts) {
    const event = events.find((candidate) => candidate.name === name);
    await api(volunteerContext, 'book', { eventId: event.id, ...volunteer, lang: 'en' });
  }

  await screenshot(volunteerContext, 'timetable', '#timetable-tab');
  await screenshot(volunteerContext, 'events', '#events-tab');
  await screenshot(volunteerContext, 'my-events', '#my-events-tab');
  await screenshot(volunteerContext, 'locations', '#locations-tab');
  await screenshot(adminContext, 'admin', '#admin-tab');

  // The same volunteer on a phone.
  const phoneContext = await newContext(browser, {
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    storageState: await volunteerContext.storageState()
  });
  await screenshot(phoneContext, 'phone', '#timetable-tab');
} finally {
  await browser?.close();
  server.kill();
  rmSync(privateDir, { recursive: true, force: true });
}
