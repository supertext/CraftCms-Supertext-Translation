#!/usr/bin/env node
/**
 * Regenerates docs/images from a freshly set-up demo (no translations yet): the article, the page
 * "About us" and the global set "Footer". In CI the plugin
 * translates with the real Supertext API (secret SUPERTEXT_API_KEY), so the guides show real
 * translations; without a key it talks to stand-in.mjs (SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/).
 * Reports always go to the stand-in (SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report), never to
 * the live Commando Center.
 * See docs/DEVELOPER.md.
 *
 *   BASE_URL (default http://127.0.0.1:8090)
 *   DEMO_ADMIN_EMAIL / DEMO_ADMIN_PASSWORD     settings, sites, permissions (admin)
 *   DEMO_EDITOR_EMAIL / DEMO_EDITOR_PASSWORD   translating (Editors group)
 */
import { chromium } from 'playwright'

const B = process.env.BASE_URL || 'http://127.0.0.1:8090'
const OUT = new URL('../../docs/images/', import.meta.url).pathname
const LIVE_API = 'https://api.supertext.com/v1/'
/** The public demo's address, shown instead of the local one. */
const SITE_URL = process.env.SITE_URL || 'https://craftcms-production-4aa0.up.railway.app/'
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()

const browser = await chromium.launch()

async function session(email, password) {
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1, locale: 'en-US' })).newPage()
  await page.goto(`${B}/admin/login`)
  await page.locator('input.login-username').first().fill(email)
  await page.locator('input.login-password').first().fill(password)
  await page.locator('button[type=submit]').first().click()
  await page.waitForURL(/\/admin\/(?!login)/)
  return page
}

async function go(page, path) {
  await page.goto(B + path)
  await page.waitForLoadState('networkidle')
  await page.addStyleTag({ content: '*{animation:none!important;transition:none!important;caret-color:transparent!important}' })
  await page.waitForTimeout(500)
}

/** Screenshot of an element with a margin. */
async function shot(page, locator, name, margin = 10) {
  await locator.scrollIntoViewIfNeeded()
  await page.waitForTimeout(200)
  const r = await locator.boundingBox()
  await page.screenshot({ path: OUT + name, clip: { x: Math.max(0, r.x - margin), y: Math.max(0, r.y - margin), width: r.width + 2 * margin, height: r.height + 2 * margin } })
}

