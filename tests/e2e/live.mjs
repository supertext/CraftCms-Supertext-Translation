#!/usr/bin/env node
/**
 * End-to-end test against the REAL Supertext API: a fresh demo (nothing translated yet)
 * with a real SUPERTEXT_API_KEY. The editor (DEMO_EDITOR_*) translates the sample article
 * into every other site with the Supertext box on the entry's edit page, then the database
 * is checked: the article and its nested Highlight entries have a translation in every site,
 * the texts are really in the target language (de-CH without "ß"), formatting and links
 * survived, nothing was enabled or disabled, and translating again (with "Overwrite existing
 * translations") updates the same records instead of duplicating them.
 * See "End-to-end tests" in docs/DEVELOPER.md.
 *
 *   CRAFT_URL=http://127.0.0.1:8081 DATABASE_URL=postgresql://postgres:postgres@127.0.0.1:5432/craft_live \
 *   EDITOR_EMAIL=… EDITOR_PASSWORD=… node tests/e2e/live.mjs
 */
import { chromium } from 'playwright';
import pg from 'pg';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const B = (process.env.CRAFT_URL ?? 'http://127.0.0.1:8081').replace(/\/$/, '');
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`); })();
const artifacts = fileURLToPath(new URL('./artifacts/', import.meta.url));
mkdirSync(artifacts, { recursive: true });

const failures = [];
function check(name, ok, detail = '') {
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
	if (!ok) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
	return ok;
}

// Common words, to tell the target languages apart. Lower case, whole words.
const STOPWORDS = {
	en: ['the', 'and', 'is', 'are', 'with', 'for', 'you', 'your', 'our', 'of', 'to', 'we', 'who', 'what'],
	de: ['der', 'die', 'das', 'und', 'ist', 'sind', 'mit', 'für', 'sie', 'ihre', 'ihr', 'unser', 'unsere', 'wir', 'eine', 'ein', 'den', 'zu', 'von', 'was'],
	fr: ['le', 'les', 'et', 'est', 'sont', 'avec', 'pour', 'vous', 'votre', 'vos', 'notre', 'nos', 'nous', 'des', 'une', 'un', 'du', 'de', 'qui', 'que'],
	it: ['il', 'lo', 'gli', 'e', 'è', 'sono', 'con', 'per', 'lei', 'sua', 'suo', 'nostro', 'nostra', 'noi', 'una', 'un', 'del', 'della', 'di', 'che', 'chi'],
};
const words = (text) => text.toLowerCase().match(/[\p{L}]+/gu) ?? [];
const score = (text, lang) => words(text).filter((w) => STOPWORDS[lang].includes(w)).length;
const plain = (v) => String(v ?? '').replace(/<[^>]+>/g, ' ');
const count = (haystack, needle) => String(haystack ?? '').split(needle).length - 1;
const decode = (s) => s.replace(/&#0?39;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');

const LEFTOVERS = ['data-st-id', 'data-st-ph', 'st-ph-'];
// What the sample's rich text has; each must survive in the same number. (Craft reference
// tags like {entry:12@1:url} would be checked too, but the sample article has none.)
const KEPT = [['links', '<a '], ['link targets', 'href="https://www.supertext.com"'], ['bold', '<strong'], ['italics', '<em'], ['headings', '<h2'], ['list items', '<li'], ['reference tags', '{entry:']];

const db = new pg.Client({ connectionString: need('DATABASE_URL') });
await db.connect();
const q = async (sql, params = []) => (await db.query(sql, params)).rows;
const live = 'el."draftId" IS NULL AND el."revisionId" IS NULL AND el."dateDeleted" IS NULL';

// --- Setup -------------------------------------------------------------------------------
const sites = await q('SELECT id, handle, language, "primary" FROM sites WHERE "dateDeleted" IS NULL ORDER BY "sortOrder"');
const sourceSite = sites.find((s) => s.primary);
const targets = sites.filter((s) => !s.primary);
const [article] = await q(`SELECT e.id FROM entries e JOIN sections s ON s.id = e."sectionId" JOIN elements el ON el.id = e.id
	WHERE s.handle = 'articles' AND ${live} ORDER BY e.id LIMIT 1`);
if (!article) throw new Error('No article: is this a fresh demo?');
const nested = (await q(`SELECT e.id FROM entries e JOIN elements el ON el.id = e.id WHERE e."primaryOwnerId" = $1 AND ${live} ORDER BY e.id`, [article.id])).map((r) => r.id);
const items = [article.id, ...nested];

// Field handles for the content keys (field layout element UIDs) in elements_sites.content.
const handles = new Map();
const fieldHandle = new Map((await q('SELECT uid, handle FROM fields')).map((f) => [f.uid, f.handle]));
for (const { config } of await q('SELECT config FROM fieldlayouts WHERE "dateDeleted" IS NULL')) {
	for (const tab of config?.tabs ?? []) for (const el of tab.elements ?? []) if (el.fieldUid) handles.set(el.uid, fieldHandle.get(el.fieldUid) ?? el.uid);
}

/** The translatable values of one element in one site: title, the article's slug, text fields. */
function fieldsOf(row) {
	const out = { title: row.title ?? '' };
	if (row.elementId === article.id) out.slug = row.slug ?? '';
	for (const [uid, v] of Object.entries(row.content ?? {})) if (typeof v === 'string') out[handles.get(uid) ?? uid] = v;
	return out;
}

/** elements_sites rows of the article and its nested entries, by site id and element id. */
async function snapshot() {
	const rows = await q(`SELECT es.id, es."elementId", es."siteId", es.title, es.slug, es.content, es.enabled, el.enabled AS "elementEnabled"
		FROM elements_sites es JOIN elements el ON el.id = es."elementId" WHERE es."elementId" = ANY($1)`, [items]);
	const out = {};
	for (const r of rows) (out[r.siteId] ??= {})[r.elementId] = r;
	const counted = await q(`SELECT COUNT(*)::int AS n FROM entries e JOIN elements el ON el.id = e.id
		WHERE ${live} AND (e.id = $1 OR e."primaryOwnerId" = $1 OR e."sectionId" = (SELECT "sectionId" FROM entries WHERE id = $1))`, [article.id]);
	return { bySite: out, entries: counted[0].n, rowIds: rows.map((r) => r.id).sort((a, b) => a - b).join(',') };
}

const before = await snapshot();
const source = before.bySite[sourceSite.id];
console.log(`Sample article #${article.id} with ${nested.length} nested entries; source ${sourceSite.language}; targets: ${targets.map((t) => t.language).join(', ')}`);
check('demo has target sites', targets.length > 0);
check('article and nested entries exist in every site', sites.every((s) => items.every((id) => before.bySite[s.id]?.[id])));
check('fresh demo (nothing translated yet)', targets.every((t) => items.every((id) => before.bySite[t.id]?.[id]?.title === source[id].title)));

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1280, height: 1000 }, locale: 'en-US' });
page.setDefaultTimeout(30_000);

