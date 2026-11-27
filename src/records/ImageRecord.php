<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\records;

use craft\db\ActiveRecord;

/**
 * The images of a translated HTML field in one site, as the plugin last wrote them (see
 * helpers\Images): per image, the source's image and the site's, and whether it is the same,
 * the site's own, or to check.
 *
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property int $sourceSiteId
 * @property string $field
 * @property string $images JSON list of records
 */
class ImageRecord extends ActiveRecord
{
    public const TABLE = '{{%supertext_images}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
