<?php

namespace modules\supertextdemo\console;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\assets\AltField;
use craft\fieldlayoutelements\assets\AssetTitleField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fieldlayoutelements\TitleField;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\fs\Local;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\models\UserGroup;
use craft\models\Volume;
use yii\console\ExitCode;

/**
 * Demo setup, run on every start (idempotent; only adds what is missing, never changes
 * existing accounts):
 *
 * - Craft Pro (user groups), sites English (en-US, primary), Deutsch (de-CH), Français (fr-CH), Italiano (it-CH)
 * - fields Summary (plain text), Body (CKEditor), Highlights (Matrix with "Highlight" entries)
 * - section "Articles" with one English sample article
 * - section "Pages" with the page "About us": Content (CKEditor with a nested "Callout" entry),
 *   Facts (Table) and Call to action (Link)
 * - category group "Topics" with one category, global set "Footer" (Tagline, Contact link), volume
 *   "Images" with one image (title and alternative text translatable per site)
 * - user group "Editors" (all sites, all of the above, Supertext) and the DEMO_ADMIN_* / DEMO_EDITOR_* accounts
 * - plugin settings (key from $SUPERTEXT_API_KEY, formal tone)
 */
class SetupController extends Controller
{
    private const SITES = [
        ['handle' => 'de', 'name' => 'Deutsch', 'language' => 'de-CH', 'path' => 'de'],
        ['handle' => 'fr', 'name' => 'Français', 'language' => 'fr-CH', 'path' => 'fr'],
        ['handle' => 'it', 'name' => 'Italiano', 'language' => 'it-CH', 'path' => 'it'],
    ];

    public function actionIndex(): int
    {
        if (Craft::$app->edition !== CmsEdition::Pro) {
            Craft::$app->setEdition(CmsEdition::Pro);
            $this->log('Craft Pro edition enabled (user groups).');
        }

        $sites = $this->sites();
        $fields = $this->fields();
        $section = $this->section($fields, $sites);
        $this->sampleEntry($section);
        $this->pluginSettings();
        $group = $this->editorsGroup($section, $sites);

        // Added after the first demos: created where they are missing; the editors get access to each.
        $pages = $this->pagesSection($sites);
        $this->samplePage($pages);
        $topics = $this->topics($sites);
        $footer = $this->footer();
        $images = $this->images();
        $this->grant($group, [
            ...array_map(fn(string $action) => "{$action}:{$pages->uid}", ['viewEntries', 'createEntries', 'saveEntries', 'viewPeerEntries', 'savePeerEntries', 'viewPeerEntryDrafts', 'savePeerEntryDrafts']),
            "viewCategories:{$topics->uid}", "saveCategories:{$topics->uid}",
            "editGlobalSet:{$footer->uid}",
            "viewAssets:{$images->uid}", "saveAssets:{$images->uid}", "viewPeerAssets:{$images->uid}", "savePeerAssets:{$images->uid}",
        ]);

        $this->account('DEMO_ADMIN', null);
        $this->account('DEMO_EDITOR', $group);
        $this->removeInstaller();

        return ExitCode::OK;
    }

    /** @return Site[] all sites */
    private function sites(): array
    {
        $service = Craft::$app->getSites();
        $primary = $service->getPrimarySite();
        if ($primary->handle !== 'en') {
            $primary->handle = 'en';
            $primary->setName('English');
            $primary->language = 'en-US';
            $service->saveSite($primary);
            $this->log('Primary site renamed to English (en-US).');
        }
        foreach (self::SITES as $config) {
            if ($service->getSiteByHandle($config['handle'], false)) {
                continue;
            }
            $site = new Site([
                'groupId' => $primary->groupId,
                'handle' => $config['handle'],
                'language' => $config['language'],
                'hasUrls' => true,
                'baseUrl' => '${PRIMARY_SITE_URL}' . $config['path'] . '/',
                'enabled' => true,
            ]);
            $site->setName($config['name']);
            if (!$service->saveSite($site)) {
                throw new \RuntimeException('Could not save site ' . $config['handle'] . ': ' . implode(' ', $site->getFirstErrors()));
            }
            $this->log("Site {$config['name']} ({$config['language']}) added.");
        }

        return $service->getAllSites(true);
    }