/** Ticks a Craft checkbox (the label draws the box and takes the click, as for a person). */
async function tick(box, id) {
	const input = box.locator(`#${id}`);
	if (!(await input.isChecked())) await box.locator(`label[for="${id}"]`).click();
	return input.isChecked();
}

/** Translates the article into every target site with the Supertext box on its edit page. */
async function translateArticle(label, overwrite) {
	await page.goto(`${B}/admin/content/entries/articles/${article.id}?site=${sourceSite.handle}`);
	const box = page.locator('.supertext-box');
	await box.locator('.st-translate, .supertext-body > p').first().waitFor();
	const problems = await box.locator('.supertext-body > p.error, .supertext-body > p.warning').allTextContents();
	if (!check(`${label}: Supertext box without errors`, problems.length === 0, problems.join(' | '))) {
		await page.screenshot({ path: `${artifacts}${label}-box.png`, fullPage: true });
		return;
	}
	for (const site of targets) {
		const exists = (await box.locator(`#st-site-${site.id}`).count()) === 1;
		check(`${label}: ${site.language} can be chosen`, exists && (await tick(box, `st-site-${site.id}`)));
	}
	const warning = box.locator('.st-warning');
	if (overwrite) {
		check(`${label}: overwrite warning shown for translated sites`, await warning.isVisible());
		check(`${label}: "Overwrite existing translations" ticked`, await tick(box, 'st-overwrite'));
	} else {
		check(`${label}: no overwrite warning before the first translation`, !(await warning.isVisible()));
	}

	const started = Date.now();
	await box.locator('.st-translate').click();
	await box.locator('.st-results li').first().waitFor({ timeout: 900_000 });
	await page.screenshot({ path: `${artifacts}${label}-result.png`, fullPage: true });
	console.log(`${label}: ${Math.round((Date.now() - started) / 1000)} s`);
	const ok = await box.locator('.st-results li.ok').count();
	const skipped = await box.locator('.st-results li.skipped').allTextContents();
	const errors = await box.locator('.st-results li.error').allTextContents();
	const notices = await page.locator('#notifications .notification.error, #notifications .error').allTextContents();
	check(`${label}: every site translated`, ok === targets.length, `${ok}/${targets.length}${skipped.length ? `; skipped: ${skipped.join(' | ')}` : ''}`);
	check(`${label}: no errors from Supertext`, errors.length === 0, errors.map((e) => e.replace(/\s+/g, ' ').trim()).join(' | '));
	check(`${label}: no error notifications`, notices.length === 0, notices.join(' | '));
}

