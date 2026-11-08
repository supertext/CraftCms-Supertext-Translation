# Release Notes for Supertext Translation

## Unreleased

### Fixed

- Demo: no database backup before updates (the image has no `pg_dump`, so `craft up` failed after a Craft update), and a failing `craft up` no longer prints lines containing the database password to the log.

### Added
- **Translation review** on/off per site (*Settings → Plugins → Supertext Translation → Languages*, switch *Translation review*, off by default): translations into a site with it on go through Supertext's review service (Supertext Localization Review), where customers and Supertext linguists correct them on the website with a Chrome extension; corrections come back into Craft with the next translation, and texts translated before keep their (reviewed) wording. Same API key, billing, languages and messages; other sites keep going directly to Supertext. New setting *Review service address* (`reviewUrl`, defaults to Supertext's review service; empty turns review off everywhere; `SUPERTEXT_REVIEW_URL` on the server wins). `config/supertext-translation.php` can set `languages.<handle>.review`. In English, German, French and Italian.
- Requests to Supertext name the plugin's version in the User-Agent (`CraftCms-Supertext-Translation/<version>`).
- Export for translation (XLIFF 1.2 or HTML) and import of translated files; Supertext SDK 0.5.0. In the Supertext box on the entry page: *Export for translation* (file format, sites, *Download file*) downloads the entry's texts (the same as a Supertext translation: title, slug, text and rich-text fields, Matrix entries); *Import translation* uploads the translated file (at most 10 MB, read in memory only) and saves it like a Supertext translation (new revision; a site with its own text only with *Overwrite existing translations*). Texts whose source changed since the export are skipped unless *Also import texts whose source changed since the export* is ticked; the summary lists imported, skipped, untranslated, broken-formatting and missing texts per entry and site. Same permission as translating, plus saving the entry in the target site. In English, German, French and Italian.
- French and Italian control panel texts, and German where it was missing: messages from Supertext (limit reached, authentication failed, timeouts and others) and the environment names in the settings now appear in the user's control panel language too. The "no API key" and "authentication failed" messages now also link to Supertext account sign-up.
- Automatic API key check: after every save of the plugin settings (shown as a notice), on *Test connection*, when the key in the environment changed and when Supertext rejects the key during a translation. The settings page shows the latest result with its time (*the connection works*, *rejected*, *no key*, *Supertext can't be reached*, *error*), with the links to create a Supertext account and generate a key, in English, German, French and Italian. `php craft supertext-translation/translate/check` shows it too.
- *Send version information to Supertext* (on by default; `SUPERTEXT_VERSION_REPORTS=0` on the server turns it off): when the settings are saved and after an update, the plugin sends the site URL, the plugin, Craft CMS, PHP and SDK versions, whether a key is set, the key check result and the ping address to Supertext, so Supertext support can see which version you run. No content, no API key and no personal data.
- Public ping endpoint `actions/supertext-translation/ping` that only echoes Supertext's challenge, so Supertext can check the site is reachable (404 with version reports off).
- New table `supertext_state` for the key check and the version reports (run `php craft up` after updating).
- Support: the Supertext support form is linked in the README, the user guide and GitHub's "New issue" page.

### Changed
- Tested on PHP 8.5 as well (CI runs PHP 8.2–8.5); the demo runs Craft CMS 5.12.0.1 and CKEditor 5.9.0.
- All Supertext API calls now go through the official Supertext PHP SDK (0.2.1), bundled with the plugin (nothing extra to install).
- Version reports, the API key check result and the reachability ping now use [Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) 0.1.0 (bundled in `lib/supertext-plugin-health`); Supertext SDK updated to 0.3.0 (now 0.4.0). No change for sites: settings and stored state stay as they are.
- **Language mapping with defaults** (Supertext SDK 0.4.0): each site's *Supertext language* is a select of Supertext's languages, with the names in your control panel language and the code (e.g. *Deutsch (Schweiz) (de-CH)*). It preselects the site's language where Supertext offers it, else its usual variant (`de` → German (Germany) `de-DE`, `en` → `en-US`, `zh-CN` → `zh-Hans`, `es-ES` → `es`), and a site nobody mapped is translated with that default instead of failing. Only languages without an obvious default (`en-IE`, `de-LI`, `gsw`, …) show *— choose —*, are marked *Not mapped* and get *The language … has no Supertext language yet*, which now names *Settings → Plugins → Supertext Translation → Languages*. Saving checks the codes against Supertext's list. *Form of address* only appears for languages that offer it. `config/supertext-translation.php` (`languages`) overrides a site's choice; its select is then disabled with a note. `php craft up` converts Supertext languages saved by older versions to the list's spelling (`de_ch` → `de-CH`, `de` → `de-DE`) and removes values that are no Supertext language (logged as a warning).
- **Languages are no longer guessed.** Each site's *Supertext language* is chosen from Supertext's own language list in the plugin settings; sites whose language is a Supertext code (`de-CH`, `fr-CH`, `es`, `zh-Hans`, apart from case and `_`/`-`) are pre-filled. Every other site needs a choice: a site in `de` needs German (Germany), (Switzerland) or (Austria). Sites without one are marked *Not mapped* and get the message *The language … has no Supertext language yet* (nothing is sent for them; the other sites are translated). The source language is sent without region, from the same setting. Older default regions were wrong for most languages (Supertext wants `es`, `sl`, `nl` without region, `zh-Hans` instead of `zh-CN`).
- *Form of address* warns for languages where Supertext offers no formal/informal choice (English, Swiss German, Romansh, Albanian, Serbian), and such a translation gets its own message.
- *Test connection* shows the API key check's result instead of "Connected".
- An unsupported language pair now has its own message, naming the codes ("Supertext does not support this language pair (en → xx-YY)"), in English, German, French and Italian.

## 0.1.0 — 2026-10-07

### Added
- First version for Craft CMS 5.
- *Translate with Supertext* box on the entry edit page: translate the current site's version into the entry's other sites, with "Already translated" / "Translated with Supertext on …" per site and an explicit *Overwrite existing translations* option.
- Translates titles, slugs (as words, then slugified), Plain Text, CKEditor and Redactor fields and the nested entries of Matrix fields; only fields that are translatable in Craft.
- Saves each translation as a new revision ("Translated with Supertext from …").
- Entry index action *Translate with Supertext* (queue; sites without their own text only).
- Permission *Translate entries with Supertext*; the editor's site and section permissions are checked.
- Settings: API key (environment variable reference, with or without the `Supertext-Auth-Key` prefix; the field links to the Supertext signup and the API key page, *supertext.com → Integrations → API*, Admin role required), Live/Staging/Testing API or a custom address, Supertext language and form of address per site, timeout, *Test connection*.
- Console commands `supertext-translation/translate` and `supertext-translation/translate/check`.
- Retries when the Supertext API answers HTTP 429 (rate limit).
- English and German control panel texts.
- Demo for Railway (`demo/`) with demo accounts, four sites and a sample article created on every start.
