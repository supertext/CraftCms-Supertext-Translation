#!/usr/bin/env node
/**
 * Records the demo movie (docs/images/demo.gif and an MP4) from a FRESH demo (no translations yet)
 * whose plugin talks to stand-in.mjs, like screenshots.mjs. The editor opens the sample article in
 * English, translates it with the Supertext box into German and French and opens the German
 * version. See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://127.0.0.1:8090 DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run movie
 *
 * Voice: Piper (pip install piper-tts), voice "alba" downloaded once into movie/voice-cache/
 * (git-ignored); MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * tests/docs/movie/out/CraftCms-Supertext-Translation.mp4, git-ignored; the MP4 is not committed).
 * Needs ffmpeg. CHROMIUM_PATH: another Chromium than Playwright's.
 */
import { chromium } from 'playwright'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const B = (process.env.BASE_URL || 'http://127.0.0.1:8090').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT || here('movie/out/CraftCms-Supertext-Translation.mp4')
const gif = here('../../docs/images/demo.gif')
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })

// Sign in as the demo editor (not recorded).
await page.goto(`${B}/admin/login`)
await page.locator('input.login-username').first().fill(need('DEMO_EDITOR_EMAIL'))
await page.locator('input.login-password').first().fill(need('DEMO_EDITOR_PASSWORD'))
await page.locator('button[type=submit]').first().click()
await page.waitForURL(/\/admin\/(?!login)/)

await page.goto(`${B}/admin/content/entries/articles?site=en`)
const id = await page.locator('tr[data-id]').filter({ hasText: 'Swiss chocolate' }).first().getAttribute('data-id')
// The movie only looks: no autosaved drafts (Craft's editor may normalise the rich text on load) and
// no "This entry has been updated" notice when the translation saves the other sites.
await page.route(/elements(\/|%2F)(save-draft|recent-activity)/i, (route) => route.abort())
await page.addInitScript(() => document.addEventListener('DOMContentLoaded', () => {
  const style = document.createElement('style')
  style.textContent = '#revision-indicators{visibility:hidden!important}' // the skipped autosave's status icon
  document.head.appendChild(style)
}))
await page.goto(`${B}/admin/content/entries/articles/${id}?site=en`)
const box = page.locator('.supertext-box')
await box.locator('.st-translate').waitFor()
await page.locator('#title').blur().catch(() => {})

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'craft-movie-'))
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })

// 1. The English entry.
await kit.say("Here's an entry in English, “Swiss chocolate, shipped worldwide”, in the Craft control panel.", {
  caption: 'An entry in English: “Swiss chocolate, shipped worldwide”',
  during: () => kit.moveTo(page.locator('#title')),
})

// 2. The Supertext box: choose the sites.
await kit.say('On the right, the box Translate with Supertext lists the entry’s other sites.', {
  caption: 'The box <b>Translate with Supertext</b>, next to the entry',
  during: () => kit.moveTo(box.locator('legend')),
})
await kit.say('Keep German and French, and untick Italian.', {
  caption: 'Choose the sites: <b>Deutsch</b> and <b>Français</b>',
  during: async () => {
    await kit.moveTo(box.locator('label[for^="st-site-"]', { hasText: 'Deutsch' }))
    await kit.hold(300)
    await kit.moveTo(box.locator('label[for^="st-site-"]', { hasText: 'Français' }))
    await kit.hold(300)
    await kit.click(box.locator('label[for^="st-site-"]', { hasText: 'Italiano' }))
  },
})
const button = box.locator('.st-translate')
await kit.say('Then click Translate.', { caption: 'Click <b>Translate</b>', during: () => kit.moveTo(button), after: 0 })

// The stand-in answers at once: send the request only after the progress state has been seen.
let release
const released = new Promise((resolve) => (release = resolve))
await page.route(/translate\/entry/, async (route) => {
  if (route.request().method() === 'POST') await released
  await route.continue()
})
await page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
const done = box.locator('.st-results li').nth(1).waitFor({ timeout: 300_000 })
await kit.say('Supertext translates the content.', {
  caption: 'Supertext translates the content…',
  during: async () => {
    await button.click()
    await kit.hold(1300)
    release()
  },
})
kit.pause()
await done
kit.resume()
await kit.sync()

// 3. The result.
await kit.say('A few seconds later, German and French are translated, each saved as a new revision.', {
  caption: 'Done: <b>Deutsch</b> and <b>Français</b> translated',
  during: () => kit.moveTo(box.locator('.st-results')),
})
const open = box.locator('.st-results li', { hasText: 'Deutsch' }).locator('a')
await kit.say("Let's open the German version and check it.", {
  caption: 'Open the German version: <b>Open</b>',
  during: () => kit.moveTo(open),
})
// Click, and cut the loading of the German site's page.
await page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
await kit.hold(250)
kit.pause()
await open.click()
await page.waitForURL(/site=de/)
await page.locator('.ck-content').first().waitFor()
await box.locator('.st-translate').waitFor()
await page.waitForTimeout(500)
await page.locator('#title').blur().catch(() => {})
await kit.sync()
kit.resume()
await kit.say('The German version is ready. Review it, and adjust it if you like.', {
  caption: 'The German version is ready – review it in Craft',
  during: async () => {
    await kit.moveTo(page.locator('#title'))
    await kit.hold(300)
    const body = page.locator('.ck-content').first()
    if (await body.isVisible().catch(() => false)) await kit.moveTo(body)
  },
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'Craft CMS', repo: 'CraftCms-Supertext-Translation', gif })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out} and ${gif} (${(fs.statSync(gif).size / 1e6).toFixed(2)} MB)`)
