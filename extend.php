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

    // Before `SetLocale`, which is the only position that works: the actor is authenticated
    // by then, and core still gets the last word on a visitor's own explicit choice.
    (new Extend\Middleware('forum'))
        ->insertBefore(SetLocale::class, Middleware\DetectLanguage::class),

    // Administrator-only -- see `Api\AbstractController`, which also whitelists `days`.
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
        ->post(
            '/language-detection/cleanup',
            'huseyinfiliz-language-detection.cleanup',
            Api\CleanupController::class
        ),

    // Daily, because the unit of the data is a day. Forums with no scheduler still have the
    // command by hand and the button on the admin page, and all three share `Cleanup`.
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
