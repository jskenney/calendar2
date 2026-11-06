#!/usr/bin/env node
/*
 * Browser check (milestone 7), with Playwright and Chromium:
 *
 *   node tests/browser/check.js                          # check every fixture and the demo course
 *   node tests/browser/check.js --screenshots=DIR        # also save the review screenshots
 *   node tests/browser/check.js --only=demo,kitchen-sink
 *
 * For each course it opens the calendar views and event pages as a student and as the
 * instructor, at desktop and phone size, in light and dark mode, and fails when a page
 * - requests Bootstrap or jQuery, or anything from another host,
 * - gets a failed response (4xx/5xx) or logs a JavaScript error,
 * - scrolls sideways (the page is wider than the screen),
 * - shows PHP warnings.
 */
'use strict';

const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');
const { chromium, devices } = require('playwright');

const ROOT = path.resolve(__dirname, '..', '..');
const args = Object.fromEntries(process.argv.slice(2).map((a) => {
  const m = a.match(/^--([a-z]+)(?:=(.*))?$/);
  return m ? [m[1], m[2] === undefined ? true : m[2]] : [a, true];
}));
const COURSES = args.only ? String(args.only).split(',') : ['demo', 'sample', 'core-tests', 'kitchen-sink', 'edge-cases', 'access-rules'];
const SHOTS = typeof args.screenshots === 'string' ? path.resolve(args.screenshots) : null;
const VIEWPORTS = {
  desktop: { viewport: { width: 1280, height: 860 } },
  phone: { ...devices['iPhone 13'] },
};

function serve(course) {
  return new Promise((resolve, reject) => {
    const child = spawn('php', [path.join(__dirname, 'serve.php'), course], { cwd: ROOT, stdio: ['pipe', 'pipe', 'inherit'] });
    let out = '';
    child.stdout.on('data', (chunk) => {
      out += chunk;
      const line = out.split('\n')[0];
      if (out.includes('\n')) {
        try { resolve({ child, ...JSON.parse(line) }); } catch (e) { reject(new Error('serve.php: ' + out)); }
      }
    });
    child.on('exit', (code) => reject(new Error(`serve.php ${course} exited (${code})`)));
  });
}

function fixtureTypes(course) {
  if (course === 'demo') { return ['class', 'lab', 'exam']; }
  return JSON.parse(fs.readFileSync(path.join(ROOT, 'tests/fixtures', course, 'fixture.json'), 'utf8')).types;
}

/** The pages checked for a course: the three calendar views and up to three events of each type. */
function pages(course, entry) {
  const list = ['show=calendar_display', 'show=calendar_display_alt', 'show=calendar_display_dateless'];
  for (const type of fixtureTypes(course)) {
    for (let n = 1; n <= 3; n++) { list.push(`type=${type}&event=${n}`); }
  }
  return list.map((q) => `${entry}?${q}`);
}

const problems = [];
function problem(where, text) { problems.push(`${where}: ${text}`); }

async function newContext(browser, base, viewport, scheme, instructor, password, entry) {
  const context = await browser.newContext({ ...VIEWPORTS[viewport], colorScheme: scheme, baseURL: base });
  if (instructor) {
    const response = await context.request.get(`${entry}?password=${encodeURIComponent(password)}`);
    if (!response.ok()) { throw new Error('login failed'); }
  }
  return context;
}

async function visit(context, base, url, where) {
  const page = await context.newPage();
  const origin = new URL(base).origin;
  page.on('request', (request) => {
    const u = request.url();
    if (/bootstrap|jquery/i.test(u)) { problem(where, 'requested ' + u); }
    if (!u.startsWith(origin) && !u.startsWith('data:') && !u.startsWith('blob:')) { problem(where, 'requested another host: ' + u); }
  });
  page.on('response', (response) => {
    if (response.status() >= 400) { problem(where, `${response.status()} ${response.url()}`); }
  });
  page.on('pageerror', (error) => problem(where, 'JavaScript error: ' + error.message));
  page.on('console', (message) => { if (message.type() === 'error') { problem(where, 'console: ' + message.text()); } });
  let response;
  try {
    response = await page.goto(url, { waitUntil: 'load' });
  } catch (error) {
    if (String(error.message).includes('Download is starting')) {
      return page; // the event's title is a file (slides.pdf): a download, nothing to look at
    }
    throw error;
  }
  if (!response || response.headers()['content-type']?.startsWith('text/html') === false) {
    return page; // a file shown by the browser (a PDF): nothing to look at
  }
  await page.evaluate(() => (window.MathJax && MathJax.startup && MathJax.startup.promise) || null).catch(() => {});
  const body = await page.content();
  if (/<b>(Warning|Notice|Deprecated|Fatal error)<\/b>:/.test(body)) { problem(where, 'PHP error on the page'); }
  const wide = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  if (wide > 1) { problem(where, `the page scrolls sideways by ${wide}px`); }
  return page;
}

