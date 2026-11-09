# Developer guide — Supertext Translation for Craft CMS

How the plugin is built, how to work on it, and how it is released and deployed.

## Architecture

A Craft 5 plugin (`supertext/craft-supertext-translation`, handle `supertext-translation`, namespace `supertext\crafttranslation`).

```
Entry edit page: "Translate with Supertext" box (sidebar)          web/assets/translate/dist/translate.js
   │ GET  actions/supertext-translation/translate/info?entryId=&siteId=
   │ POST actions/supertext-translation/translate/entry  { entryId, sourceSiteId, targetSiteIds[], overwrite }
   ▼
controllers/TranslateController ── requires the plugin permission, the editor's own user
   ▼
services/Translator::translate()
   │ collectUnits(): title, slug, Plain Text / CKEditor / Redactor fields, nested Matrix entries (recursive)
   │ per target site: skip if it has its own text (unless overwrite), check canSave()
   ▼
api/SupertextClient::translateSegments()   the bundled Supertext SDK: one <div data-st-id="N"> per value,
   │   documents below 900k characters, POST file → poll status → GET translation → DELETE
   │   (HTTP through Craft::createGuzzleClient via api/CallableTransport)
   ▼
apply(): set the values on the entry (and nested entries) in the target site, save nested entries,
         then the entry (revision "Translated with Supertext from …"); remember() in supertext_translations
```

