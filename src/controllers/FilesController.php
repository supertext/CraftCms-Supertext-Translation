<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use supertext\crafttranslation\api\FileExchange;
use supertext\crafttranslation\api\SupertextException;
use supertext\crafttranslation\helpers\Messages;
use supertext\crafttranslation\Plugin;
use supertext\crafttranslation\sdk\Files\TranslationFileError;
use yii\web\Response;

/**
 * Export for translation / Import translation (XLIFF 1.2 and HTML files, see api/FileExchange):
 *
 *   POST actions/supertext-translation/files/export   { entryId, siteId, targetSiteIds[], format } → the file (attachment)
 *   POST actions/supertext-translation/files/import   multipart { file, includeChanged, overwrite } → { results }
 *
 * Same permission as translating; the import also checks that the user may save each entry in its
 * target site. Uploads are only read in memory, never stored.
 */
class FilesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION);
        $this->requirePostRequest();

        return true;
    }

    public function actionExport(): Response
    {
        $request = Craft::$app->getRequest();
        $format = $request->getBodyParam('format') === FileExchange::FORMAT_HTML ? FileExchange::FORMAT_HTML : FileExchange::FORMAT_XLIFF;
        $targets = array_values(array_filter(array_map('intval', (array) $request->getBodyParam('targetSiteIds', []))));
        if ($targets === []) {
            return $this->asFailure(Craft::t('supertext-translation', 'Choose at least one site.'));
        }
        if ($format === FileExchange::FORMAT_HTML && \count($targets) !== 1) {
            return $this->asFailure(Craft::t('supertext-translation', 'An HTML file holds exactly one language. Choose one site or XLIFF.'));
        }

        try {
            $file = Plugin::getInstance()->getTranslator()->exportFile(
                (int) $request->getRequiredBodyParam('entryId'),
                (int) $request->getRequiredBodyParam('siteId'),
                $targets,
                $format,
            );
        } catch (SupertextException $e) {
            return $this->asFailure(Messages::of($e));
        } catch (TranslationFileError) {
            $file = null; // invalid_job: nothing left to translate
        }
        if ($file === null) {
            return $this->asFailure(Craft::t('supertext-translation', 'Nothing to translate.'));
        }

        $response = Craft::$app->getResponse();
        $response->getHeaders()->set('Cache-Control', 'no-store')->set('X-Content-Type-Options', 'nosniff');

        return $response->sendContentAsFile($file['content'], $file['filename'], ['mimeType' => $file['contentType']]);
    }

    public function actionImport(): Response
    {
        $request = Craft::$app->getRequest();
        $upload = UploadedFile::getInstanceByName('file');
        if (!$upload) {
            return $this->asFailure(Craft::t('supertext-translation', 'Please choose a file.'));
        }
        if (\in_array($upload->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $upload->size > FileExchange::MAX_UPLOAD_BYTES) {
            return $this->asFailure(Messages::fileError('too_large'));
        }
        $content = $upload->error === UPLOAD_ERR_OK ? file_get_contents($upload->tempName, false, null, 0, FileExchange::MAX_UPLOAD_BYTES + 1) : false;
        if ($content === false) {
            return $this->asFailure(Craft::t('supertext-translation', 'Please choose a file.'));
        }

        try {
            $results = Plugin::getInstance()->getTranslator()->importFile(
                $content,
                (bool) $request->getBodyParam('includeChanged', false),
                (bool) $request->getBodyParam('overwrite', false),
                Craft::$app->getUser()->getIdentity(),
            );
        } catch (TranslationFileError $e) {
            return $this->asFailure(Messages::fileError($e->errorCode));
        }

        return $this->asJson(['results' => $results]);
    }
}
