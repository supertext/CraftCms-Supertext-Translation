<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\migrations;

use craft\db\Migration;
use craft\db\Table;
use supertext\crafttranslation\records\ImageRecord;

/** The images of translated HTML fields per site, so overwriting keeps a site's own images (1.3). */
class m261128_000000_images extends Migration
{
    public function safeUp(): bool
    {
        self::createImagesTable($this);

        return true;
    }

    public static function createImagesTable(Migration $migration): void
    {
        if ($migration->db->tableExists(ImageRecord::TABLE)) {
            return;
        }
        $migration->createTable(ImageRecord::TABLE, [
            'id' => $migration->primaryKey(),
            'elementId' => $migration->integer()->notNull(),
            'siteId' => $migration->integer()->notNull(),
            'sourceSiteId' => $migration->integer()->notNull(),
            'field' => $migration->string()->notNull(),
            'images' => $migration->text()->notNull(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);
        $migration->createIndex(null, ImageRecord::TABLE, ['elementId', 'siteId', 'field'], true);
        $migration->addForeignKey(null, ImageRecord::TABLE, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE');
        $migration->addForeignKey(null, ImageRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE');
        $migration->addForeignKey(null, ImageRecord::TABLE, ['sourceSiteId'], Table::SITES, ['id'], 'CASCADE');
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(ImageRecord::TABLE);

        return true;
    }
}
