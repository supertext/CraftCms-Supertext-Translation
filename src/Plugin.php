<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use supertext\crafttranslation\api\Languages;
use supertext\crafttranslation\api\Reporting as ApiReporting;
use supertext\crafttranslation\api\SupertextClient;
use supertext\crafttranslation\elements\actions\TranslateWithSupertext;
use supertext\crafttranslation\helpers\Messages;
use supertext\crafttranslation\models\Settings;
use supertext\crafttranslation\pluginhealth\KeyCheck;
use supertext\crafttranslation\services\Reporting;
use supertext\crafttranslation\services\Translator;
use supertext\crafttranslation\web\assets\translate\TranslateAsset;
use yii\base\Event;

/**
 * Supertext Translation: translates entries, categories, global sets and assets into the other
 * sites with Supertext AI.
 *
 * - Edit pages of entries, categories and assets: a "Supertext" box in the sidebar (choose sites,
 *   translate); global sets: the same box below their fields
 * - Entry, category and asset indexes: a "Translate with Supertext" action (queue jobs, missing translations only)
 * - Settings: API key, environment, languages and tone, Test connection, version reports
 * - Console: php craft supertext-translation/translate
 * - Commando Center: version reports, API key check, public ping (actions/supertext-translation/ping)
 *
 * @property-read Translator $translator
 * @property-read Reporting $reporting
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION = 'supertext-translation:translate';

    public string $schemaVersion = '1.2.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => ['translator' => Translator::class, 'reporting' => Reporting::class],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'supertext\\crafttranslation\\console\\controllers';
        }

        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function (RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => 'Supertext',
                'permissions' => [
                    self::PERMISSION => ['label' => Craft::t('supertext-translation', 'Translate content with Supertext')],
                ],
            ];
        });

        foreach ([Entry::class, Category::class, Asset::class] as $type) {
            Event::on($type, Element::EVENT_REGISTER_ACTIONS, function (RegisterElementActionsEvent $event) {
                if (Craft::$app->getIsMultiSite() && Craft::$app->getUser()->checkPermission(self::PERMISSION)) {
                    $event->actions[] = TranslateWithSupertext::class;
                }
            });

            Event::on($type, Element::EVENT_DEFINE_SIDEBAR_HTML, function (DefineHtmlEvent $event) {
                /** @var ElementInterface $element */
                $element = $event->sender;
                $html = $this->boxHtml($element);
                if ($html !== '') {
                    $event->html = $html . $event->html;
                }
            });
        }

        // Global sets have their own edit page without a sidebar: the box goes below the fields.
        Craft::$app->getView()->hook('cp.globals.edit.content', function (array &$context) {
            $globalSet = $context['globalSet'] ?? null;

            return $globalSet instanceof GlobalSet ? $this->boxHtml($globalSet) : '';
        });
    }

    public function getTranslator(): Translator
    {
        /** @var Translator */
        return $this->get('translator');
    }

    public function getReporting(): Reporting
    {
        /** @var Reporting */
        return $this->get('reporting');
    }

    /**
     * Every save of the plugin settings: the automatic API key check and the `installed`/`settings_saved`
     * report. The check is shown as a notice, like on the settings page. Never fails the save.
     */
    public function afterSaveSettings(): void
    {
        parent::afterSaveSettings();
        try {
            $check = $this->getReporting()->afterSettingsSaved()?->check;
            $request = Craft::$app->getRequest();
            if ($check !== null && !$request->getIsConsoleRequest() && $request->getIsCpRequest()) {
                $session = Craft::$app->getSession();
                $text = strip_tags(Messages::keyCheck($check));
                if ($check->status === KeyCheck::OK) {
                    $session->setNotice($text);
                } else {
                    $session->setError($text);
                }
            }
        } catch (\Throwable $e) {
            Craft::debug('Supertext key check after saving the settings skipped: ' . $e->getMessage(), __METHOD__);
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $reporting = $this->getReporting();
        $reporting->onRequest();
        $settings = $this->getSettings();
        $languages = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $target = $settings->targetCodeOrNull($site->handle, $site->language);
            $configured = Settings::configuredLanguageCode($site->handle);
            $languages[$site->handle] = [
                // The select shows the saved choice, else the suggestion (exact match or the SDK's default), else "— choose —".
                'code' => $settings->languageCode($site->handle) ?: (string) Languages::suggest($site->language),
                'configured' => $configured !== '',
                'target' => $target,
                'politenessSupported' => $target !== null ? Languages::supportsPoliteness($target) : null,
                'review' => $settings->review($site->handle),
                'reviewConfigured' => Settings::configuredReview($site->handle) !== null,
            ];
        }
        $check = $reporting->lastCheck();

        return Craft::$app->getView()->renderTemplate('supertext-translation/_settings', [
            'settings' => $settings,
            'sites' => Craft::$app->getSites()->getAllSites(),
            'languages' => $languages,
            'targets' => Languages::options(Craft::$app->language),
            'reviewAvailable' => $settings->getReviewUrl() !== '',
            'reviewDefault' => SupertextClient::REVIEW_URL,
            'reviewForced' => Settings::reviewUrlFromEnvironment() !== null,
            'check' => $check,
            'checkText' => $check !== null ? Messages::keyCheck($check) : null,
            'reportsEnabled' => $reporting->enabled(),
            'reportsForced' => ApiReporting::forcedByEnvironment(),
            'pingUrl' => $reporting->callbackUrl($reporting->siteUrl()),
        ]);
    }

    /** The Supertext box on an edit page (saved, canonical elements in a multi-site install only). */
    private function boxHtml(ElementInterface $element): string
    {
        $request = Craft::$app->getRequest();
        if (!$request->getIsCpRequest() || $request->getIsConsoleRequest() || !$element->id) {
            return '';
        }
        if ($element->getIsRevision() || ($element->getIsDraft() && !$element->isProvisionalDraft) || $element->getIsUnpublishedDraft()) {
            return '';
        }
        if (!Craft::$app->getUser()->checkPermission(self::PERMISSION) || \count($element->getSupportedSites()) < 2) {
            return '';
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(TranslateAsset::class);
        $view->registerTranslations('supertext-translation', TranslateAsset::MESSAGES);

        return $view->renderTemplate('supertext-translation/_sidebar', [
            'elementId' => $element->getCanonicalId(),
            'siteId' => $element->siteId,
            'below' => $element instanceof GlobalSet,
        ]);
    }
}
