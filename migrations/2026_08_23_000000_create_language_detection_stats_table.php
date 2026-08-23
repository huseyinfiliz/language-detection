<?php

/*
 * This file is part of huseyinfiliz/language-detection.
 *
 * Copyright (c) 2026 Hüseyin Filiz.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable(
    'language_detection_stats',
    function (Blueprint $table) {
        $table->increments('id');

        // One row per (day, requested locale, country). `locale` holds the locale the
        // visitor asked for, which is not necessarily one that is installed -- that is
        // what makes the missing-languages report and the fallback count possible
        // without any extra columns.
        $table->date('date');
        $table->string('locale', 20);

        // NOT NULL DEFAULT '' rather than nullable: MySQL treats NULLs as distinct in a
        // unique index, so a nullable column would let every request without a resolved
        // country insert a fresh row instead of incrementing the existing one, and the
        // atomic upsert below would never dedupe. '' means "unknown".
        $table->char('country_code', 2)->default('');

        $table->unsignedInteger('requests')->default(0);
        $table->unsignedInteger('unique_visitors')->default(0);

        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();

        // Deduplication key for the atomic daily upsert. Index names are given
        // explicitly because Laravel prefixes generated names with the configured table
        // prefix, which can push them past MySQL's 64-character limit.
        $table->unique(['date', 'locale', 'country_code'], 'language_detection_stats_unique');

        // No standalone index on `date`: the unique index above already has `date` as its
        // leftmost column, so every date-range query (dashboard, cleanup) can use it.
        $table->index(['locale', 'date'], 'language_detection_stats_locale_date');
        $table->index(['country_code', 'date'], 'language_detection_stats_country_date');
    }
);
