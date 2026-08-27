<?php

/*
 * This file is part of huseyinfiliz/language-detection.
 *
 * Copyright (c) 2026 Hüseyin Filiz.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace HuseyinFiliz\LanguageDetection\Tests\Integration;

use Carbon\Carbon;
use Flarum\Locale\LocaleManager;
use Flarum\Testing\integration\TestCase;
use HuseyinFiliz\LanguageDetection\LanguageCatalog;

/**
 * The missing-languages query, against a real database.
 *
 * `LanguageCatalogTest` covers every decision the report makes; what is left over is the one
 * aggregate query, and it can only be demonstrated where `SUM()`, `GROUP BY` and a date range
 * actually run. That is what this file is for.
 *
 * Rows are seeded directly rather than accumulated by sending requests. The report is about
 * volume across days, and building a week of history through the middleware would take dozens of
 * requests, pin every assertion to today's date, and test `Analytics` all over again --
 * `StatisticsTest` already does that. Seeding instead makes the window boundaries something this
 * test can state exactly.
 *
 * The catalog is resolved from the container in every test, so the claim that `extend.php` needs
 * no registration for it is exercised rather than assumed.
 */
class MissingLanguagesTest extends TestCase
{
    const TABLE = 'language_detection_stats';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('huseyinfiliz-language-detection');
    }

    public function test_it_adds_up_one_language_across_several_days()
    {
        $this->seed([
            [$this->daysAgo(0), 'ja', 10, 4],
            [$this->daysAgo(1), 'ja', 20, 8],
            [$this->daysAgo(3), 'ja', 11, 3],
        ]);

        $report = $this->catalog()->missing(null);

        $this->assertCount(1, $report);
        $this->assertSame('ja', $report[0]['locale']);
        $this->assertSame(41, $report[0]['requests']);
        $this->assertSame(15, $report[0]['visitors']);
    }

    public function test_it_names_the_package_an_admin_would_install()
    {
        $this->seed([[$this->daysAgo(0), 'ja', 41, 15]]);

        $report = $this->catalog()->missing(null);

        $this->assertSame('Japanese', $report[0]['name']);
        $this->assertSame('flarum-lang/japanese', $report[0]['package']);
    }

    public function test_the_totals_are_integers_and_not_the_strings_the_driver_returns()
    {
        // `SUM()` comes back from PDO as a string. Uncast, the API response would ship
        // `"requests": "41"` and the dashboard would sort its busiest languages as text -- so
        // `"9"` would outrank `"41"`. This is the one thing in this file that a unit test could
        // never catch, because there is no driver in a unit test to return the wrong type.
        $this->seed([[$this->daysAgo(0), 'ja', 41, 15]]);

        $report = $this->catalog()->missing(null);

        $this->assertIsInt($report[0]['requests']);
        $this->assertIsInt($report[0]['visitors']);
    }

    public function test_a_window_leaves_out_the_days_before_it()
    {
        $this->seed([
            [$this->daysAgo(0), 'ja', 10, 4],
            [$this->daysAgo(10), 'ja', 100, 40],
        ]);

        $this->assertSame(10, $this->catalog()->missing(7)[0]['requests']);
        $this->assertSame(110, $this->catalog()->missing(null)[0]['requests']);
    }

    public function test_a_window_of_seven_days_reaches_back_six()
    {
        // The boundary, asserted from both sides. Seven days means today and the six before it,
        // not today and the seven before it, because the dashboard shows a seven-day total beside
        // a seven-bar chart and the two have to be the same seven days.
        $this->seed([
            [$this->daysAgo(6), 'ja', 10, 4],
            [$this->daysAgo(7), 'ja', 100, 40],
        ]);

        $this->assertSame(10, $this->catalog()->missing(7)[0]['requests']);
    }

    public function test_a_window_of_one_day_is_today_alone()
    {
        $this->seed([
            [$this->daysAgo(0), 'ja', 5, 2],
            [$this->daysAgo(1), 'ja', 50, 20],
        ]);

        $this->assertSame(5, $this->catalog()->missing(1)[0]['requests']);
    }

    public function test_a_language_the_forum_already_serves_is_left_out()
    {
        // The filter that makes this a report about missing languages rather than a traffic
        // breakdown. Turkish is installed below, so its rows are excluded however many there are.
        $this->seed([
            [$this->daysAgo(0), 'tr', 900, 400],
            [$this->daysAgo(0), 'ja', 10, 4],
        ]);

        $report = $this->catalog(['tr'])->missing(null);

        $this->assertSame(['ja'], array_column($report, 'locale'));
    }

    public function test_visitors_who_stated_no_preference_are_left_out()
    {
        // Typically the largest bucket in the table, and not a language. Left in it would head
        // the report with no name, no package and nothing an admin could do about it.
        $this->seed([
            [$this->daysAgo(0), '', 9000, 4000],
            [$this->daysAgo(0), 'ja', 10, 4],
        ]);

        $report = $this->catalog()->missing(null);

        $this->assertSame(['ja'], array_column($report, 'locale'));
    }

    public function test_one_language_asked_for_in_several_ways_is_one_row()
    {
        // Two spellings, two days, four rows in the table, one pack to install. The roll-up
        // happens after the query, so this also shows that grouping by pack survives the trip
        // through `GROUP BY locale`.
        $this->seed([
            [$this->daysAgo(0), 'de', 10, 4],
            [$this->daysAgo(0), 'de-de', 5, 2],
            [$this->daysAgo(1), 'de', 20, 8],
            [$this->daysAgo(1), 'de-at', 1, 1],
        ]);

        $report = $this->catalog()->missing(null);

        $this->assertCount(1, $report);
        $this->assertSame('de', $report[0]['locale']);
        $this->assertSame(36, $report[0]['requests']);
        $this->assertSame(15, $report[0]['visitors']);
        $this->assertSame(['de', 'de-at', 'de-de'], $report[0]['tags']);
    }

    public function test_the_same_language_from_two_countries_is_one_row_here()
    {
        // `country_code` is part of the table's key, so Turkish-from-Germany and
        // Turkish-from-Turkey are separate rows -- and this report groups by language alone,
        // because which pack to install does not depend on where the readers are.
        $this->seed([
            [$this->daysAgo(0), 'ja', 10, 4, 'JP'],
            [$this->daysAgo(0), 'ja', 3, 1, 'US'],
        ]);

        $report = $this->catalog()->missing(null);

        $this->assertCount(1, $report);
        $this->assertSame(13, $report[0]['requests']);
    }

    public function test_languages_are_ordered_by_their_totals_and_not_by_any_single_day()
    {
        // German loses today and wins the week. Ordering has to happen after the sums, or the
        // top of this report would be whichever language happened to be busy most recently.
        $this->seed([
            [$this->daysAgo(0), 'de', 5, 2],
            [$this->daysAgo(1), 'de', 40, 16],
            [$this->daysAgo(0), 'ja', 30, 12],
        ]);

        $report = $this->catalog()->missing(null);

        $this->assertSame(['de', 'ja'], array_column($report, 'locale'));
        $this->assertSame([45, 30], array_column($report, 'requests'));
    }

    public function test_an_empty_table_is_an_empty_report()
    {
        $this->assertSame([], $this->catalog()->missing(null));
        $this->assertSame([], $this->catalog()->missing(30));
    }

    /**
     * Insert rows straight into the statistics table.
     *
     * Each row is `[date, locale, requests, visitors]`, with an optional fifth element for the
     * country when a test needs two rows to differ by nothing else.
     *
     * @param array<array<int, int|string>> $rows
     */
    protected function seed(array $rows): void
    {
        $insert = [];

        foreach ($rows as $row) {
            $insert[] = [
                'date'            => $row[0],
                'locale'          => $row[1],
                'requests'        => $row[2],
                'unique_visitors' => $row[3],
                // Part of the table's unique key, so every seeded row needs one. `''` is what
                // `Analytics` writes for a visitor it cannot place, and is the realistic value.
                'country_code'    => $row[4] ?? '',
            ];
        }

        $this->database()->table(self::TABLE)->insert($insert);
    }

    /**
     * A date `$days` before today, read from the same clock `missing()` reads.
     */
    protected function daysAgo(int $days): string
    {
        $this->app();

        return Carbon::now()->subDays($days)->toDateString();
    }

    /**
     * A catalog from the container, over a given installed set.
     *
     * The locale manager is a container singleton, so adding to it here is what the rest of the
     * application -- and therefore `LocaleMatcher` -- sees. English is registered already, as the
     * forum's configured default; nothing below relies on any language but the ones it names.
     *
     * @param string[] $installed extra locale codes to register as installed
     */
    protected function catalog(array $installed = []): LanguageCatalog
    {
        $container = $this->app()->getContainer();

        $locales = $container->make(LocaleManager::class);

        foreach ($installed as $code) {
            $locales->addLocale($code, $code);
        }

        // Resolved rather than constructed: `LanguageCatalog` takes a `LocaleMatcher`, a
        // connection and a nullable path, and this is the assertion that all three are
        // autowirable without anything registered in `extend.php`.
        return $container->make(LanguageCatalog::class);
    }
}
