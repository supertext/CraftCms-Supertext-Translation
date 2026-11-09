<?php

use craft\config\GeneralConfig;
use craft\helpers\App;

return GeneralConfig::create()
    ->defaultWeekStartDay(1)
    ->omitScriptNameInUrls()
    ->preventUserEnumeration()
    ->loginPath(false)
    ->enableTwigSandbox()
    // The demo keeps its project config in the database (no volume); files are regenerated on start.
    ->allowAdminChanges(App::env('CRAFT_ALLOW_ADMIN_CHANGES') ?? true)
    ->devMode(App::env('CRAFT_DEV_MODE') ?? false)
    ->disallowRobots(true)
    // No pg_dump/mysqldump in the image: a backup before migrations fails the update
    // (and its error message contains the database command line, password included).
    ->backupOnUpdate(false)
    // Translated slugs ("chocolat-suisse-expedie-…") stay ASCII.
    ->limitAutoSlugsToAscii(true)
    ->aliases([
        '@webroot' => dirname(__DIR__) . '/web',
    ]);