    /** @return array<string, \craft\base\FieldInterface> */
    private function fields(): array
    {
        $service = Craft::$app->getFields();
        $make = function (string $handle, callable $create) use ($service) {
            $field = $service->getFieldByHandle($handle);
            if (!$field) {
                $field = $create();
                if (!$service->saveField($field)) {
                    throw new \RuntimeException("Could not save field {$handle}: " . implode(' ', $field->getFirstErrors()));
                }
                $this->log("Field {$handle} added.");
            }

            return $field;
        };

        $summary = $make('summary', fn() => new PlainText(['name' => 'Summary', 'handle' => 'summary', 'multiline' => true, 'initialRows' => 3, 'translationMethod' => 'site']));
        $text = $make('text', fn() => new PlainText(['name' => 'Text', 'handle' => 'text', 'multiline' => true, 'initialRows' => 2, 'translationMethod' => 'site']));
        $body = $make('body', fn() => new \craft\ckeditor\Field(['name' => 'Body', 'handle' => 'body', 'translationMethod' => 'site', 'toolbar' => ['heading', '|', 'bold', 'italic', 'link', '|', 'bulletedList', 'numberedList']]));

        $entries = Craft::$app->getEntries();
        $highlightType = $entries->getEntryTypeByHandle('highlight');
        if (!$highlightType) {
            $highlightType = new EntryType(['name' => 'Highlight', 'handle' => 'highlight', 'icon' => 'star', 'titleTranslationMethod' => 'site']);
            $highlightType->setFieldLayout($this->layout([$text]));
            $this->save($entries->saveEntryType($highlightType), $highlightType, 'entry type highlight');
        }
        $highlights = $make('highlights', fn() => new Matrix([
            'name' => 'Highlights',
            'handle' => 'highlights',
            'entryTypes' => [$highlightType],
            'propagationMethod' => PropagationMethod::All,
            'viewMode' => Matrix::VIEW_MODE_BLOCKS,
            'createButtonLabel' => 'Add a highlight',
        ]));

        return ['summary' => $summary, 'body' => $body, 'highlights' => $highlights];
    }

    /** @param Site[] $sites */
    private function section(array $fields, array $sites): Section
    {
        $entries = Craft::$app->getEntries();
        $section = $entries->getSectionByHandle('articles');
        if ($section) {
            return $section;
        }

        $type = $entries->getEntryTypeByHandle('article');
        if (!$type) {
            $type = new EntryType(['name' => 'Article', 'handle' => 'article', 'icon' => 'newspaper', 'titleTranslationMethod' => 'site']);
            $type->setFieldLayout($this->layout(array_values($fields)));
            $this->save($entries->saveEntryType($type), $type, 'entry type article');
        }

        $section = new Section([
            'name' => 'Articles',
            'handle' => 'articles',
            'type' => Section::TYPE_CHANNEL,
            'propagationMethod' => PropagationMethod::All,
            'enableVersioning' => true,
        ]);
        $section->setEntryTypes([$type]);
        $section->setSiteSettings(array_map(fn(Site $site) => new Section_SiteSettings([
            'siteId' => $site->id,
            'enabledByDefault' => true,
            'hasUrls' => true,
            'uriFormat' => 'articles/{slug}',
            'template' => 'articles/_entry',
        ]), $sites));
        $this->save($entries->saveSection($section), $section, 'section articles');
        $this->log('Section Articles added.');

        return $section;
    }

