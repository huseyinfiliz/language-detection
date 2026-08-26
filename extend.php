<?php

/*
 * This file is part of huseyinfiliz/language-detection.
 *
 * Copyright (c) 2026 Hüseyin Filiz.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace HuseyinFiliz\LanguageDetection;

use Flarum\Extend;
use Flarum\Http\Middleware\SetLocale;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less')
        ->content(Content\AdminPayload::class),

    new Extend\Locales(__DIR__.'/locale'),

    // Detection has to happen after the actor is known and before core decides the
    // locale, and this is the only position that satisfies both. Running after
    // `SetLocale` would mean overwriting a choice the visitor had already made.
    (new Extend\Middleware('forum'))
        ->insertBefore(SetLocale::class, Middleware\DetectLanguage::class),

    // Read-only, administrator-only, and outside JSON:API on purpose -- see
    // `Api\AbstractController`, which is also where the `days` parameter is whitelisted.
    (new Extend\Routes('api'))
        ->get(
            '/language-detection/statistics',
            'huseyinfiliz-language-detection.statistics',
            Api\StatisticsController::class
        )
        ->get(
            '/language-detection/missing',
            'huseyinfiliz-language-detection.missing',
            Api\MissingLanguagesController::class
        )
        // The one endpoint that writes. It deletes by the saved `retention_days` setting and takes
        // no period from the request, so there is nothing here a caller can widen.
        ->post(
            '/language-detection/cleanup',
            'huseyinfiliz-language-detection.cleanup',
            Api\CleanupController::class
        ),

    // Daily, because the unit of the data is a day: running it more often could only ever delete
    // rows the previous run already had. Forums without a configured scheduler still have the
    // command by hand and the button on the admin page, which is why all three share `Cleanup`.
    (new Extend\Console())
        ->command(Console\CleanupCommand::class)
        ->schedule(Console\CleanupCommand::class, function (Event $event) {
            $event->daily();
        }),

    (new Extend\Settings())
        ->default('huseyinfiliz-language-detection.detection_order', 'browser_ip')
        ->default('huseyinfiliz-language-detection.default_locale', '')
        ->default('huseyinfiliz-language-detection.enable_analytics', '1')
        ->default('huseyinfiliz-language-detection.ignore_bots', '1')
        ->default('huseyinfiliz-language-detection.retention_days', '90'),
];