// --- Log in as the editor ----------------------------------------------------------------
await page.goto(`${B}/admin/login`);
await page.locator('input.login-username').first().fill(need('EDITOR_EMAIL'));
await page.locator('input.login-password').first().fill(need('EDITOR_PASSWORD'));
await page.locator('button[type=submit]').first().click();
const loggedIn = await page.waitForURL(/\/admin\/(?!login)/, { timeout: 30_000 }).then(() => true, () => false);
if (!check('editor can log in (DEMO_EDITOR_*)', loggedIn)) {
	await page.screenshot({ path: `${artifacts}login.png` });
	throw new Error(failures.join('\n'));
}

// --- First translation -------------------------------------------------------------------
await translateArticle('first-run', false);
const first = await snapshot();

for (const site of targets) {
	const lang = site.language.split(/[-_]/)[0].toLowerCase();
	const tag = `${site.handle} (${site.language})`;
	let fields = 0;
	let changed = 0;
	let text = '';
	for (const id of items) {
		const s = source[id];
		const t = first.bySite[site.id]?.[id];
		if (!check(`${tag}: entry #${id} has a row in this site`, !!t)) continue;
		check(`${tag}: entry #${id} enabled state unchanged`, t.enabled === before.bySite[site.id][id].enabled && t.elementEnabled === before.bySite[site.id][id].elementEnabled, `site ${t.enabled}, element ${t.elementEnabled}`);
		const sf = fieldsOf(s);
		const tf = fieldsOf(t);
		for (const [name, value] of Object.entries(sf)) {
			if (!plain(value).trim()) continue;
			fields++;
			const v = String(tf[name] ?? '');
			if (!check(`${tag}: #${id}.${name} filled`, plain(v).trim() !== '')) continue;
			// A single word can be the same in the target language, so unchanged values only count against the ratio below.
			if (v !== value) changed++;
			else console.log(`note ${tag}: #${id}.${name} unchanged ("${plain(v).trim().slice(0, 60)}")`);
			if (name !== 'slug') text += ` ${plain(v)}`;
			const left = LEFTOVERS.filter((l) => v.includes(l));
			check(`${tag}: #${id}.${name} has no placeholders left`, left.length === 0, left.join(', '));
			for (const [what, needle] of KEPT) {
				if (count(value, needle)) check(`${tag}: #${id}.${name} keeps its ${what}`, count(v, needle) === count(value, needle), `${count(v, needle)}/${count(value, needle)}`);
			}
		}
	}
	check(`${tag}: texts were changed by the translation`, fields > 0 && changed / fields >= 0.8, `${changed}/${fields} fields`);

	if (STOPWORDS[lang]) {
		const scores = Object.fromEntries(Object.keys(STOPWORDS).map((l) => [l, score(text, l)]));
		const best = Object.entries(scores).sort((a, b) => b[1] - a[1])[0][0];
		check(`${tag}: text is in the target language`, best === lang, JSON.stringify(scores));
	}
	if (/^de-ch$/i.test(site.language)) check(`${tag}: Swiss German spelling (no "ß")`, !text.includes('ß'));

	// The translated page on the front end, at its translated address.
	const row = first.bySite[site.id]?.[article.id];
	if (row?.slug) {
		const res = await fetch(`${B}/${site.handle}/articles/${row.slug}`);
		const html = decode(await res.text());
		check(`${tag}: front-end page /${site.handle}/articles/${row.slug}`, res.ok && html.includes(row.title), `HTTP ${res.status}`);
	}
}

// --- Translating again updates in place ---------------------------------------------------
await translateArticle('second-run', true);
const second = await snapshot();
check('translating again adds no entries', second.entries === first.entries, `${first.entries} → ${second.entries}`);
check('translating again updates the same site rows (no duplicates)', second.rowIds === first.rowIds);
for (const site of targets) {
	const changedAgain = items.filter((id) => second.bySite[site.id]?.[id]?.title && second.bySite[site.id][id].title !== source[id].title).length;
	check(`${site.handle} (${site.language}): still translated after the second run`, changedAgain >= Math.ceil(items.length * 0.8), `${changedAgain}/${items.length} titles`);
}

await browser.close();
await db.end();

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:\n- ${failures.join('\n- ')}`);
	process.exit(1);
}
console.log('\nAll end-to-end checks passed.');
