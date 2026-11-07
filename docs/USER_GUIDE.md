# User guide — Supertext Translation for Craft CMS

For editors who translate entries in the Craft control panel. Your administrator has installed the plugin and set up a site for each language (see the [installation guide](INSTALLATION.md)).

In Craft, every language is a **site**, and an entry has a version in each site. Supertext fills the other sites' versions with a translation of the site you're in.

The Supertext box and its messages appear in your control panel language (English, German, French or Italian).

## Translate an entry

1. Open the entry in the language you translate **from**, e.g. *English* (site menu at the top left).
2. **Save** your changes first. Supertext translates the saved version.
3. In the sidebar, the **Translate with Supertext** box shows:
   - **From**: the site you're in.
   - **Into**: the entry's other sites you may edit. Sites that don't have their own text yet are ticked.
4. Click **Translate**.

![The Translate with Supertext box: from English into Deutsch, Français and Italiano (all ticked), and the Translate button](images/01-translate-box.png)

Each language takes a few seconds. The box then lists the result per site, with a link to open it:

![The box after translating: Deutsch, Français and Italiano translated, each with an Open link](images/02-translated.png)

## Review the translation

Click **Open** (or switch the site at the top left). The translation is saved in that site's version of the entry, exactly as if you had typed it: title, slug, texts, rich text with its headings, bold text, links and lists, and the entries inside Matrix fields.

![The German version of the sample article: title, summary, formatted body and the highlights in German](images/03-german-entry.png)

Change what you like and **Save**. The site's status (enabled or disabled) doesn't change: if a site's version was live before, the translation is live right away. To review first, disable the entry for that site, translate, review, then enable it.

**Translation review:** if your administrator turned on *Translation review* for a site, reviewers (your colleagues or Supertext linguists) check and correct the translations on the website. Their corrections come into Craft the next time you translate the entry into that site: after a review, translate it again with *Overwrite existing translations*. Texts reviewed once keep their corrected wording in later translations.