    private function sampleEntry(Section $section): void
    {
        if (Entry::find()->sectionId($section->id)->status(null)->exists()) {
            return;
        }
        $type = $section->getEntryTypes()[0];
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $type->id,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'title' => 'Swiss chocolate, shipped worldwide',
            'slug' => 'swiss-chocolate-shipped-worldwide',
        ]);
        $entry->setFieldValues([
            'summary' => 'How a small family business in Bern brings handmade pralines to 40 countries.',
            'body' => '<h2>From Bern to the world</h2>'
                . '<p>Every praline is made by hand in our <strong>Bern</strong> workshop. Read more on <a href="https://www.supertext.com">our website</a>.</p>'
                . '<ul><li>Fresh ingredients from local farmers</li><li>Climate-neutral delivery within 48 hours</li></ul>',
            'highlights' => [
                'new1' => ['type' => 'highlight', 'title' => 'Handmade in Bern', 'fields' => ['text' => 'Every praline is filled and decorated by hand.']],
                'new2' => ['type' => 'highlight', 'title' => 'Shipped in 48 hours', 'fields' => ['text' => 'Cool packaging keeps the chocolate fresh on its way.']],
            ],
        ]);
        $this->save(Craft::$app->getElements()->saveElement($entry), $entry, 'sample article');
        $this->log('Sample article added.');
    }

    /** @return \craft\base\FieldInterface */
    private function field(string $handle, callable $create)
    {
        $service = Craft::$app->getFields();
        $field = $service->getFieldByHandle($handle);
        if (!$field) {
            $field = $create();
            if (!$service->saveField($field)) {
                throw new \RuntimeException("Could not save field {$handle}: " . implode(' ', $field->getFirstErrors()));
            }
            $this->log("Field {$handle} added.");
        }

        return $field;
    }

    /** @param Site[] $sites */
    private function pagesSection(array $sites): Section
    {
        $entries = Craft::$app->getEntries();
        $section = $entries->getSectionByHandle('pages');
        if ($section) {
            return $section;
        }

        $text = Craft::$app->getFields()->getFieldByHandle('text');
        $callout = $entries->getEntryTypeByHandle('callout');
        if (!$callout) {
            $callout = new EntryType(['name' => 'Callout', 'handle' => 'callout', 'icon' => 'bullhorn', 'titleTranslationMethod' => 'site']);
            $callout->setFieldLayout($this->layout([$text]));
            $this->save($entries->saveEntryType($callout), $callout, 'entry type callout');
        }
        $content = $this->field('content', fn() => new \craft\ckeditor\Field([
            'name' => 'Content',
            'handle' => 'content',
            'translationMethod' => 'site',
            'toolbar' => ['heading', '|', 'bold', 'italic', 'link', '|', 'bulletedList', 'numberedList', '|', 'createEntry'],
            'entryTypes' => [$callout],
        ]));
        $facts = $this->field('facts', fn() => new Table([
            'name' => 'Facts',
            'handle' => 'facts',
            'translationMethod' => 'site',
            'columns' => [
                'col1' => ['heading' => 'Fact', 'handle' => 'fact', 'type' => 'singleline'],
                'col2' => ['heading' => 'Number', 'handle' => 'number', 'type' => 'number'],
                'col3' => ['heading' => 'Note', 'handle' => 'note', 'type' => 'singleline'],
            ],
        ]));
        $cta = $this->field('cta', fn() => new Link([
            'name' => 'Call to action',
            'handle' => 'cta',
            'translationMethod' => 'site',
            'types' => ['url', 'entry'],
            'showLabelField' => true,
            'advancedFields' => ['title'],
        ]));

        $type = $entries->getEntryTypeByHandle('page');
        if (!$type) {
            $type = new EntryType(['name' => 'Page', 'handle' => 'page', 'icon' => 'file', 'titleTranslationMethod' => 'site']);
            $type->setFieldLayout($this->layout([$content, $facts, $cta]));
            $this->save($entries->saveEntryType($type), $type, 'entry type page');
        }
        $section = new Section([
            'name' => 'Pages',
            'handle' => 'pages',
            'type' => Section::TYPE_CHANNEL,
            'propagationMethod' => PropagationMethod::All,
            'enableVersioning' => true,
        ]);
        $section->setEntryTypes([$type]);
        $section->setSiteSettings(array_map(fn(Site $site) => new Section_SiteSettings([
            'siteId' => $site->id,
            'enabledByDefault' => true,
            'hasUrls' => false,
        ]), $sites));
        $this->save($entries->saveSection($section), $section, 'section pages');
        $this->log('Section Pages added.');

        return $section;
    }

    /** "About us": text around a nested Callout entry, a table and a link. */
    private function samplePage(Section $section): void
    {
        if (Entry::find()->sectionId($section->id)->status(null)->exists()) {
            return;
        }
        $elements = Craft::$app->getElements();
        $primary = Craft::$app->getSites()->getPrimarySite()->id;
        $page = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'siteId' => $primary,
            'title' => 'About us',
            'slug' => 'about-us',
        ]);
        $page->setFieldValues([
            'facts' => [
                ['col1' => 'Shops in Switzerland', 'col2' => 3, 'col3' => 'Bern, Zurich and Basel'],
                ['col1' => 'Pralines a day', 'col2' => 4000, 'col3' => 'All made by hand'],
            ],
            'cta' => ['type' => 'url', 'value' => 'https://www.supertext.com', 'label' => 'Visit our shop', 'title' => 'Opens our online shop'],
        ]);
        $this->save($elements->saveElement($page), $page, 'sample page');

        $content = Craft::$app->getFields()->getFieldByHandle('content');
        $callout = new Entry([
            'typeId' => Craft::$app->getEntries()->getEntryTypeByHandle('callout')->id,
            'fieldId' => $content->id,
            'ownerId' => $page->id,
            'siteId' => $primary,
            'title' => 'Free delivery',
        ]);
        $callout->setFieldValue('text', 'Orders over CHF 50 are delivered free of charge.');
        $this->save($elements->saveElement($callout), $callout, 'sample callout');

        $page->setFieldValue('content', '<p>We have made chocolate in <strong>Bern</strong> for three generations.</p>'
            . '<craft-entry data-entry-id="' . $callout->id . '">&nbsp;</craft-entry>'
            . '<p>Thank you for choosing our pralines.</p>');
        $this->save($elements->saveElement($page), $page, 'sample page content');
        $this->log('Sample page added.');
    }

    /** @param Site[] $sites */
    private function topics(array $sites): CategoryGroup
    {
        $categories = Craft::$app->getCategories();
        $group = $categories->getGroupByHandle('topics');
        if (!$group) {
            $summary = Craft::$app->getFields()->getFieldByHandle('summary');
            $group = new CategoryGroup(['name' => 'Topics', 'handle' => 'topics']);
            $group->setSiteSettings(array_map(fn(Site $site) => new CategoryGroup_SiteSettings(['siteId' => $site->id, 'hasUrls' => false]), $sites));
            $group->setFieldLayout($this->layout([$summary], Category::class, TitleField::class));
            $this->save($categories->saveGroup($group), $group, 'category group topics');
            $this->log('Category group Topics added.');
        }
        if (!Category::find()->groupId($group->id)->status(null)->exists()) {
            $category = new Category([
                'groupId' => $group->id,
                'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                'title' => 'Handmade chocolate',
                'slug' => 'handmade-chocolate',
            ]);
            $category->setFieldValue('summary', 'Pralines that are filled and decorated by hand in our workshop.');
            $this->save(Craft::$app->getElements()->saveElement($category), $category, 'sample category');
            $this->log('Sample category added.');
        }

        return $group;
    }

    private function footer(): GlobalSet
    {
        $globals = Craft::$app->getGlobals();
        $set = $globals->getSetByHandle('footer');
        if ($set) {
            return $set;
        }
        $tagline = $this->field('tagline', fn() => new PlainText(['name' => 'Tagline', 'handle' => 'tagline', 'translationMethod' => 'site']));
        $contact = $this->field('contact', fn() => new Link([
            'name' => 'Contact link',
            'handle' => 'contact',
            'translationMethod' => 'site',
            'types' => ['url', 'email'],
            'showLabelField' => true,
        ]));
        $set = new GlobalSet(['name' => 'Footer', 'handle' => 'footer']);
        $set->setFieldLayout(FieldLayout::createFromConfig([
            'type' => GlobalSet::class,
            'tabs' => [['name' => 'Content', 'elements' => [
                ['type' => CustomField::class, 'fieldUid' => $tagline->uid],
                ['type' => CustomField::class, 'fieldUid' => $contact->uid],
            ]]],
        ]));
        $this->save($globals->saveSet($set), $set, 'global set footer');

        $set = GlobalSet::find()->handle('footer')->siteId(Craft::$app->getSites()->getPrimarySite()->id)->one();
        $set->setFieldValues([
            'tagline' => 'Handmade in Bern since 1952.',
            'contact' => ['type' => 'url', 'value' => 'https://www.supertext.com', 'label' => 'Write to us'],
        ]);
        $this->save(Craft::$app->getElements()->saveElement($set), $set, 'footer content');
        $this->log('Global set Footer added.');

        return $set;
    }

    /** Volume "Images" (in web/uploads) with one image whose title and alternative text are translated per site. */
    private function images(): Volume
    {
        $volumes = Craft::$app->getVolumes();
        $volume = $volumes->getVolumeByHandle('images');
        if (!$volume) {
            $fsService = Craft::$app->getFs();
            if (!$fsService->getFilesystemByHandle('images')) {
                $fs = $fsService->createFilesystem([
                    'type' => Local::class,
                    'name' => 'Images',
                    'handle' => 'images',
                    'hasUrls' => true,
                    'url' => '@web/uploads',
                    'path' => '@webroot/uploads',
                ]);
                $this->save($fsService->saveFilesystem($fs), $fs, 'filesystem images');
            }
            $volume = new Volume([
                'name' => 'Images',
                'handle' => 'images',
                'titleTranslationMethod' => 'site',
                'altTranslationMethod' => 'site',
                'propagationMethod' => PropagationMethod::All,
            ]);
            $volume->setFsHandle('images');
            $volume->setFieldLayout(FieldLayout::createFromConfig([
                'type' => Asset::class,
                'tabs' => [['name' => 'Content', 'elements' => [['type' => AssetTitleField::class], ['type' => AltField::class]]]],
            ]));
            $this->save($volumes->saveVolume($volume), $volume, 'volume images');
            $this->log('Volume Images added.');
        }

        $asset = Asset::find()->volumeId($volume->id)->status(null)->one();
        if (!$asset) {
            $temp = tempnam(sys_get_temp_dir(), 'demo') . '.png';
            $this->drawPralines($temp);
            $asset = new Asset([
                'tempFilePath' => $temp,
                'filename' => 'pralines.png',
                'newFolderId' => Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id,
                'volumeId' => $volume->id,
                'avoidFilenameConflicts' => true,
                'title' => 'Pralines on a wooden tray',
                'alt' => 'Six hand-decorated pralines on a wooden tray',
            ]);
            $asset->setScenario(Asset::SCENARIO_CREATE);
            $this->save(Craft::$app->getElements()->saveElement($asset), $asset, 'sample image');
            $this->log('Sample image added.');
        }
        // The file lives in the container (no volume on Railway): drawn again when it's missing.
        $file = Craft::getAlias('@webroot/uploads/' . $asset->getPath());
        if (!is_file($file)) {
            @mkdir(\dirname($file), 0775, true);
            $this->drawPralines($file);
        }

        return $volume;
    }

    /** A simple picture of pralines (brown circles on a tray), so the demo needs no image files. */
    private function drawPralines(string $path): void
    {
        $image = imagecreatetruecolor(480, 320);
        imagefill($image, 0, 0, imagecolorallocate($image, 203, 160, 112));
        imagefilledrectangle($image, 30, 40, 450, 280, imagecolorallocate($image, 160, 110, 70));
        $colors = [imagecolorallocate($image, 74, 44, 28), imagecolorallocate($image, 110, 64, 38), imagecolorallocate($image, 238, 225, 205)];
        foreach ([[110, 110], [240, 110], [370, 110], [110, 210], [240, 210], [370, 210]] as $i => [$x, $y]) {
            imagefilledellipse($image, $x, $y, 90, 90, $colors[$i % 3]);
            imagearc($image, $x, $y - 8, 50, 30, 200, 340, $colors[($i + 2) % 3]);
        }
        imagepng($image, $path);
        imagedestroy($image);
    }

    /** Adds permissions to the editors' group (keeps the ones it has). */
    private function grant(UserGroup $group, array $permissions): void
    {
        // A new service: Craft's keeps the list of known permissions from earlier in this run and
        // would drop the ones of a category group, volume or global set created just now.
        $service = new \craft\services\UserPermissions();
        $current = $service->getPermissionsByGroupId($group->id);
        $missing = array_diff(array_map('strtolower', $permissions), $current);
        if ($missing !== []) {
            $service->saveGroupPermissions($group->id, array_merge($current, $missing));
            $this->log('Editors may now edit and translate pages, topics, the footer and images.');
        }
    }

    private function pluginSettings(): void
    {
        $plugins = Craft::$app->getPlugins();
        $plugin = $plugins->getPlugin('supertext-translation');
        if (!$plugin || ($plugin->getSettings()->languages ?? []) !== []) {
            return;
        }
        $plugins->savePluginSettings($plugin, [
            'apiKey' => '$SUPERTEXT_API_KEY',
            'apiUrl' => '$SUPERTEXT_API_URL',
            'environment' => 'live',
            'languages' => [
                'de' => ['code' => '', 'politeness' => 'more'],
                'fr' => ['code' => '', 'politeness' => 'more'],
                'it' => ['code' => '', 'politeness' => 'more'],
            ],
        ]);
        $this->log('Supertext settings saved (formal tone for German, French, Italian).');
    }

    /** @param Site[] $sites */
    private function editorsGroup(Section $section, array $sites): UserGroup
    {
        $users = Craft::$app->getUserGroups();
        $group = $users->getGroupByHandle('editors');
        if ($group) {
            return $group;
        }
        $group = new UserGroup(['name' => 'Editors', 'handle' => 'editors', 'description' => 'Edit and translate articles in every language']);
        $this->save($users->saveGroup($group), $group, 'user group editors');

        $permissions = ['accessCp', 'supertext-translation:translate'];
        foreach ($sites as $site) {
            $permissions[] = "editSite:{$site->uid}";
        }
        foreach (['viewEntries', 'createEntries', 'saveEntries', 'deleteEntries', 'viewPeerEntries', 'savePeerEntries', 'deletePeerEntries', 'viewPeerEntryDrafts', 'savePeerEntryDrafts'] as $action) {
            $permissions[] = "{$action}:{$section->uid}";
        }
        Craft::$app->getUserPermissions()->saveGroupPermissions($group->id, $permissions);
        $this->log('User group Editors added.');

        return $group;
    }

    /** Creates the account from <PREFIX>_EMAIL / _PASSWORD if it doesn't exist. Never changes existing accounts. */
    private function account(string $prefix, ?UserGroup $group): void
    {
        $email = trim((string) getenv("{$prefix}_EMAIL"));
        $password = (string) getenv("{$prefix}_PASSWORD");
        if ($email === '' || $password === '') {
            $this->log("{$prefix}_EMAIL / {$prefix}_PASSWORD not set; skipping that account.");

            return;
        }
        if (User::find()->email($email)->status(null)->exists() || User::find()->username($email)->status(null)->exists()) {
            $this->log("{$prefix}: account exists, left unchanged.");

            return;
        }

        $user = new User([
            'email' => $email,
            'username' => $email,
            'firstName' => 'Demo',
            'lastName' => $group ? 'Editor' : 'Admin',
            'admin' => $group === null,
            'active' => true,
            'newPassword' => $password,
        ]);
        $user->setScenario(User::SCENARIO_REGISTRATION);
        if (!Craft::$app->getElements()->saveElement($user)) {
            // Craft's rules: a valid e-mail address, a password of at least 6 characters.
            $this->stderr("[demo] {$prefix}: account not created (" . implode(' ', $user->getFirstErrors()) . "). Check {$prefix}_EMAIL / {$prefix}_PASSWORD.\n");

            return;
        }
        if ($group) {
            Craft::$app->getUsers()->assignUserToGroups($user->id, [$group->id]);
        }
        $this->log("{$prefix}: account created.");
    }

    /** The image installs Craft with a throwaway admin when DEMO_ADMIN_* is missing; remove it once a real admin exists. */
    private function removeInstaller(): void
    {
        $installer = User::find()->email('installer-*@example.com')->status(null)->one();
        $realAdmin = User::find()->admin(true)->status('active')->andWhere(['not like', 'users.email', 'installer-%@example.com', false])->exists();
        if ($installer && $realAdmin) {
            Craft::$app->getElements()->deleteElement($installer, true);
            $this->log('Installer account removed.');
        }
    }

    /** @param \craft\base\FieldInterface[] $fields */
    private function layout(array $fields, string $type = Entry::class, string $titleField = EntryTitleField::class): FieldLayout
    {
        return FieldLayout::createFromConfig([
            'type' => $type,
            'tabs' => [[
                'name' => 'Content',
                'elements' => array_merge(
                    [['type' => $titleField]],
                    array_map(fn($field) => ['type' => CustomField::class, 'fieldUid' => $field->uid], $fields),
                ),
            ]],
        ]);
    }

    private function save(bool $ok, $model, string $what): void
    {
        if (!$ok) {
            throw new \RuntimeException("Could not save {$what}: " . implode(' ', $model->getFirstErrors()));
        }
    }

    private function log(string $message): void
    {
        $this->stdout("[demo] {$message}\n");
    }
}