/** Review screenshots: [file, role, viewport, scheme, url, action] */
function screenshotPlan(entry) {
  const month = `${entry}?show=calendar_display`;
  const lecture = `${entry}?type=class&event=11`;
  const plan = [];
  for (const scheme of ['light', 'dark']) {
    for (const viewport of ['desktop', 'phone']) {
      plan.push([`month-student-${viewport}-${scheme}`, 'student', viewport, scheme, month + '#today', null]);
      plan.push([`event-student-${viewport}-${scheme}`, 'student', viewport, scheme, lecture, null]);
      plan.push([`month-instructor-${viewport}-${scheme}`, 'instructor', viewport, scheme, month + '#today', null]);
    }
    plan.push([`list-student-desktop-${scheme}`, 'student', 'desktop', scheme, `${entry}?show=calendar_display_alt`, null]);
    plan.push([`event-instructor-files-desktop-${scheme}`, 'instructor', 'desktop', scheme, lecture, 'files']);
  }
  plan.push(['agenda-student-phone-light', 'student', 'phone', 'light', `${entry}?show=calendar_display_dateless`, null]);
  plan.push(['menu-classes-desktop-light', 'student', 'desktop', 'light', lecture, 'classes']);
  plan.push(['menu-classes-phone-dark', 'student', 'phone', 'dark', lecture, 'classes']);
  plan.push(['login-desktop-light', 'student', 'desktop', 'light', month, 'login']);
  plan.push(['print-event-desktop-light', 'student', 'desktop', 'light', lecture + '&print=1', null]);
  plan.push(['lab-student-desktop-light', 'student', 'desktop', 'light', `${entry}?type=lab&event=9`, null]);
  return plan;
}

async function main() {
  const browser = await chromium.launch();
  let checked = 0;
  try {
    for (const course of COURSES) {
      const server = await serve(course);
      try {
        for (const instructor of [false, true]) {
          for (const viewport of Object.keys(VIEWPORTS)) {
            for (const scheme of ['light', 'dark']) {
              const context = await newContext(browser, server.url, viewport, scheme, instructor, server.password, server.entry);
              for (const url of pages(course, server.entry)) {
                const where = `${course} ${instructor ? 'instructor' : 'student'} ${viewport} ${scheme} ${url}`;
                const page = await visit(context, server.url, url, where);
                await page.close();
                checked++;
              }
              await context.close();
            }
          }
        }
        if (SHOTS && (course === 'demo' || course === 'kitchen-sink')) {
          fs.mkdirSync(SHOTS, { recursive: true });
          const plan = course === 'demo' ? screenshotPlan(server.entry)
            : [['kitchen-sink-month-instructor-desktop-light', 'instructor', 'desktop', 'light', `${server.entry}?show=calendar_display`, null],
               ['kitchen-sink-event-student-desktop-light', 'student', 'desktop', 'light', `${server.entry}?type=class&event=1`, null]];
          for (const [file, role, viewport, scheme, url, action] of plan) {
            const context = await newContext(browser, server.url, viewport, scheme, role === 'instructor', server.password, server.entry);
            const page = await visit(context, server.url, url, `screenshot ${file}`);
            await page.waitForTimeout(300);
            if (action === 'files') { await page.click('.cal5-pagebar summary:has-text("Files")'); }
            if (action === 'classes') { await page.click('.cal5-primary summary:has-text("Classes")'); }
            if (action === 'login') { await page.click('.cal5-login-menu summary'); }
            await page.screenshot({ path: path.join(SHOTS, `${file}.png`), fullPage: action === null && !url.includes('#today') && viewport === 'desktop' });
            await context.close();
          }
        }
      } finally {
        server.child.stdin.end();
        await new Promise((resolve) => server.child.on('close', resolve));
      }
    }
  } finally {
    await browser.close();
  }
  if (problems.length > 0) {
    console.log(`${problems.length} problems in ${checked} page views:`);
    for (const p of [...new Set(problems)].slice(0, 60)) { console.log('  - ' + p); }
    process.exit(1);
  }
  console.log(`browser check: ${checked} page views, no Bootstrap or jQuery, no other hosts, no errors`);
}

main().catch((error) => { console.error(error); process.exit(1); });