| Path | Responsibility |
| --- | --- |
| `src/Plugin.php` | Registers the permission `supertext-translation:translate`, the entry index action, the sidebar box (`Element::EVENT_DEFINE_SIDEBAR_HTML`), settings page, console controllers |
| `src/api/` | Wrapper around the Supertext SDK (`SupertextClient`, `CallableTransport`), language codes (`Languages`), Commando Center glue (`Reporting`, `ReportTransport`), exception. No Craft dependencies (HTTP through a transport callable), unit-tested on their own |
| `lib/supertext-sdk/` | Bundled copy of the Supertext PHP SDK, namespace `supertext\crafttranslation\sdk` (see *Supertext SDK*) |
| `lib/supertext-plugin-health/` | Bundled copy of Supertext Plugin Health (PHP), namespace `supertext\crafttranslation\pluginhealth` (see *Supertext Plugin Health*) |
| `src/services/Translator.php` | Field rules, "already translated" check, translation, saving |
| `src/controllers/TranslateController.php` | Control panel actions `info`, `entry`, `test-connection` (admins: the API key check) |
| `src/controllers/FilesController.php`, `src/api/FileExchange.php` | Export for translation / Import translation (XLIFF 1.2 and HTML files, see *Translation files*) |
| `src/controllers/PingController.php` | Public `actions/supertext-translation/ping` for the Commando Center |
| `src/services/Reporting.php` | Commando Center on Craft: site URL, versions, when the reporter runs, its state in the table `supertext_state` |
| `src/elements/actions/TranslateWithSupertext.php`, `src/jobs/TranslateEntryJob.php` | Bulk translation in the queue (missing sites only) |
| `src/console/controllers/TranslateController.php` | `php craft supertext-translation/translate <entryId> [--from=en] [--to=de,fr] [--overwrite]`, `php craft supertext-translation/translate/check` |
| `src/models/Settings.php`, `src/templates/_settings.twig` | Settings (project config; API key as `$SUPERTEXT_API_KEY`; per site Supertext language, form of address and *Translation review* in `languages`) |
| `src/records/TranslationRecord.php`, `src/migrations/` | Table `supertext_translations` (last Supertext translation per entry and site) and `supertext_state` (`m261014_000000_supertext_state`: the reporter's state) |
| `src/translations/{de,fr,it}/supertext-translation.php` | German, French and Italian strings (keys are the English source strings) |
| `src/helpers/Messages.php` | Shows a `SupertextException` from the API client in the user's language (by its `reason`), falling back to the client's English message |

### Field rules

`Translator::collectUnits()` and `kindOf()`:

- **Title**: if the entry type has a title field and its translation method isn't *none*.
- **Slug** (top-level entry only): if it looks like words joined by hyphens; sent as words (`a-b-c` → `a b c`), turned back into a slug with `ElementHelper::generateSlug()` in the target site's language (so `limitAutoSlugsToAscii` applies).
- **Plain Text** → escaped text, line breaks as `<br>`. **CKEditor / Redactor** (`craft\ckeditor\Field`, `craft\redactor\Field`) → HTML as is.
- Only fields where `$field->getIsTranslatable($entry)` is true.
- **Matrix** → recursively the nested entries' titles and fields (up to 5 levels). The nested entry with the same ID in the target site gets the translation, so this needs Matrix propagation that keeps nested entries in all sites (the default).
- Empty values are skipped; an empty translation never overwrites.

Each value is one `data-st-id` element, so a whole rich-text field (all its paragraphs, bold words and links) is translated in context.

**"Already translated"**: a target site has its own text when any collected value (except the slug) differs from the source site's value. Without *overwrite* such sites are skipped. In Craft a site's version starts as a copy of the source, so "same as the source" means "not translated yet".

**Saving**: values are set on the target site's elements and saved with `Elements::saveElement()` (nested entries first). Saving the entry creates a revision with notes; the site's enabled status is not changed.

## Translation files

*Export for translation* and *Import translation* in the Supertext box (SDK 0.5.0, `supertext\crafttranslation\sdk\Files\TranslationFiles`; SDK integration guide → *Translation files: XLIFF and HTML*). Nothing calls the API.

```
translate.js (Export / Import sections of the box)
   │ POST actions/supertext-translation/files/export  { entryId, siteId, targetSiteIds[], format }  → file (attachment)
   │ POST actions/supertext-translation/files/import  multipart { file, includeChanged, overwrite } → { results }
   ▼
controllers/FilesController ── plugin permission; uploads > 10 MB refused before reading, read in memory only
   ▼
services/Translator::exportFile() / importFile() ── Craft side: entries, sites, permissions, write-back
   ▼
api/FileExchange ── no Craft classes (tests/unit/FileExchangeTest.php): reference, jobs, import statuses
```

- **Job:** one entry from its source site into one target site. `plugin` = `CraftCms-Supertext-Translation`; `reference` = `entry:<entry id>:site-<source site id>:site-<target site id>`, e.g. `entry:5:site-1:site-3` (`FileExchange::reference()` / `parseReference()`); `sourceLanguage`/`targetLanguage` = `Settings::sourceCode()`/`targetCodeOrNull()` (the site's Supertext language, exact match, default), else the site language with `_` → `-` (an unmapped site doesn't block an export).
- **Segments:** exactly the API translation's units (`collectUnits()`), via `Translator::fileSegments()`, keyed `<element id>.<title|slug|field handle>` (nested Matrix entries have their own element id; slugs as words, HTML only for rich-text fields).
- **Export:** XLIFF: one job per selected site, `craft-<entry id>-<site handle>.xlf` (`craft-<entry id>.xlf` for several sites), `application/x-xliff+xml`; HTML: exactly one site, `craft-<entry id>-<site handle>.html`, `text/html; charset=utf-8`; as attachment (`Response::sendContentAsFile()`), fetched by the box and saved through a blob link. Nothing to translate → *Nothing to translate.*, no file.
- **Import:** `FileExchange::read()` (errors `invalid_file`, `missing_metadata`, `too_large`), then per job `FileExchange::import()`: `plugin` must be this plugin (`other_system`), the reference must parse (`not_found`); `Translator` loads the entry in the source site (`not_found`), the target site and the entry in it (`no_language`), checks the permission and `canSave()` (`forbidden`) and the overwrite rule of API translations (`already_translated` when the site has its own text and *Overwrite existing translations* is off), and collects the current units. `importTranslations()` compares them: `ok` is written, `source_changed` only with *Also import texts whose source changed since the export*, `untranslated`/`invalid_markup`/`missing` never. Messages: `Messages::fileError()`.
- **Write-back:** the imported texts are mapped back to the units' indexes and go through `apply()` (nested entries first, revision note *Translated with Supertext from …*, slug regenerated, empty texts never written) and `remember()`, as for API translations. Units without an imported text keep their value in the target site.

## Supertext API protocol

Shared with the WordPress plugin and every other Supertext CMS plugin:

1. `POST {base}translate/ai/file`: multipart with `file` (part `Content-Type` exactly `text/html`, no charset, or the API answers 415), `target_lang` (a Supertext target code, e.g. `de-CH` or `es`), optional `source_lang` (bare language, e.g. `en`); see *Language codes*, optional `politeness` (`more`/`less`). Returns `{file_id}`.
2. `GET …/{file_id}/status` until `done` (`error`, `limit_exceeded`, `deleted` are terminal).
3. `GET …/{file_id}/translation` returns the translated HTML.
4. `DELETE …/{file_id}` (files also expire after 24 h).

Auth header: `Authorization: Supertext-Auth-Key <key>`. The header name must be `Authorization` (`Authentication` gets 403). Supertext shows the key with the prefix, so the client strips a pasted `Supertext-Auth-Key ` and always sends exactly one. Base URLs: `https://api.supertext.com/v1/` (live), `https://api.staging.supertext.com/v1/`, `https://api.testing.supertext.com/v1/`. `GET features` is the cost-free key check (*Test connection*, `translate/check`).

User-Agent: `supertext-sdk-php … CraftCms-Supertext-Translation/<version>` (the plugin's version from Craft, as in the version reports).

**Translation review** (`Settings::$languages[<site handle>]['review']`, a lightswitch per site in the Languages table, off by default): `Translator::translate()` takes `SupertextClient::forReview($settings->review($handle))` for each target site, a second SDK client with the same key, timeouts, transport and User-Agent whose base URL is `Settings::getReviewUrl()` (setting `reviewUrl`, default `SupertextClient::REVIEW_URL`, the translation proxy of [Supertext Localization Review](https://github.com/Supertext/Localization-Review-Supertext-Translation); `SUPERTEXT_REVIEW_URL` on the server wins, also when empty). It is created once per client and only when needed; each document is uploaded, polled, downloaded and deleted by the client that submitted it (`translateDocument()` is one round trip). The proxy speaks the same API v1, forwards to the live Supertext API with the plugin's key, stores every segment per key and serves reviewers' corrections on the next upload, so nothing else changes in the plugin. An empty review URL (or the same URL as the API) means `forReview()` always returns the direct client. The key check (Commando Center, *Test connection*), the version reports and the translation files never use the review service. No migration: sites without `review` in the project config are off.

**Rate limit:** the API limits requests per second per key (HTTP 429). The client retries a 429 up to 4 times, waiting for `Retry-After` if sent, otherwise 1, 2, 4 and 8 seconds plus jitter. Target sites are translated one after the other.

## Supertext SDK

The protocol above, the retries, the language list, the API key check (`Client::checkApiKey()`, `Protocol::apiKeyFingerprint()`) and the `data-st-id` document come from the official Supertext PHP SDK ([Supertext/Supertext-API-SDK-and-Guide](https://github.com/Supertext/Supertext-API-SDK-and-Guide), 0.5.0): it provides the client, the key check and the translation files (`Files\TranslationFiles`, no API calls). The version reports are no longer in the SDK (since 0.3.0) but in *Supertext Plugin Health* (below). The plugin ships a copy in `lib/supertext-sdk/` with its namespace changed from `Supertext\Sdk` to `supertext\crafttranslation\sdk` (everything that talks to the API is in its `V1` sub-namespace, `supertext\crafttranslation\sdk\V1`, folder `src/V1/`; Composer autoloads it from the plugin's `composer.json`), so sites install nothing extra and another package that requires the SDK itself can't clash with it. `lib/supertext-sdk/COMMIT` names the SDK revision, `VERSION` its version.

- Never edit `lib/supertext-sdk/`. Fix the SDK in its repository, then run `scripts/vendor-sdk.sh [ref]` (default `main`; `SDK_DIR=/path/to/clone` uses a local clone) and the tests. The script copies `sdk/php/src` with its subfolders and rewrites every `Supertext\Sdk\…` namespace (`V1`, `Http` and `Files` included).
- `src/api/SupertextClient.php` keeps the plugin's interface (`translateDocument`, `translateSegments`, `validateApiKey`, transport callable) and turns every SDK error code into a `SupertextException` reason (`REASONS`); `helpers/Messages::of()` translates it. A new SDK error code needs a reason there, a `match` arm in `Messages` and its strings in `de`, `fr` and `it`.
- The demo installs the plugin from `demo/project/composer.lock`, which records the plugin's autoload paths: after changing `autoload` in `composer.json`, run `composer update supertext/craft-supertext-translation --no-install` in `demo/project` and commit the lock (otherwise the demo can't load the SDK classes).
- PHPStan analyses `src/` only; the SDK has its own checks in its repository.
- Segments missing from Supertext's answer are left untouched in the target site (the SDK's `translateTexts()` would fail the whole entry instead).
- The live API answers an unknown language (`xx-YY`) with HTTP 404 `INVALID_LANGUAGE_PAIR`; the SDK reports it as `invalid_language_pair` with both codes, shown to editors as "Supertext does not support this language pair (en → xx-YY)".

## Language codes

Supertext accepts exactly the codes its `GET features` lists (the SDK's `Languages`, from the live API): bare sources (`en`, `de`); targets in one exact form each, regional for German, English, French, Italian and Portuguese (`de-CH`, `en-US`, `pt-BR`), with a script for Chinese and Serbian (`zh-Hans`, `sr-Latn`), a dialect for Swiss German (`gsw-u-sd-chzh`), bare for the others (`es`, `nl`, `sl`). Anything else is HTTP 404 `INVALID_LANGUAGE_PAIR`; formal/informal for a target without it (`en-US`, `rm`, `sq`, `sr-*`, `gsw-*`) is HTTP 400 `FEATURE_ERROR` (`unsupported_feature`).

`src/api/Languages.php` (the SDK's `Languages` plus the per-site setting; no Craft dependency, `tests/unit/LanguagesTest.php`) resolves a site with the SDK's `Languages::target($siteLanguage, [$siteLanguage => $code])`:

- **Target:** the site's *Supertext language* (`languages[<handle>][code]` in the settings, or in `config/supertext-translation.php`, which Craft merges over the stored settings and so wins); else the site's language if it equals a Supertext code apart from case and `_`/`-` (`de-CH`, `fr_ch` → `fr-CH`, `es`); else the SDK's default (`de` → `de-DE`, `en` → `en-US`, `fr` → `fr-FR`, `it` → `it-IT`, `pt` → `pt-PT`, `zh`/`zh-CN` → `zh-Hans`, `zh-TW` → `zh-Hant`, `sr` → `sr-Latn`, `es-ES` → `es`); else `SupertextException` with reason `unsupported_language` (the site language as `{0}`; `en-IE`, `de-LI`, `gsw`, `sr-RS` …), which the translator reports for that site without calling Supertext. `Settings::languageCode()` returns a stored code in the list's spelling (`Languages::canonical()`); a config-file code the list doesn't know is sent as it is.
- **Source:** the same setting as the SDK's mapping: the source form of the mapped code (`de-CH` → `de`) or of the site language (`en-US` → `en`), else `unsupported_language`.
- **Settings page** (`Plugin::settingsHtml()`, `_settings.twig`): per site a `forms.selectField` with `Languages::options(Craft::$app->language)` (the SDK's `targetOptions()`, names localized with PHP intl's `Locale::getDisplayName()` where it knows the code, else the SDK's English name, sorted with `Collator`, the code in brackets), preselected with the saved code, else `Languages::suggest()` (the SDK's `suggestTarget()`: exact match, else default), else *— choose —*. *Not mapped* marks sites without a target (no mapping, no match, no default). *Form of address* is shown (and posted) only where `supportsPoliteness()` of the selected target isn't false; a small script toggles it when the select changes. A site set in the config file (`Settings::configuredLanguageCode()`) gets a disabled select with a note. `Settings::validateLanguages()` refuses codes outside `Languages::targets()` and tones other than default/more/less.
- **Migration** `m261017_000000_language_codes` (schema 1.2.0, project config, once): stored codes through `Languages::migrate()` (`de_ch` → `de-CH`, `de` → `de-DE`, `es-ES` → `es`); a code that is no Supertext language is removed with a `Craft::warning`.

`SupertextClient` sends the codes it is given; the stand-in (`tests/docs/stand-in.mjs`) accepts exactly Supertext's list and answers anything else with 404 `INVALID_LANGUAGE_PAIR`, like the real API.

## Supertext Plugin Health

[Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) (`plugin-health/php` in the Commando Center repository) sends the version reports to the Supertext Commando Center, reports the result of the SDK's API key check and builds the ping endpoint's answer (see *Commando Center* below). It has no dependencies and doesn't need the SDK: `src/api/Reporting.php` passes it the SDK's key check (`CallableKeyChecker`) and `Supertext::SDK_VERSION`. The plugin ships a copy in `lib/supertext-plugin-health/` with its namespace changed from `Supertext\PluginHealth` to `supertext\crafttranslation\pluginhealth`, autoloaded from `composer.json` like the SDK copy (and recorded in `demo/project/composer.lock`, see above). `COMMIT` names the revision, `VERSION` its version (0.1.0).

- Never edit `lib/supertext-plugin-health/`. Fix it in Commando-Center-Supertext-Translation/plugin-health, then run `scripts/vendor-plugin-health.sh <tag>` (e.g. `plugin-health-v0.1.0`; default `main`; `PLUGIN_HEALTH_DIR=/path/to/clone` uses a local clone) and the tests.
- PHPStan analyses `src/` only; Plugin Health has its own checks in its repository.

## Commando Center: version reports, key check, ping

Protocol: [Commando-Center-Supertext-Translation](https://github.com/Supertext/Commando-Center-Supertext-Translation) → `docs/DEVELOPER.md` → *Report protocol*. Supertext Plugin Health's `InstallationReporter`, `reportsEnabled()` and `pingResponse()` implement it, with the SDK's `checkApiKey()` as key checker (against the configured API, through the plugin's transport); `src/api/Reporting.php` (no Craft classes) loads the state, runs one reporter action and stores the new state, never throwing; `src/services/Reporting.php` gives it what Craft knows.

- **Setting:** `Settings::$versionReports` (*Send version information to Supertext*, on by default, lightswitch on the settings page). `SUPERTEXT_VERSION_REPORTS` (`0/false/off/no`, `1/true/on/yes`) on the server wins (`reportsEnabled()`); the page says so. Off: no reports, ping 404. `SUPERTEXT_REPORT_URL` sends reports elsewhere (CI, screenshots: the stand-in's `POST /report`).
- **Report fields:** `site_url` = the primary site's base URL (else the request's host; console without one: nothing is reported, the key check still runs); plugin `CraftCms-Supertext-Translation` with `Plugin::getVersion()` (Composer's version, i.e. the Git tag); system `Craft CMS` with `Craft::$app->getVersion()`; runtime PHP; `sdk_version` = the SDK's `Supertext::SDK_VERSION`; `callback.url` = `<site>/actions/supertext-translation/ping` (`<site>/index.php?p=actions/…` without `omitScriptNameInUrls`). HTTP goes through the plugin's Guzzle transport (`CallableTransport` for the SDK, `ReportTransport` for Plugin Health), with their timeouts (3 s per report, 10 s for the key check).
- **When:**
  - `afterSettingsSaved`: `Plugin::afterSaveSettings()`, which Craft calls from `Plugins::savePluginSettings()` (every save in the control panel, and the demo setup's). The check is shown as a notice (ok) or error flash. Applying project config on another environment doesn't call it; the next request there runs `onRequest`.
  - `onRequest`: the settings page, `translate/info` (the Supertext box), the end of every `Translator::translate()` (control panel action, queue job, console command). On web requests it runs once, after the response was sent (`Response::EVENT_AFTER_SEND`, after `fastcgi_finish_request()` where available); on console and queue right away.
  - `authenticationFailed`: a translation failed with reason `auth_failed` (HTTP 401/403); replaces `onRequest` for that request.
  - `testConnection`: *Test connection* (`translate/test-connection`, returns `{ ok, status, message, checkedAt }`).
  - `translate/check` (console): `testConnection`, then `reportCheck` if that sent nothing.
- **State:** one row (`name = 'reporter'`, Plugin Health's JSON, the same the SDK 0.2 reporter stored, so the switch sent no new `installed` report) in `supertext_state`. Load and save errors (e.g. before `craft up` created the table) are swallowed: the state is just empty then. The API key is never stored or logged; only the SDK's fingerprint is kept.
- **Ping:** `controllers/PingController` (`$allowAnonymous = true`, no CSRF, GET only) sends `pingResponse()`'s status, headers and body as they are.
- **Messages:** `helpers/Messages::keyCheck()` has the center's five texts in four languages, with the account and API key links (new tab) for `invalid` and `missing`.

## Local development

Use the demo project in `demo/project` (it requires the plugin from the repository root through a Composer path repository):

```bash
cd demo/project
composer install
# .env: CRAFT_DB_DRIVER=pgsql, CRAFT_DB_SERVER, CRAFT_DB_PORT, CRAFT_DB_DATABASE, CRAFT_DB_USER,
#       CRAFT_DB_PASSWORD, CRAFT_SECURITY_KEY, PRIMARY_SITE_URL=http://127.0.0.1:8090/, SUPERTEXT_API_KEY
php craft install/craft --email=you@example.com --username=you@example.com --password=… \
  --site-name="Supertext Craft Demo" --site-url='$PRIMARY_SITE_URL' --language=en-US
php craft plugin/install ckeditor && php craft plugin/install supertext-translation
DEMO_EDITOR_EMAIL=editor@example.com DEMO_EDITOR_PASSWORD=… php craft supertext-demo/setup
php craft serve 127.0.0.1:8090        # control panel: http://127.0.0.1:8090/admin
```

To work without a real key, run the stand-in API (`node tests/docs/stand-in.mjs`) and set `SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/`. It returns real German, French and Italian for the sample article and `[de-CH] …`-prefixed text for anything else.

## Tests

```bash
composer install
vendor/bin/phpunit
```

- `tests/unit/SupertextClientTest.php`: the SDK wrapper: protocol, codes sent as given, auth header and prefix, 429 retries, error reasons (language pair and missing tone included), the key check, clean-up, segments (plain text, HTML, blank, missing) and splitting into several documents; Translation review: a review site's upload, status, download and delete go to the review URL with the same key while other sites go directly, the key check stays direct, the User-Agent carries the version, an empty review URL (or the API's own) turns it off, `REVIEW_URL` is the setting's default and `SUPERTEXT_REVIEW_URL` wins.
- `tests/unit/LanguagesTest.php`: the per-site setting wins, exact matches, the SDK's defaults (`de` → `de-DE`, `es-ES` → `es`, `zh-CN` → `zh-Hans`), nothing else guessed (`en-IE`, `de-LI`, `gsw` → `unsupported_language`), sources from the same setting, the politeness list.
- `tests/unit/ReportingTest.php`: with a fake transport and in-memory storage: `installed`/`settings_saved` after a save (fields, 3 s timeout, never the key), nothing due on a plain request, `upgraded`, a 401/403 reported once, *Test connection*, the `check` report, nothing sent with reports off or without a site URL, the one-hour back-off, broken storage, ping answers, the environment variable.
- `tests/unit/FileExchangeTest.php`: translation files: the reference format round trip, the export uses the collector's keys and one job per site (unmapped sites with their language), HTML only for one site, nothing to export, import writes `ok` texts, `source_changed` only with the option, `untranslated`/`invalid_markup`/`missing` not written, other plugins, unknown references and the loading errors (`not_found`, `no_language`, `forbidden`, `already_translated`), files over the size limit refused.
- `tests/unit/TranslationsTest.php`: every string passed to `Craft::t('supertext-translation', …)`, `|t('supertext-translation')` or listed in `TranslateAsset::MESSAGES` is in the German, French and Italian files (and nothing else is), with the same placeholders, tags and URLs; every `t()` in the edit-page JavaScript is in `TranslateAsset::MESSAGES`.
- `tests/demo-check.sh` (CI): the demo image on PostgreSQL with the stand-in, started twice: demo accounts created once and never duplicated, no passwords in the log, the Editors group, `translate/check`, translation of the sample article into three sites (title, ASCII slug, rich text markup, nested Matrix entries), the skip on a second run, the German front-end page, and the Commando Center: the public ping (200 and 400) and the `installed` and `check` reports received by the stand-in (`SUPERTEXT_REPORT_URL`), with the plugin, Craft version and callback URL and never the key.

CI (`.github/workflows/ci.yml`) on every push and pull request: **test** (PHP 8.2, 8.3, 8.4 and 8.5: lint and PHPUnit), **phpstan** (see *Code quality and security checks*) and **demo** (builds `demo/Dockerfile`, runs `tests/demo-check.sh`).

## Demo (Railway)

The public demo is a container built from `demo/Dockerfile`: PHP 8.3 with Apache, Craft CMS 5.12 Pro with CKEditor and this plugin, sites English (`en-US`, primary), Deutsch (`de-CH`), Français (`fr-CH`) and Italiano (`it-CH`), and a sample article. It runs on Railway in the `supertext-cms-demos` project, service `CraftCMS`, region EU West (Amsterdam): <https://craftcms-production-4aa0.up.railway.app/> (control panel: `/admin`). Data lives in a `craft` database on the project's PostgreSQL service.

**Deploys:** Railway builds `main` of this repository (`railway.json` points it at `demo/Dockerfile`). If a push doesn't start a deployment, check that Railway's GitHub app has access to the repository, or redeploy the service (a redeploy also picks up the latest commit).

**What's in `demo/`:**

| Path | Purpose |
| --- | --- |
| `Dockerfile` | PHP 8.3 + Apache (document root `demo/project/web`), PHP extensions, Composer install of `demo/project` |
| `docker/entrypoint.sh` | Every start: database (via `prepare-db.php`), install on the first start, `craft up` (without a backup: `backupOnUpdate` is off in the demo's `config/general.php`, the image has no `pg_dump`), plugins, `supertext-demo/setup`, Apache on `$PORT` |
| `docker/prepare-db.php` | Turns `DATABASE_URL` into `CRAFT_DB_*` for the database `CRAFT_DEMO_DB_NAME` (default `craft`) and creates it if missing |
| `project/` | The Craft project: `composer.json`/`.lock`, `config/` (Pro edition is set by the setup; ASCII slugs), front-end `templates/` with a language switcher, and `modules/supertextdemo` with the `supertext-demo/setup` console command |
| `.env.example` | The variables below |

**Demo setup** (`php craft supertext-demo/setup`, every start, only adds what is missing): Craft Pro (user groups), the four sites, fields *Summary* (Plain Text), *Body* (CKEditor with lists), *Highlights* (Matrix with *Highlight* entries: title and text), section *Articles* (all sites), the sample article, the plugin settings (key `$SUPERTEXT_API_KEY`, URL `$SUPERTEXT_API_URL`, formal tone for German, French and Italian; the sites' languages `en-US`, `de-CH`, `fr-CH`, `it-CH` are Supertext codes, so no Supertext language needs choosing), the user group **Editors** and the demo accounts.

**No volume:** Railway's volume limit for the project is reached, and the demo needs none: content and project config are in PostgreSQL (Craft writes `config/project/*.yaml` from the database on start). Uploaded files would disappear with the next deploy.

**Service variables:**

| Variable | |
| --- | --- |
| `DATABASE_URL` | `${{Postgres.DATABASE_URL}}`; the demo database (`CRAFT_DEMO_DB_NAME`, default `craft`) is created on that server if missing |
| `CRAFT_SECURITY_KEY` | Random secret |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Administrator (the e-mail address is also the username) |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor for automated tests and screenshots: member of **Editors** (control panel, every site, articles incl. other authors' entries, *Translate entries with Supertext*). Craft has no built-in editor role, so the demo creates this group. |
| `SUPERTEXT_API_KEY` | Supertext key (the plugin setting references it) |
| `SUPERTEXT_API_URL` | Optional, e.g. a stand-in API (to try the review service, switch on *Translation review* for a site instead) |
| `SUPERTEXT_REVIEW_URL` | Optional: another review service than Supertext's (wins over *Review service address*; empty turns review off) |
| `SUPERTEXT_VERSION_REPORTS` | Optional; `0` turns the version reports to Supertext off (default on: the demo reports like any installation) |
| `PORT` | Port Apache listens on (Railway sets it) |

Railway also sets `RAILWAY_PUBLIC_DOMAIN`; the entrypoint makes `PRIMARY_SITE_URL` from it (the sites are `/`, `/de/`, `/fr/`, `/it/`).

**Demo accounts:** on every start the setup creates the `DEMO_ADMIN` and `DEMO_EDITOR` accounts if no account with that e-mail address or username exists. Existing accounts are never changed; change passwords in the control panel. Craft requires a valid e-mail address and a password of at least 6 characters; otherwise the account is skipped with a warning naming the variable and Craft's reason, and the demo still starts. Passwords are never logged.

**No installer screen:** Craft's web installer only appears on an empty database. The entrypoint installs Craft on the first start with `DEMO_ADMIN_*` as the first admin, or, if those aren't set (or Craft rejects them), with a throwaway `installer-…@example.com` admin whose random password is never stored or shown. The setup deletes that throwaway account as soon as a real active admin exists.

**Craft Pro:** the demo runs Craft Pro (user groups for the editor). Craft shows a licensing notice for Pro on a public domain without a license.

**Run it locally:**

```bash
docker build -f demo/Dockerfile -t supertext-craft-demo .
docker run --rm -p 8080:8080 \
  -e DATABASE_URL=postgresql://user:pass@host.docker.internal:5432/postgres -e CRAFT_SECURITY_KEY=dev \
  -e PRIMARY_SITE_URL=http://localhost:8080/ \
  -e DEMO_ADMIN_EMAIL=you@example.com -e DEMO_ADMIN_PASSWORD='choose-one' \
  -e SUPERTEXT_API_KEY=… supertext-craft-demo
# http://localhost:8080/admin
```

## Docs screenshots

The images in `docs/images/` are generated by `tests/docs/screenshots.mjs` (Playwright) from a freshly set-up demo (no translations yet) whose plugin talks to `tests/docs/stand-in.mjs`. The stand-in returns German, French and Italian for the sample article (`samples.json`, real Supertext output). Regenerate them whenever a screen they show changes:

```bash
cd tests/docs && npm install && npx playwright install chromium
npm run stand-in &   # also the Commando Center: start the demo with SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report
# a fresh demo with SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/ and DEMO_* set, served on :8090
BASE_URL=http://127.0.0.1:8090 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… \
  DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run screenshots
```

`06-settings.png` and `07-settings-languages.png` still show the settings page before *Review service address* and the *Translation review* column; regenerate them with the script on the next run against a demo.

The script shows the live API address instead of the stand-in's on the settings page, and the public demo's address (`SITE_URL`) instead of the local one under *Sites*.

## Demo movie

`docs/images/demo.gif` (shown at the top of the README) is recorded by `tests/docs/movie.mjs` with Supertext's movie kit (`tests/docs/movie/`: Playwright screencast → ffmpeg; intro and outro cards, captions, cursor; Inter font under the SIL Open Font License in `movie/assets/fonts/`). Like the screenshots, it needs a **fresh** demo (no translations yet) pointed at the stand-in API, `ffmpeg` and, for the voice, Piper:

```bash
pip install piper-tts                       # the voice; without it (or with MOVIE_VOICE=0) the movie is silent
cd tests/docs && npm install && npx playwright install chromium
npm run stand-in &                          # http://127.0.0.1:8765/v1/
# a fresh demo with SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/, SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report,
# SUPERTEXT_API_KEY=st-demo-0000-0000-0000 and DEMO_* set, served on :8090
BASE_URL=http://127.0.0.1:8090 DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run movie
```

About 48 seconds, 1280×720, an English voice and English captions: the demo editor opens the sample article in English, keeps *Deutsch* and *Français* in the *Translate with Supertext* box (unticks *Italiano*), clicks *Translate* (the waiting time is cut) and opens the German version. The script skips Craft's autosave and "entry has been updated" checks in the recorded browser, so no draft appears. It writes the GIF (800 px, 12 fps, keep it under 3 MB) to `docs/images/demo.gif` and the MP4 to `tests/docs/movie/out/CraftCms-Supertext-Translation.mp4` (git-ignored; `MOVIE_OUT` changes the path). The MP4 is not committed; the GIF is silent. The voice model (63 MB, pinned revision, checked by SHA-256) is downloaded once into `tests/docs/movie/voice-cache/` (git-ignored, never committed); the time spent generating speech is cut from the movie. Re-record it in the same commit when the Supertext box changes.

**Install and configuration movie** (`tests/docs/movie-install.mjs`, `npm run movie:install`): the same kit, voice and look, for administrators. Same setup as above (a made-up API key: the stand-in accepts any key), but signed in as the demo **administrator**:

```bash
BASE_URL=http://127.0.0.1:8090 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… npm run movie:install
```

About 91 seconds: a terminal (`kit.terminal()`) with the commands from `docs/INSTALLATION.md` (`composer config repositories.supertext vcs …`, `composer require`, the key in `.env` with the fake key `st-demo-0000-0000-0000`, `php craft plugin/install supertext-translation`; output shortened), where the key comes from (supertext.com → Integrations → API; create an account at supertext.com), then the control panel: *Settings → Plugins → Supertext Translation → Settings*, the **API key** field with the environment variable reference `$SUPERTEXT_API_KEY`, the **Languages** table (*Deutsch*: *German (Switzerland) (de-CH)*, *Translation review* left off), *Save* with the API key check's notice (*the connection to Supertext works*), and the *Translate with Supertext* box on an entry. The settings page shows the live API and the public demo's address instead of the local ones (*Custom API address*, which points the demo at the stand-in, is hidden). No GIF; the MP4 goes to `tests/docs/movie/out/CraftCms-Supertext-Translation-install.mp4` (git-ignored, not committed; `MOVIE_OUT` changes the path). Re-record it when the installation steps, the settings page or the key check change.

Voice: Piper voice “alba” (en_GB), trained on a dataset from the University of Edinburgh's Centre for Speech Technology Research, CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/)

## Code quality and security checks

- **Checks** (`.github/workflows/checks.yml`): on every push and pull request, [actionlint](https://github.com/rhysd/actionlint) and [zizmor](https://docs.zizmor.sh) lint the workflows. On pull requests, dependency review fails a PR that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current); checkouts don't keep credentials, and workflows get `contents: read` unless a job needs more (the release job: `contents: write`).
- **Links** (`.github/workflows/links.yml`): [lychee](https://lychee.cli.rs) checks the links in all Markdown files weekly and whenever docs change on `main`. Broken links open or update the issue "Broken links in the docs" (a docs push that breaks links also fails). Links that can't work from CI go in `.lycheeignore` (one regex per line).
- **PHPStan** (job `phpstan` in `ci.yml`, config `phpstan.neon`): level 5 on the plugin's own code (`src/`), not the tests or `demo/`. [craftcms/phpstan](https://github.com/craftcms/phpstan) (a dev dependency) tells PHPStan about Craft and Yii. Locally: `composer install`, then `composer phpstan` (or `vendor/bin/phpstan analyse`). Existing findings that aren't simple to fix are listed in `phpstan-baseline.neon` (regenerate with `vendor/bin/phpstan analyse --generate-baseline` after fixing one). New code must not add findings.
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as PR comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.
- `.github/workflows/support-triage.yml`: support requests from the Supertext integrations website arrive as issues labelled `support` (contact details stay in the Supertext CMS). Claude classifies each one: answers questions (marked `<!-- supertext:reply -->`, emailed to the sender by the CMS) and closes them, fixes bugs as pull requests for review (never merged automatically), labels feature requests `feature-request`. Only people with triage rights can add labels, so outsiders can't start it. Needs the organisation secret `ANTHROPIC_API_KEY`; the workflow is the same in every Supertext repo.

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot PRs and the "Broken links in the docs" issue.

## Dependency updates

Dependabot opens weekly pull requests (minor and patch updates grouped). Major updates the plugin can't take are ignored in `.github/dependabot.yml`:

- `phpunit/phpunit` >=12: PHPUnit 12 and later need PHP 8.3+; the plugin supports PHP 8.2 (CI tests 8.2).

Remove a rule when the plugin's minimum version (PHP, CMS) moves up far enough.

## Releasing

Releases are published by `.github/workflows/release.yml` when the version is officially bumped; nobody tags or creates releases by hand.

1. Move the *Unreleased* entries in `CHANGELOG.md` under a new `## X.Y.Z — YYYY-MM-DD` section, and keep an empty *Unreleased* above it.
2. There is no version field to change: Composer takes the version from the Git tag the workflow creates.
3. Push to `main`. The workflow tags `vX.Y.Z` and creates the GitHub release with the CHANGELOG section as notes (0.x versions as pre-releases). A push that adds no new version does nothing, and a version that is already released is skipped. After fixing a failed run, start it again with *Run workflow* on the *Release* workflow.

Keep the CHANGELOG format: Craft's Plugin Store reads it.
Planned: submit the package to Packagist and the Craft Plugin Store.
## Conventions

- PSR-12, PHP 8.2, strict types in new code; keep `src/api/` free of Craft classes.
- User-visible strings through `Craft::t('supertext-translation', …)` (or `|t('supertext-translation')` in Twig) with German, French and Italian in `src/translations/{de,fr,it}/supertext-translation.php`; new or changed strings need all three in the same commit. Strings used by the JavaScript are listed in `TranslateAsset::MESSAGES`. Formal address (Sie, vous, Lei) and Craft's own terms (*Eintrag/Website*, *entrée/site*, *articolo/sito*).
- The API client (`src/api/`, no Craft classes, built on the bundled SDK) throws `SupertextException` with an English message and a `reason` (e.g. `limit_exceeded`); `helpers/Messages::of()` maps the reason to a translated string. A new reason needs a `match` arm there and its strings.
- Keep the three docs in `docs/` current with every change (see `CLAUDE.md`).

## Known limitations / roadmap

- Translation runs inside the editor's request (one site after the other, up to *Timeout* each); the index action uses the queue.
- Supported field types: Plain Text, CKEditor, Redactor, Matrix. Not yet: Table cells, Link field labels, SEO plugin fields, entries nested inside CKEditor fields, categories, global sets and assets (alt text).
- Matrix fields that keep separate nested entries per site ("Only save entries to the site they were created in") are not translated.
- Re-translating overwrites manual changes in the translated fields (with *Overwrite existing translations*). A translation memory and reviewers' corrections come from the review service when a site has *Translation review* on; corrections reach Craft only with the next translation (no push).
- Not in the Plugin Store / on Packagist yet.
- The language list is the SDK's (October 2026); a language Supertext adds later can be used before the SDK knows it only if the site already stores its code. A future version could read `GET features` live.
- Human (professional) translation orders are not supported yet (the WordPress plugin has them).