// --- Editor: translate ---------------------------------------------------------------------
{
  const page = await session(need('DEMO_EDITOR_EMAIL'), need('DEMO_EDITOR_PASSWORD'))
  await go(page, '/admin/content/entries/articles?site=en')
  const id = await page.locator('tr[data-id]').filter({ hasText: 'Swiss chocolate' }).first().getAttribute('data-id')

  await go(page, `/admin/content/entries/articles/${id}?site=en`)
  const box = page.locator('.supertext-box')
  await box.locator('.st-translate').waitFor()
  await page.locator('#title').blur().catch(() => {})
  await shot(page, box, '01-translate-box.png')

  await box.locator('.st-translate').click()
  await box.locator('.st-results li').first().waitFor({ timeout: 300_000 }) // the real API takes a while
  await shot(page, box, '02-translated.png')

  // The German version of the entry.
  await go(page, `/admin/content/entries/articles/${id}?site=de`)
  const top = await page.locator('#main-content, #content').first().boundingBox()
  await page.screenshot({ path: OUT + '03-german-entry.png', clip: { x: top.x, y: 0, width: Math.min(1280 - top.x, 1030), height: 900 } })

  // Back in English: every site is translated now; choosing one shows the overwrite warning.
  await go(page, `/admin/content/entries/articles/${id}?site=en`)
  await box.locator('.st-site input').first().waitFor()
  await box.locator('.st-site input').first().check()
  await box.locator('.st-warning').waitFor({ state: 'visible' })
  await shot(page, box, '04-overwrite-warning.png')

  // Index action
  await go(page, '/admin/content/entries/articles?site=en')
  await page.locator('tr[data-id]').filter({ hasText: 'Swiss chocolate' }).first().locator('.checkbox').click()
  await page.waitForTimeout(600)
  await page.locator('button.menubtn[data-icon="settings"]').first().click()
  await page.getByText('Translate with Supertext', { exact: true }).waitFor()
  await page.waitForTimeout(300)
  await page.screenshot({ path: OUT + '05-index-action.png', clip: { x: 226, y: 0, width: 1054, height: 720 } })

  // The page "About us": translated with its box, then its German version (nested callout, table, link).
  await go(page, '/admin/content/entries/pages?site=en')
  const pageId = await page.locator('tr[data-id]').filter({ hasText: 'About us' }).first().getAttribute('data-id')
  await go(page, `/admin/content/entries/pages/${pageId}?site=en`)
  await box.locator('.st-translate').waitFor()
  await box.locator('.st-translate').click()
  await box.locator('.st-results li').first().waitFor({ timeout: 300_000 })
  await page.setViewportSize({ width: 1280, height: 1100 })
  await go(page, `/admin/content/entries/pages/${pageId}?site=de`)
  const fields = await page.locator('#main-content, #content').first().boundingBox()
  await page.screenshot({ path: OUT + '09-german-page.png', clip: { x: fields.x, y: 0, width: Math.min(1280 - fields.x, 1030), height: 1100 } })
  await page.setViewportSize({ width: 1280, height: 900 })

  // A global set: the box sits below its fields.
  await go(page, '/admin/globals/footer?site=en')
  await box.locator('.st-translate').waitFor()
  await shot(page, page.locator('#content').first(), '10-global-set.png', 0)
}

// --- Admin: settings, sites ------------------------------------------------------------
{
  const page = await session(need('DEMO_ADMIN_EMAIL'), need('DEMO_ADMIN_PASSWORD'))
  await go(page, '/admin/settings/plugins/supertext-translation')
  await page.locator('[data-supertext-test-button]').click()
  await page.locator('[data-supertext-test-result].success, [data-supertext-test-result].error').waitFor({ timeout: 20_000 })
  await page.evaluate(([live, site]) => {
    // The live API instead of the stand-in, the public demo instead of the local address (ping URL).
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      if (n.textContent.includes('127.0.0.1')) n.textContent = n.textContent.replace(/http:\/\/127\.0\.0\.1:\d+\/v1\//, live).replace(/https?:\/\/127\.0\.0\.1:\d+\//, site)
    }
    document.querySelectorAll('input').forEach((i) => { if (i.value.includes('127.0.0.1')) i.value = live })
  }, [LIVE_API, SITE_URL])
  const form = page.locator('#main-content, #content').first()
  const r = await form.boundingBox()
  await page.screenshot({ path: OUT + '06-settings.png', clip: { x: r.x, y: 0, width: Math.min(1280 - r.x, 1054), height: 900 } })
  await page.setViewportSize({ width: 1280, height: 1400 })
  await go(page, '/admin/settings/plugins/supertext-translation')
  const table = page.locator('[data-supertext-languages]')
  await shot(page, table, '07-settings-languages.png', 16)
  await page.setViewportSize({ width: 1280, height: 900 })

  await go(page, '/admin/settings/sites')
  // Show the public demo's address instead of the local one.
  await page.evaluate((site) => {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
    for (let n = walker.nextNode(); n; n = walker.nextNode()) if (/^https?:\/\/127\.0\.0\.1:\d+/.test(n.textContent)) n.textContent = n.textContent.replace(/^https?:\/\/127\.0\.0\.1:\d+\//, site)
  }, SITE_URL)
  await page.screenshot({ path: OUT + '08-sites.png', clip: { x: 226, y: 0, width: 1054, height: 420 } })
}

await browser.close()
console.log(`Screenshots written to ${OUT}`)
