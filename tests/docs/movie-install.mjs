#!/usr/bin/env node
/**
 * Records the install and configuration movie (MP4 only, no GIF) from a FRESH demo whose plugin
 * talks to stand-in.mjs, like movie.mjs. An administrator installs the plugin (terminal: Composer,
 * the API key in .env, `php craft plugin/install`), opens Settings → Plugins → Supertext
 * Translation (API key as the environment variable reference $SUPERTEXT_API_KEY), saves and sees
 * the API key check, sets the Deutsch site's Supertext language in the Languages table and shows
 * where editors find "Translate with Supertext". See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://127.0.0.1:8090 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… npm run movie:install
 *
 * The demo's SUPERTEXT_API_KEY should be a made-up one (the stand-in accepts any key); the terminal
 * shows st-demo-0000-0000-0000. MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes
 * (default tests/docs/movie/out/CraftCms-Supertext-Translation-install.mp4, git-ignored). Needs ffmpeg.
 */
import { chromium } from 'playwright'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const B = (process.env.BASE_URL || 'http://127.0.0.1:8090').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT || here('movie/out/CraftCms-Supertext-Translation-install.mp4')
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()
const FAKE_KEY = 'st-demo-0000-0000-0000'
const LIVE_API = 'https://api.supertext.com/v1/'
/** The public demo's address, shown instead of the local one (ping URL). */
const SITE_URL = process.env.SITE_URL || 'https://craftcms-production-4aa0.up.railway.app/'

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })

// Sign in as the demo administrator (not recorded): configuration is an administrator's task.
await page.goto(`${B}/admin/login`)
await page.locator('input.login-username').first().fill(need('DEMO_ADMIN_EMAIL'))
await page.locator('input.login-password').first().fill(need('DEMO_ADMIN_PASSWORD'))
await page.locator('button[type=submit]').first().click()
await page.waitForURL(/\/admin\/(?!login)/)
await page.goto(`${B}/admin/content/entries/articles?site=en`)
const entryId = await page.locator('tr[data-id]').filter({ hasText: 'Swiss chocolate' }).first().getAttribute('data-id')

/**
 * The settings page shows where the plugin sends requests: show the live API and the public demo, never
 * the local addresses. The demo's *Custom API address* ($SUPERTEXT_API_URL, the stand-in) is hidden (still saved).
 */
async function maskLocal() {
  await page.addStyleTag({ content: '[id$="apiUrl-field"]{display:none!important}' }).catch(() => {})
  await page.evaluate(([live, site]) => {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      if (/127\.0\.0\.1|localhost/.test(n.textContent)) {
        n.textContent = n.textContent.replace(/https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?\/v1\//g, live).replace(/https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?\//g, site)
      }
    }
  }, [LIVE_API, SITE_URL]).catch(() => {})
}
const apiKey = page.locator('input[name="settings[apiKey]"]')
const ripple = () => page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'craft-movie-install-'))
await page.goto('about:blank')
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })

/** More commands in the terminal kit.terminal() opened (same look; kit.terminal() would start a new one). */
async function typeMore(lines) {
  await page.evaluate(() => document.querySelector('#x > .p.c:last-child')?.remove())
  for (const l of lines) {
    if (l.cmd !== undefined) {
      await page.evaluate(() => { const x = document.getElementById('x')
        const p = document.createElement('span'); p.className = 'p'; p.textContent = '$ '; x.appendChild(p)
        const c = document.createElement('span'); c.className = 'c'; x.appendChild(c) })
      for (const ch of l.cmd) { await page.evaluate((ch) => { document.querySelector('#x .c').textContent += ch }, ch); await page.waitForTimeout(38) }
      await page.waitForTimeout(450)
      await page.evaluate(() => { document.querySelector('#x .c').classList.remove('c'); document.getElementById('x').append('\n') })
    } else {
      await page.evaluate((o) => { const s = document.createElement('span'); s.className = 'o'; s.textContent = o + '\n'; document.getElementById('x').appendChild(s) }, l.out ?? '')
      await page.waitForTimeout(l.wait ?? 180)
    }
  }
  await page.evaluate(() => { const c = document.createElement('span'); c.className = 'p c'; c.textContent = '$ '; document.getElementById('x').appendChild(c) })
}

