<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\controllers;

use Craft;
use craft\web\Controller;
use supertext\crafttranslation\helpers\FieldValues;
use supertext\crafttranslation\helpers\Images;
use supertext\crafttranslation\Plugin;
use supertext\crafttranslation\records\ImageRecord;
use yii\web\Response;

/**
 *   POST actions/supertext-translation/images/checked   Utilities → Supertext images: "Mark as checked"
 *
 * The image the site has now in that place decides: the source's (`same`) or its own (`own`).
 */
class ImagesController extends Controller
{
    public function actionChecked(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requirePermission('utility:supertext-images');
        $request = Craft::$app->getRequest();
        $where = [
            'elementId' => (int) $request->getRequiredBodyParam('elementId'),
            'siteId' => (int) $request->getRequiredBodyParam('siteId'),
            'field' => (string) $request->getRequiredBodyParam('field'),
        ];
        $slot = (int) $request->getRequiredBodyParam('slot');
        $status = (string) $request->getBodyParam('status', 'all');

        $record = ImageRecord::findOne($where);
        $images = $record ? json_decode($record->images, true) : null;
        if ($record && \is_array($images)) {
            $element = Plugin::getInstance()->getTranslator()->elementInSite($where['elementId'], $where['siteId']);
            $now = $element ? Images::at(FieldValues::html($element->getFieldValue($where['field'])), $slot) : null;
            foreach ($images as &$image) {
                if (\is_array($image) && (int) ($image['slot'] ?? -1) === $slot) {
                    $src = $now ?? (string) $image['src'];
                    $image = ['slot' => $slot, 'originalSrc' => (string) $image['originalSrc'], 'src' => $src, 'status' => Images::identity($src) === Images::identity((string) $image['originalSrc']) ? Images::SAME : Images::OWN];
                }
            }
            unset($image);
            $record->images = (string) json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $record->save(false);
        }

        return $this->redirect('utilities/supertext-images?checked=1' . ($status !== 'all' ? '&status=' . rawurlencode($status) : ''));
    }
}
