#!/usr/bin/env node
/**
 * Docs screenshots and CI only: answers like the Supertext AI file translation API v1 and
 * returns German, French and Italian for the demo's sample article (samples.json: real
 * Supertext output, keyed by target language's primary subtag).
 * Unknown text comes back as "[<target>] text". Like the real API, it accepts exactly Supertext's
 * language list (targets "de-CH", "es", "zh-Hans", …; bare sources) and answers anything else with
 * HTTP 404 INVALID_LANGUAGE_PAIR. Listens on :8765 (any API key).
 *
 * It stands in for the Supertext Commando Center too (SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report),
 * so screenshots and CI never report to the live center: POST /report answers 202 and keeps the
 * report; GET /reports lists them (for tests/demo-check.sh).
 */
import http from 'node:http'
import { randomBytes } from 'node:crypto'
import { readFileSync } from 'node:fs'

const norm = (s) => s.replace(/\s+/g, ' ').replace(/> </g, '><').trim()
const decode = (s) => s.replace(/&quot;/g, '"').replace(/&#0?39;|&apos;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')
const encode = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const samples = Object.fromEntries(
  Object.entries(JSON.parse(readFileSync(new URL('samples.json', import.meta.url), 'utf8'))).map(([lang, pairs]) => [
    lang,
    new Map(Object.entries(pairs).map(([k, v]) => [norm(k), v])),
  ]),
)
const files = new Map()
const reports = []
// Supertext's language list (GET features, October 2026; the SDK's Languages).
const TARGETS = new Set('bg cs da de-AT de-CH de-DE el en-GB en-US es fi fr-CH fr-FR gsw-u-sd-chbe gsw-u-sd-chzh hr hu it-CH it-IT ja ko nb nl pl pt-BR pt-PT rm ru sk sl sq sr-Cyrl sr-Latn sv tr zh-Hans zh-Hant'.split(' '))
const SOURCES = new Set('bg cs da de el en es fi fr gsw hr hu it ja ko nb nl pl pt rm ru sk sl sq sr sv tr zh'.split(' '))

http
  .createServer(async (req, res) => {
    const path = new URL(req.url, 'http://x').pathname.replace(/^\/v1/, '')
    const send = (status, body, type = 'application/json') => {
      res.writeHead(status, { 'Content-Type': type })
      res.end(type === 'application/json' ? JSON.stringify(body) : body)
    }
    if (req.method === 'POST' && path === '/report') {
      const chunks = []
      for await (const chunk of req) chunks.push(chunk)
      try { reports.push(JSON.parse(Buffer.concat(chunks).toString('utf8'))) } catch { return send(400, { ok: false, error: 'not JSON' }) }
      return send(202, { ok: true })
    }
    if (req.method === 'GET' && path === '/reports') return send(200, reports)
    if (!/^Supertext-Auth-Key \S+$/.test(req.headers.authorization || '')) return send(401, { detail: 'missing key' })
    if (path === '/features') return send(200, {})
    if (req.method === 'POST') {
      const chunks = []
      for await (const chunk of req) chunks.push(chunk)
      const form = await new Request('http://x', { method: 'POST', headers: req.headers, body: Buffer.concat(chunks) }).formData()
      const file = form.get('file')
      if (!file || file.type !== 'text/html') return send(415, { detail: 'FILETYPE_NOT_ALLOWED' })
      // Like the real API: only its own codes ("de" or "es-ES" as a target, "en-US" as a source are rejected).
      const target = String(form.get('target_lang') ?? '')
      const source = form.get('source_lang')
      if (!TARGETS.has(target) || (source !== null && !SOURCES.has(String(source)))) {
        return send(404, { error_code: 'INVALID_LANGUAGE_PAIR', source_lang: source, target_lang: target })
      }
      const id = randomBytes(6).toString('hex')
      files.set(id, { html: await file.text(), target })
      return send(200, { file_id: id })
    }
    const match = path.match(/file\/([a-f0-9]+)(\/status|\/translation)?$/)
    const file = match && files.get(match[1])
    if (!file) return send(404, {})
    if (req.method === 'DELETE') { files.delete(match[1]); return send(200, {}) }
    if (match[2] === '/status') return send(200, { status: 'done' })
    const known = samples[file.target.split('-')[0]] ?? new Map()
    const html = file.html.replace(/(<div data-st-id="\d+">)([\s\S]*?)(<\/div>\n)/g, (all, open, inner, close) => {
      const hit = known.get(norm(inner)) ?? known.get(norm(decode(inner)))
      if (hit !== undefined) return open + (known.has(norm(inner)) ? hit : encode(hit)) + close
      // Texts with addresses that differ per demo (entry and asset reference tags, the site's URL):
      // samples.json writes them as @@0@@, @@1@@, … in order; the request's addresses are put back.
      const addresses = []
      const tokenized = inner.replace(/(href|src)="([^"]*)"/g, (m, name, value) => `${name}="@@${addresses.push(value) - 1}@@"`)
      const pattern = addresses.length ? known.get(norm(tokenized)) : undefined
      if (pattern !== undefined) return open + pattern.replace(/@@(\d+)@@/g, (m, i) => addresses[Number(i)] ?? m) + close
      return open + inner.replace(/>([^<]+)</g, (m, t) => (t.trim() ? `>[${file.target}] ${t}<` : m)).replace(/^([^<]+)/, `[${file.target}] $1`) + close
    })
    send(200, html, 'text/html')
  })
  .listen(Number(process.env.PORT || 8765), '127.0.0.1', () => console.log(`Stand-in API on http://127.0.0.1:${process.env.PORT || 8765}/v1/`))