Every translation is saved as a new **revision** with the note *Translated with Supertext from English*. To undo a translation, open the revision menu (*Current*, next to the entry's name at the top), choose the earlier revision and click *Revert content from this revision*.

## Translate again or update a translation

Sites that already have their own text are not ticked, and show **Already translated** or **Translated with Supertext on** *date*. If you tick one, the box asks whether to overwrite it:

![The overwrite warning: Deutsch is ticked although it was translated with Supertext; the "Overwrite existing translations" checkbox and its explanation](images/04-overwrite-warning.png)

- Leave **Overwrite existing translations** off: those sites are skipped (*already translated, skipped*); only sites without their own text are translated. Your changes are safe.
- Turn it on: the translatable fields of those sites are replaced with a new translation of the current source. **Changes made in those sites are lost** (the previous version stays in the revisions). Use this after the source text has changed.

"Its own text" means: at least one translatable field differs from the source site.

## Translate with a file (XLIFF or HTML)

Instead of translating with Supertext's API, you can download an entry's texts as a file, have them translated elsewhere (a translator, a translation tool such as Trados, memoQ or Phrase, or Supertext's web app) and import the translated file again. The translations are saved exactly like a translation with Supertext: in the target site's version of the entry, as a new revision.

**Export for translation:** open the entry in the site you translate **from** (save your changes first). In the Supertext box, open **Export for translation**.

1. Choose the **File format**:
   - **XLIFF 1.2 (for translation tools)**: the better choice for translators and translation tools; bold text, links and other formatting appear as protected tags. One file can hold several sites.
   - **HTML (one language per file)**: for Supertext's web app or anyone who translates HTML. Tick exactly one site.
2. Tick the sites under **Into** and click **Download file**. The file is called e.g. `craft-12-fr.xlf` (entry ID and site handle; `craft-12.xlf` for several sites).

The file holds the same texts a translation with Supertext would send: title, slug (as words), Plain Text and rich text fields, and the entries inside Matrix fields. An entry without texts gives *Nothing to translate.*

**Send it for translation.** Translators must keep the file's structure: in an HTML file the head (the `supertext:` meta tags) and the `data-st-id` attributes must stay as they are. An HTML file can't mark a text as untranslated: imported unchanged, it writes the source text into the target site.

**Import translation:** in the Supertext box of any entry (the file says which entry and sites it belongs to), open **Import translation**, choose the **Translated file (XLIFF or HTML)** and click **Import translation**. Files up to 10 MB.

- **Also import texts whose source changed since the export** (off by default): texts edited in the source site after the export are skipped unless you tick this.
- **Overwrite existing translations** (off by default): like translating with Supertext, a site that already has its own text is skipped (*This site already has its own text …*) unless you tick this.

The summary shows, per entry and site:

| Line | Meaning |
| --- | --- |
| *Imported: n* | Texts saved in the target site. |
| *Skipped, source changed since the export: n* | The source text was edited after the export; tick the option above to import them anyway, or export again. |
| *Not translated in the file: n* | The file has no translation for these texts (XLIFF). |
| *Formatting changed, not imported: n* | The translation broke or added formatting (tags); fix it in the file or translate these texts again. |
| *Not in the file: n* | Texts added since the export; translate them with Supertext or export again. |

Texts that weren't imported keep their current value in the target site. Errors: *This file isn't a valid XLIFF or HTML translation file*, *This file wasn't exported by the Supertext plugin, or its header was removed*, *The file is too large (at most 10 MB)*, *This file belongs to another system (…)* (exported by another Supertext plugin), *The entry this file was exported from no longer exists*, *The site has no language …* (the site was deleted or the entry isn't available in it any more), *You are not allowed to edit the entry in this site.*

## Translate many entries at once

In the entry list, select entries, open the **actions menu** (gear icon) and choose **Translate with Supertext**. Each selected entry is translated from the site the list shows into all its other sites, in the background. Sites that already have their own text are skipped; to overwrite them, use the box on the entry page.

![The entry index with an entry selected and the actions menu open](images/05-index-action.png)

Progress and errors appear in Craft's queue indicator (bottom left); failed jobs are listed under *Utilities → Queue Manager*.

## What is translated

| Content | What happens |
| --- | --- |
| Title | Translated (unless the entry type's title is *Not translatable*) |
| Slug | Translated as words and turned back into a slug, e.g. `swiss-chocolate-shipped-worldwide` → `schweizer-schokolade-weltweit-versandt`. A one-word slug stays as it is. |
| Plain Text fields | Translated, line breaks kept |
| CKEditor (and Redactor) fields | Translated as HTML: headings, bold, italic, links and lists stay in place, link addresses are kept |
| Matrix fields | The title and the fields above of each nested entry are translated |
| Fields that are *Not translatable* | Not touched (they are the same in every site) |
| Other field types (numbers, dates, assets, relations, dropdowns, tables, links, …) | Not translated |

Empty fields are skipped. Entries nested inside CKEditor fields are not translated yet.

## Messages

| Message | Meaning |
| --- | --- |
| *Supertext is not set up yet: …* | No API key. Ask your administrator. |
| *already translated, skipped* | The site has its own text and *Overwrite existing translations* was off. |
| *You are not allowed to edit the entry in this site.* | Your user group may not edit that site or the section. |
| *The entry is not available in this site.* | The entry isn't enabled in that site. |
| *Authentication failed. Please check the Supertext API key.* | The API key is wrong. Ask your administrator. |
| *Your Supertext translation limit is exceeded.* | Your organisation's Supertext limit is used up. |
| *Supertext does not support this language pair (en → …).* | Supertext can't translate into this site's language, or its Supertext language is set wrong. Tell your administrator. |
| *The language … has no Supertext language yet.* | The site's language has no obvious Supertext language (e.g. `en-IE` or `gsw`) and your administrator hasn't chosen one yet. Ask them to choose it under *Settings → Plugins → Supertext Translation → Languages*; the other sites are translated. |
| *Supertext offers no formal or informal tone for this language.* | The form of address set for this site isn't available in its language (e.g. English). Ask your administrator to set it to *Default*. |
| *Too many requests to Supertext. Please try again shortly.* | Supertext was busy; try again in a moment. |
| *Timed out waiting for the Supertext translation.* / *The Supertext service is currently unavailable.* | Supertext took too long or is unavailable. Try again later. |
| *The translation could not be saved: …* | Craft refused to save the translated version (e.g. a required field is empty in that site). The reason follows. |

Errors are shown per site: if one site fails, the others are still translated and saved.

## Support

Questions, problems or ideas: use the [Supertext support form](https://integrations.supertext.com/en/support?platform=craft-cms). Mention the version of Craft CMS and of the plugin, what you did and what you expected; an error message helps a lot. You get an answer by email, in your language.