// 1. Terminal: the commands from docs/INSTALLATION.md (output shortened).
await kit.say('The plugin isn’t in the Plugin Store yet. So first, add its GitHub repository to your Craft project’s Composer setup.', {
  caption: 'Add the plugin’s repository to <b>Composer</b>',
  during: () => kit.terminal({ title: 'my-site', lines: [
    { cmd: 'composer config repositories.supertext vcs https://github.com/Supertext/CraftCms-Supertext-Translation' },
  ] }),
})
await kit.say('Then require the plugin. Composer downloads it into your project.', {
  caption: '<b>composer require</b> the plugin',
  during: () => typeMore([
    { cmd: 'composer require supertext/craft-supertext-translation:dev-main' },
    { out: './composer.json has been updated' },
    { out: 'Package operations: 1 install, 0 updates, 0 removals' },
    { out: '  - Installing supertext/craft-supertext-translation (dev-main)' },
  ]),
})
await kit.say('Generate your API key at supertext.com, under Integrations, A P I. No account yet? Create one at supertext.com. Put the key in your environment, for example in dot env.', {
  caption: 'API key: <b>supertext.com → Integrations → API</b><br>No account yet? Create one at <b>supertext.com</b>',
  during: async () => { await kit.hold(1200); await typeMore([{ cmd: `echo 'SUPERTEXT_API_KEY="${FAKE_KEY}"' >> .env` }]) },
})
await kit.say('Then install the plugin. This creates its two small database tables.', {
  caption: 'Install the plugin: <b>php craft plugin/install</b>',
  during: () => typeMore([
    { cmd: 'php craft plugin/install supertext-translation' },
    { out: '*** installing supertext-translation' },
    { out: '    > create table {{%supertext_translations}} ... done (time: 0.011s)' },
    { out: '    > create table {{%supertext_state}} ... done (time: 0.008s)' },
    { out: '*** installed supertext-translation successfully (time: 0.122s)' },
  ]),
})
await kit.hold(300)

// 2. The control panel as administrator: Settings → Plugins → Supertext Translation (loading time cut).
kit.pause()
await page.goto(`${B}/admin/settings`)
await page.locator('#content a[href*="settings/plugins"]').first().waitFor()
await kit.sync()
kit.resume()
await kit.say('Now sign in to the control panel as an administrator, and open Settings, Plugins.', {
  caption: 'In the control panel: <b>Settings → Plugins</b>',
  during: async () => {
    await kit.hold(600)
    const plugins = page.locator('#content a[href*="settings/plugins"]').first()
    await kit.moveTo(plugins)
    await ripple()
    await Promise.all([page.waitForURL(/settings\/plugins/), plugins.click()])
    await page.locator('tr', { hasText: 'Supertext Translation' }).first().waitFor()
    await kit.sync()
  },
})
const settingsLink = page.locator('tr', { hasText: 'Supertext Translation' }).first().locator('.link-settings a').first()
await kit.say('Next to Supertext Translation, click Settings.', {
  caption: '<b>Supertext Translation</b> → <b>Settings</b>',
  during: async () => {
    await kit.moveTo(settingsLink)
    await ripple()
    await Promise.all([page.waitForURL(/settings\/plugins\/supertext-translation/), settingsLink.click()])
    await apiKey.waitFor()
    await maskLocal()
    await kit.sync()
  },
})

// 3. API key: the environment variable reference.
await kit.say('The API key field refers to the environment variable. Keep the reference, so the key never ends up in your project config.', {
  caption: 'API key: <b>$SUPERTEXT_API_KEY</b> (an environment variable)',
  during: async () => { await kit.hold(300); await kit.moveTo(apiKey) },
})

// 4. Languages: the Supertext language per site.
const table = page.locator('[data-supertext-languages]')
const german = table.locator('[data-supertext-site="de"]')
const select = german.locator('select[data-supertext-code]')
await kit.say('Below, the Languages table has one row per site, with its Supertext language already suggested.', {
  caption: '<b>Languages</b>: one row per site, the <b>Supertext language</b> suggested',
  during: async () => {
    await table.evaluate((el) => window.scrollBy({ top: el.getBoundingClientRect().top - 160, behavior: 'smooth' }))
    await kit.hold(900)
    await kit.moveTo(select)
  },
})
await kit.say('Pick another variant if you need one. For Deutsch, we keep German, Switzerland.', {
  caption: 'Deutsch: <b>German (Switzerland) (de-CH)</b>',
  during: async () => {
    await ripple()
    await select.focus()
    await kit.hold(500)
    await select.selectOption('de-CH')
    await kit.hold(400)
  },
})
const review = german.locator('.lightswitch').first()
await kit.say('Translation review can be switched on per site. We leave it off.', {
  caption: '<b>Translation review</b>: optional, per site (left off)',
  during: async () => { await kit.hold(300); await kit.moveTo(review) },
})
const save = page.locator('button.btn.submit[type=submit]').first()
const notice = page.getByText('the connection to Supertext works', { exact: false }).first()
await kit.say('Click Save. The plugin checks the API key right away: the connection to Supertext works.', {
  caption: 'Click <b>Save</b>: the plugin checks the API key',
  during: async () => {
    await kit.moveTo(save)
    await ripple()
    await Promise.all([page.waitForEvent('load'), save.click()])
    await kit.caption('API key check: <b>the connection to Supertext works</b>')
    await notice.waitFor()
    await kit.moveTo(notice)
  },
})

// 5. Where editors find it.
kit.pause()
await page.goto(`${B}/admin/content/entries/articles/${entryId}?site=en`)
await page.locator('.supertext-box .st-translate').waitFor()
await page.locator('#title').blur().catch(() => {})
await kit.sync()
kit.resume()
await kit.say("That's it. Editors now find the box Translate with Supertext on every entry page.", {
  caption: 'Done: <b>Translate with Supertext</b> on every entry',
  during: async () => {
    await kit.hold(400)
    await kit.moveTo(page.locator('.supertext-box legend'))
  },
  after: 200,
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'Craft CMS', repo: 'CraftCms-Supertext-Translation', kind: 'install' })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out}`)
