<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\controllers;

use Craft;
use craft\web\Controller;
use supertext\crafttranslation\api\SupertextException;
use supertext\crafttranslation\helpers\Messages;
use supertext\crafttranslation\Plugin;
use supertext\crafttranslation\pluginhealth\KeyCheck;
use yii\web\Response;

/**
 * Control panel actions:
 *
 *   GET  actions/supertext-translation/translate/info?elementId=&siteId=  sites of the entry, category, global set or asset
 *   POST actions/supertext-translation/translate/entry                    { elementId, sourceSiteId, targetSiteIds[], overwrite }
 *
 * `entryId` is still accepted for `elementId` (pages still open from an earlier version).
 *   POST actions/supertext-translation/translate/test-connection          admins: the API key check
 *   POST actions/supertext-translation/translate/send-configuration       admins: installed plugins and language mapping to Supertext
 */
class TranslateController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        if ($action->id === 'test-connection' || $action->id === 'send-configuration') {
            $this->requireAdmin(false);
        } else {
            $this->requirePermission(Plugin::PERMISSION);
        }

        return true;
    }

    public function actionInfo(): Response
    {
        $request = Craft::$app->getRequest();
        $elementId = (int) ($request->getQueryParam('elementId') ?? $request->getRequiredQueryParam('entryId'));
        $siteId = (int) $request->getRequiredQueryParam('siteId');
        $translator = Plugin::getInstance()->getTranslator();
        $element = $translator->elementInSite($elementId, $siteId);
        if (!$element) {
            return $this->asFailure(Craft::t('supertext-translation', 'This content was not found.'));
        }

        Plugin::getInstance()->getReporting()->onRequest();

        return $this->asJson($translator->describe($element, Craft::$app->getUser()->getIdentity()) + [
            'configured' => Plugin::getInstance()->getSettings()->getApiKey() !== '',
        ]);
    }

    public function actionEntry(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $targets = array_map('intval', (array) $request->getBodyParam('targetSiteIds', []));
        if ($targets === []) {
            return $this->asFailure(Craft::t('supertext-translation', 'Choose at least one site.'));
        }

        try {
            $results = Plugin::getInstance()->getTranslator()->translate(
                (int) ($request->getBodyParam('elementId') ?? $request->getRequiredBodyParam('entryId')),
                (int) $request->getRequiredBodyParam('sourceSiteId'),
                $targets,
                (bool) $request->getBodyParam('overwrite', false),
                Craft::$app->getUser()->getIdentity(),
            );
        } catch (SupertextException $e) {
            return $this->asFailure(Messages::of($e));
        }

        return $this->asJson(['results' => $results]);
    }

    /** The automatic API key check (cost-free `GET features`): { ok, status, message (HTML), checkedAt }. */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $check = Plugin::getInstance()->getReporting()->testConnection();

        return $this->asJson([
            'ok' => $check->status === KeyCheck::OK,
            'status' => $check->status,
            'message' => Messages::keyCheck($check),
            'checkedAt' => $check->checkedAt,
        ]);
    }

    /** *Send configuration to Supertext*: { ok, message } (the installed plugins and the language mapping, only while reports are on). */
    public function actionSendConfiguration(): Response
    {
        $this->requirePostRequest();
        $result = Plugin::getInstance()->getReporting()->sendConfiguration();
        if (!$result['enabled']) {
            $message = Craft::t('supertext-translation', 'Turn on “Send version information to Supertext” to send the configuration.');
        } elseif (!$result['sent']) {
            $message = Craft::t('supertext-translation', 'The configuration couldn\'t be sent. Try again later.');
        } else {
            $message = Craft::t('supertext-translation', 'Sent: {plugins} plugins, {languages} languages.', [
                'plugins' => $result['plugins'],
                'languages' => $result['languages'],
            ]);
        }

        return $this->asJson(['ok' => $result['sent'], 'message' => $message]);
    }
}
