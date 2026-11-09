/**
 * Supertext box on the entry edit page: lists the entry's other sites, translates the
 * current site's saved version into the chosen ones and links to the results.
 */
(function () {
    const t = (message, params) => Craft.t('supertext-translation', message, params);
    const esc = (value) => Craft.escapeHtml(String(value));

    function siteUrl(handle) {
        const url = new URL(window.location.href);
        url.searchParams.set('site', handle);
        url.searchParams.delete('draftId');
        return url.toString();
    }

    async function init(box) {
        const body = box.querySelector('.supertext-body');
        const entryId = box.dataset.entryId;
        const siteId = box.dataset.siteId;
        let info;
        try {
            info = (await Craft.sendActionRequest('GET', 'supertext-translation/translate/info', { params: { entryId, siteId } })).data;
        } catch (e) {
            body.innerHTML = `<p class="error">${esc(e?.response?.data?.message ?? e)}</p>`;
            return;
        }
        if (!info.configured) {
            body.innerHTML = `<p class="warning">${esc(t('Supertext is not set up yet: an administrator needs to add the API key in the plugin settings.'))}</p>`;
            return;
        }
        const source = info.sites.find((s) => s.isSource);
        const targets = info.sites.filter((s) => !s.isSource && s.canSave);
        if (!targets.length) {
            body.innerHTML = `<p class="light">${esc(t('This entry exists in no other site you can edit.'))}</p>`;
            return;
        }

        const date = (iso) => Craft.formatDate ? Craft.formatDate(new Date(iso)) : new Date(iso).toLocaleDateString();
        body.innerHTML = `
            <p class="st-label">${esc(t('From'))}</p>
            <p class="st-source">${esc(source.name)} <code>${esc(source.language)}</code></p>
            <p class="st-label">${esc(t('Into'))}</p>
            ${targets.map((s) => `
                <div class="st-site">
                    <input type="checkbox" class="checkbox" id="st-site-${s.id}" value="${s.id}" ${s.translated ? '' : 'checked'}>
                    <label for="st-site-${s.id}">${esc(s.name)} <code>${esc(s.language)}</code></label>
                    ${s.lastTranslation
                        ? `<span class="st-chip">${esc(t('Translated with Supertext on {date}', { date: date(s.lastTranslation) }))}</span>`
                        : s.translated ? `<span class="st-chip">${esc(t('Already translated'))}</span>` : ''}
                </div>`).join('')}
            <div class="st-warning hidden">
                <input type="checkbox" class="checkbox" id="st-overwrite">
                <label for="st-overwrite"><strong>${esc(t('Overwrite existing translations'))}</strong></label>
                <p>${esc(t('Changes made to those translations are replaced by a new translation. Leave this off to translate only the sites without their own text.'))}</p>
            </div>
            <p class="st-hint">${esc(t('Supertext translates the saved version of this site. Save your changes first.'))}</p>
            <button type="button" class="btn submit st-translate">${esc(t('Translate'))}</button>
            <ul class="st-results" aria-live="polite"></ul>
            <details class="st-files">
                <summary>${esc(t('Export for translation'))}</summary>
                <p class="st-label">${esc(t('File format'))}</p>
                <div class="st-site"><input type="radio" class="radio" name="st-format" id="st-format-xliff" value="xliff" checked><label for="st-format-xliff">${esc(t('XLIFF 1.2 (for translation tools)'))}</label></div>
                <div class="st-site"><input type="radio" class="radio" name="st-format" id="st-format-html" value="html"><label for="st-format-html">${esc(t('HTML (one language per file)'))}</label></div>
                <p class="st-label">${esc(t('Into'))}</p>
                ${targets.map((s) => `
                    <div class="st-site">
                        <input type="checkbox" class="checkbox st-export-site" id="st-export-${s.id}" value="${s.id}">
                        <label for="st-export-${s.id}">${esc(s.name)} <code>${esc(s.language)}</code></label>
                    </div>`).join('')}
                <button type="button" class="btn st-export">${esc(t('Download file'))}</button>
                <p class="st-export-error error hidden" aria-live="polite"></p>
            </details>
            <details class="st-files">
                <summary>${esc(t('Import translation'))}</summary>
                <p class="st-label"><label for="st-import-file">${esc(t('Translated file (XLIFF or HTML)'))}</label></p>
                <input type="file" id="st-import-file" accept=".xlf,.xliff,.html,.htm,application/x-xliff+xml,text/html">
                <div class="st-site"><input type="checkbox" class="checkbox" id="st-import-changed"><label for="st-import-changed">${esc(t('Also import texts whose source changed since the export'))}</label></div>
                <div class="st-site"><input type="checkbox" class="checkbox" id="st-import-overwrite"><label for="st-import-overwrite">${esc(t('Overwrite existing translations'))}</label></div>
                <button type="button" class="btn st-import">${esc(t('Import translation'))}</button>
                <ul class="st-import-results" aria-live="polite"></ul>
            </details>`;

        initFiles(body, entryId, siteId, info.sites);
        const boxes = [...body.querySelectorAll('.st-site input[id^="st-site-"]')];
        const warning = body.querySelector('.st-warning');
        const overwrite = body.querySelector('#st-overwrite');
        const button = body.querySelector('.st-translate');
        const results = body.querySelector('.st-results');

        const update = () => {
            const chosen = boxes.filter((b) => b.checked).map((b) => Number(b.value));
            const replacing = targets.some((s) => s.translated && chosen.includes(s.id));
            warning.classList.toggle('hidden', !replacing);
            button.disabled = chosen.length === 0;
            button.classList.toggle('disabled', chosen.length === 0);
        };
        boxes.forEach((b) => b.addEventListener('change', update));
        update();

        button.addEventListener('click', async () => {
            const targetSiteIds = boxes.filter((b) => b.checked).map((b) => Number(b.value));
            if (!targetSiteIds.length) {
                Craft.cp.displayError(t('Choose at least one site.'));
                return;
            }
            button.classList.add('loading');
            button.disabled = true;
            button.textContent = t('Translating…');
            results.innerHTML = '';
            try {
                const response = await Craft.sendActionRequest('POST', 'supertext-translation/translate/entry', {
                    data: { entryId, sourceSiteId: source.id, targetSiteIds, overwrite: overwrite.checked },
                });
                results.innerHTML = response.data.results.map((r) => {
                    const site = info.sites.find((s) => s.id === r.siteId);
                    if (r.status === 'translated') {
                        return `<li class="ok">${esc(r.site)}: ${esc(t('translated'))} – <a href="${esc(siteUrl(site.handle))}">${esc(t('Open'))}</a></li>`;
                    }
                    if (r.status === 'skipped') {
                        return `<li class="skipped">${esc(r.site)}: ${esc(t('already translated, skipped'))}</li>`;
                    }
                    return `<li class="error">${esc(r.site)}: ${esc(r.message ?? '')}</li>`;
                }).join('');
            } catch (e) {
                results.innerHTML = `<li class="error">${esc(e?.response?.data?.message ?? e)}</li>`;
            } finally {
                button.classList.remove('loading');
                button.textContent = t('Translate');
                update();
            }
        });
    }

    /** POST to a control panel action with the CSRF token; JSON errors come back as { message }. */
    function post(action, data) {
        data.append(Craft.csrfTokenName, Craft.csrfTokenValue);
        return fetch(Craft.getActionUrl(action), {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
    }

    async function failure(response) {
        try {
            return (await response.json()).message ?? response.statusText;
        } catch (e) {
            return response.statusText;
        }
    }

    /** Export for translation / Import translation (XLIFF or HTML files). */
    function initFiles(body, entryId, siteId, sites) {
        const exportButton = body.querySelector('.st-export');
        const importButton = body.querySelector('.st-import');
        if (!exportButton || !importButton) {
            return;
        }
        const exportError = body.querySelector('.st-export-error');
        exportButton.addEventListener('click', async () => {
            const data = new FormData();
            data.append('entryId', entryId);
            data.append('siteId', siteId);
            data.append('format', body.querySelector('input[name="st-format"]:checked').value);
            body.querySelectorAll('.st-export-site:checked').forEach((b) => data.append('targetSiteIds[]', b.value));
            exportError.classList.add('hidden');
            exportButton.classList.add('loading');
            try {
                const response = await post('supertext-translation/files/export', data);
                if (!response.ok) {
                    throw new Error(await failure(response));
                }
                const name = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? 'translation.xlf';
                const url = URL.createObjectURL(await response.blob());
                const link = document.createElement('a');
                link.href = url;
                link.download = name;
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            } catch (e) {
                exportError.textContent = e.message ?? String(e);
                exportError.classList.remove('hidden');
            } finally {
                exportButton.classList.remove('loading');
            }
        });

        const list = body.querySelector('.st-import-results');
        importButton.addEventListener('click', async () => {
            const file = body.querySelector('#st-import-file').files[0];
            if (!file) {
                Craft.cp.displayError(t('Please choose a file.'));
                return;
            }
            const data = new FormData();
            data.append('file', file);
            if (body.querySelector('#st-import-changed').checked) data.append('includeChanged', '1');
            if (body.querySelector('#st-import-overwrite').checked) data.append('overwrite', '1');
            list.innerHTML = '';
            importButton.classList.add('loading');
            importButton.disabled = true;
            try {
                const response = await post('supertext-translation/files/import', data);
                if (!response.ok) {
                    throw new Error(await failure(response));
                }
                list.innerHTML = (await response.json()).results.map((r) => {
                    const site = sites.find((s) => s.id === r.siteId);
                    const head = `${esc(r.title)} → ${esc(r.site)}`;
                    if (r.error) {
                        return `<li class="error">${head}: ${esc(r.message ?? '')}</li>`;
                    }
                    const c = r.counts;
                    const lines = [t('Imported: {count}', { count: r.imported })];
                    if (c.source_changed && r.imported < c.ok + c.source_changed) lines.push(t('Skipped, source changed since the export: {count}', { count: c.source_changed }));
                    if (c.untranslated) lines.push(t('Not translated in the file: {count}', { count: c.untranslated }));
                    if (c.invalid_markup) lines.push(t('Formatting changed, not imported: {count}', { count: c.invalid_markup }));
                    if (c.missing) lines.push(t('Not in the file: {count}', { count: c.missing }));
                    const open = site && String(r.entryId) === String(entryId) ? ` – <a href="${esc(siteUrl(site.handle))}">${esc(t('Open'))}</a>` : '';
                    return `<li class="${r.imported ? 'ok' : 'skipped'}">${head}${open}<br>${lines.map(esc).join('<br>')}</li>`;
                }).join('');
            } catch (e) {
                list.innerHTML = `<li class="error">${esc(e.message ?? e)}</li>`;
            } finally {
                importButton.classList.remove('loading');
                importButton.disabled = false;
            }
        });
    }

    const start = () => document.querySelectorAll('[data-supertext]:not([data-supertext-ready])').forEach((box) => {
        box.setAttribute('data-supertext-ready', '');
        init(box);
    });
    start();
    // The edit page can re-render its sidebar (e.g. after switching sites in a slideout).
    new MutationObserver(start).observe(document.body, { childList: true, subtree: true });
})();
