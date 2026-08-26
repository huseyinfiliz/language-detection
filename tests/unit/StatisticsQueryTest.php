<?php

/*
 * This file is part of huseyinfiliz/language-detection.
 *
 * Copyright (c) 2026 Hüseyin Filiz.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace HuseyinFiliz\LanguageDetection\Tests\Unit;

use Carbon\Carbon;
use Flarum\Locale\LocaleManager;
use Flarum\Locale\Translator;
use Flarum\Testing\unit\TestCase;
use HuseyinFiliz\LanguageDetection\LanguageCatalog;
use HuseyinFiliz\LanguageDetection\LocaleMatcher;
use HuseyinFiliz\LanguageDetection\Statistics;
use Illuminate\Database\ConnectionInterface;
use Mockery;

/**
 * How the dashboard's numbers are shaped, once the rows are in hand.
 *
 * Everything here goes through the `…From()` methods, so no query is issued -- and both connections
 * handed in carry no Mockery expectations at all, which makes that an assertion rather than a
 * convention: reach for the database from this half of `Statistics` and these tests fail.
 *
 * Named `StatisticsQueryTest` rather than `StatisticsTest` because `tests/integration` already has
 * a `StatisticsTest` -- Phase 6's counting tests. Two files of the same name would work, since
 * neither directory is autoloadable, but they would be a nuisance to grep for and to talk about.
 *
 * `ApiTest` covers the other half, where `SUM()`, `GROUP BY` and a date range actually run.
 */
class StatisticsQueryTest extends TestCase
{
    public function test_a_language_is_named_from_the_catalog_and_marked_served_when_installed()
    {
        $languages = $this->statistics(['tr'])->languagesFrom($this->volumes(['tr' => [41, 12]]));

        $this->assertCount(1, $languages);
        $this->assertSame('tr', $languages[0]['locale']);
        $this->assertSame('Turkish', $languages[0]['name']);
        $this->assertSame('Türkçe', $languages[0]['native']);
        $this->assertTrue($languages[0]['served']);
        $this->assertSame(41, $languages[0]['requests']);
        $this->assertSame(12, $languages[0]['visitors']);
    }

    public function test_a_language_nobody_installed_is_marked_unserved()
    {
        $languages = $this->statistics(['tr'])->languagesFrom($this->volumes(['ja' => [10, 4]]));

        $this->assertFalse($languages[0]['served']);

        // Still named, because the catalog knows Japanese whether or not the forum does. This is
        // the row the missing-languages table then explains what to do about.
        $this->assertSame('Japanese', $languages[0]['name']);
    }

    public function test_served_means_what_the_middleware_did_and_not_an_exact_match()
    {
        // `tr-tr` is not installed as such, but `LocaleMatcher` truncates it to the installed `tr`
        // and the visitor was served Turkish. A hand-rolled `in_array()` over the installed codes
        // would print a red "unserved" badge against a request that succeeded -- which is why this
        // column is the matcher's answer and nothing else.
        $languages = $this->statistics(['tr'])->languagesFrom($this->volumes(['tr-tr' => [41, 12]]));

        $this->assertTrue($languages[0]['served']);
    }

    public function test_stating_no_preference_is_neither_served_nor_unserved()
    {
        // Null, not false. On most forums this is the largest row in the table, and counted as
        // unserved it would put the summary at "most of your visitors are unserved" and send an
        // admin hunting a problem that does not exist. It has no name either: it is not a
        // language whose display name went missing.
        $languages = $this->statistics(['tr'])->languagesFrom($this->volumes(['' => [9000, 4000]]));

        $this->assertCount(1, $languages);
        $this->assertSame('', $languages[0]['locale']);
        $this->assertNull($languages[0]['served']);
        $this->assertNull($languages[0]['name']);
        $this->assertNull($languages[0]['native']);

        // And it is still counted, because it is real traffic and the biggest thing on the page.
        $this->assertSame(9000, $languages[0]['requests']);
    }

    public function test_a_language_flarum_has_no_pack_for_is_named_by_nothing_and_still_listed()
    {
        $languages = $this->statistics(['tr'])->languagesFrom($this->volumes(['sw' => [30, 20]]));

        $this->assertCount(1, $languages);
        $this->assertNull($languages[0]['name']);
        $this->assertFalse($languages[0]['served']);
    }

    public function test_the_busiest_language_comes_first()
    {
        $languages = $this->statistics(['tr'])->languagesFrom(
            $this->volumes(['ja' => [10, 5], 'de' => [50, 20], 'fr' => [30, 15]])
        );

        $this->assertSame(['de', 'fr', 'ja'], array_column($languages, 'locale'));
    }

    public function test_equal_demand_is_ordered_by_code_rather_than_by_luck()
    {
        // MySQL's row order for equal sums is unspecified, so without a tie-break this table would
        // reshuffle between refreshes and the integration test that asserts its order would be
        // intermittent -- the worst kind of failing test, because it also passes.
        $languages = $this->statistics(['tr'])->languagesFrom(
            $this->volumes(['ja' => [10, 1], 'de' => [10, 9], 'fr' => [10, 5]])
        );

        $this->assertSame(['de', 'fr', 'ja'], array_column($languages, 'locale'));
    }

    public function test_totals_are_integers_whatever_the_driver_handed_over()
    {
        // Belt to the integration test's braces. `SUM()` arrives from PDO as a string; shipped
        // uncast, a JavaScript sort ranks `"9"` above `"41"` and the dashboard names the wrong
        // busiest language with complete confidence.
        $languages = $this->statistics(['tr'])->languagesFrom(
            ['ja' => ['requests' => '41', 'visitors' => '12']]
        );

        $this->assertIsInt($languages[0]['requests']);
        $this->assertIsInt($languages[0]['visitors']);
        $this->assertSame(41, $languages[0]['requests']);
    }

    public function test_an_unknown_country_is_a_row_like_any_other()
    {
        // `''` is what `Analytics` writes for an address it cannot place, and it is worth seeing:
        // a forum whose traffic is mostly unplaceable is a forum whose IP dataset or edge headers
        // are not doing what its admin thinks they are.
        $countries = $this->statistics()->countriesFrom($this->volumes(['TR' => [10, 4], '' => [90, 40]]));

        $this->assertSame(['', 'TR'], array_column($countries, 'country'));
        $this->assertSame([90, 10], array_column($countries, 'requests'));
    }

    public function test_countries_are_ordered_by_demand_then_by_code()
    {
        $countries = $this->statistics()->countriesFrom(
            $this->volumes(['US' => [10, 1], 'DE' => [10, 2], 'JP' => [50, 3]])
        );

        $this->assertSame(['JP', 'DE', 'US'], array_column($countries, 'country'));
    }

    public function test_the_summary_adds_up_to_the_tables_it_sits_above()
    {
        $statistics = $this->statistics(['tr']);

        $languages = $statistics->languagesFrom(
            $this->volumes(['tr' => [100, 40], 'ja' => [30, 10], '' => [70, 25]])
        );
        $countries = $statistics->countriesFrom($this->volumes(['TR' => [120, 50], '' => [80, 25]]));

        $summary = $statistics->summaryFrom($languages, $countries);

        $this->assertSame(200, $summary['requests']);
        $this->assertSame(75, $summary['visitors']);

        // Two languages, not three: `''` did not name one.
        $this->assertSame(2, $summary['languages']);

        // One country, not two: "unknown" is not a country, though it stays in the table.
        $this->assertSame(1, $summary['countries']);

        $this->assertSame(100, $summary['served']);
        $this->assertSame(30, $summary['unserved']);
        $this->assertSame(70, $summary['unstated']);
    }

    public function test_the_three_way_split_accounts_for_every_request()
    {
        // The reason `unstated` exists at all. With two buckets the cards would either misfile
        // 70 requests as unserved or leave them out of both, and an admin reading "served 100,
        // unserved 30" under a total of 200 would rightly wonder where the other 70 went.
        $statistics = $this->statistics(['tr']);

        $languages = $statistics->languagesFrom(
            $this->volumes(['tr' => [100, 40], 'ja' => [30, 10], '' => [70, 25]])
        );

        $summary = $statistics->summaryFrom($languages, []);

        $this->assertSame(
            $summary['requests'],
            $summary['served'] + $summary['unserved'] + $summary['unstated']
        );
    }

    public function test_an_empty_window_is_a_summary_of_zeros_rather_than_of_nulls()
    {
        // A forum that switched analytics on this morning. Every field has to be an integer, or
        // the cards render "null" and the arithmetic behind the percentages throws.
        $summary = $this->statistics()->summaryFrom([], []);

        $this->assertSame(
            ['requests', 'visitors', 'languages', 'countries', 'served', 'unserved', 'unstated'],
            array_keys($summary)
        );

        foreach ($summary as $field => $value) {
            $this->assertSame(0, $value, "field: '$field'");
        }
    }

    public function test_a_quiet_day_is_a_zero_and_not_a_missing_bar()
    {
        // `GROUP BY date` returns only the days that have rows. Shipped as-is, a forum with
        // traffic on two days a week apart would draw two adjacent bars -- a quiet week rendered
        // as a busy two-day one, with the x-axis silently compressed.
        $trend = $this->statistics()->trendFrom(
            $this->volumes(['2026-08-24' => [10, 4], '2026-08-26' => [30, 12]]),
            Carbon::parse('2026-08-26 09:15:00'),
            3
        );

        $this->assertSame(['2026-08-24', '2026-08-25', '2026-08-26'], array_column($trend, 'date'));
        $this->assertSame([10, 0, 30], array_column($trend, 'requests'));
        $this->assertSame([4, 0, 12], array_column($trend, 'visitors'));
    }

    public function test_a_window_of_seven_days_is_seven_entries_ending_today()
    {
        // The boundary the whole dashboard hangs off: seven days is today and the six before it,
        // so that a seven-day total and a seven-bar chart describe the same seven days.
        $trend = $this->statistics()->trendFrom([], Carbon::parse('2026-08-26 23:59:59'), 7);

        $this->assertCount(7, $trend);
        $this->assertSame('2026-08-20', $trend[0]['date']);
        $this->assertSame('2026-08-26', $trend[6]['date']);
    }

    public function test_a_window_of_one_day_is_today_alone()
    {
        $trend = $this->statistics()->trendFrom([], Carbon::parse('2026-08-26 09:15:00'), 1);

        $this->assertSame([['date' => '2026-08-26', 'requests' => 0, 'visitors' => 0]], $trend);
    }

    public function test_a_nonsensical_window_is_clamped_to_a_single_day()
    {
        // Unreachable through the API, which whitelists 7, 30 and 90 -- but a zero or a negative
        // reaching the date arithmetic would ask for a cutoff in the future and report an empty
        // window, and an empty window looks like good news rather than like a bad argument.
        foreach ([0, -1, -400] as $days) {
            $trend = $this->statistics()->trendFrom([], Carbon::parse('2026-08-26 09:15:00'), $days);

            $this->assertCount(1, $trend, "days: $days");
            $this->assertSame('2026-08-26', $trend[0]['date'], "days: $days");
        }
    }

    public function test_an_empty_table_still_draws_a_full_chart()
    {
        $trend = $this->statistics()->trendFrom([], Carbon::parse('2026-08-26 09:15:00'), 30);

        $this->assertCount(30, $trend);
        $this->assertSame([0], array_unique(array_column($trend, 'requests')));
    }

    public function test_a_row_from_outside_the_window_cannot_lengthen_the_chart()
    {
        // The query filters by date, so this should not arise -- but the fill iterates the window
        // rather than the rows, which is what makes the chart's length a property of the window
        // instead of a property of whatever the database happened to return.
        $trend = $this->statistics()->trendFrom(
            $this->volumes(['2020-01-01' => [999, 999], '2026-08-26' => [1, 1]]),
            Carbon::parse('2026-08-26 09:15:00'),
            7
        );

        $this->assertCount(7, $trend);
        $this->assertSame(1, array_sum(array_column($trend, 'requests')));
    }

    public function test_nothing_requested_means_no_rows_at_all()
    {
        $statistics = $this->statistics(['tr']);

        $this->assertSame([], $statistics->languagesFrom([]));
        $this->assertSame([], $statistics->countriesFrom([]));
    }

    /**
     * @param string[] $installed locale codes, spelled as a language pack would publish them
     */
    protected function statistics(array $installed = []): Statistics
    {
        $locales = new LocaleManager(new Translator('en'));

        foreach ($installed as $code) {
            $locales->addLocale($code, $code);
        }

        // One matcher, shared with the catalog, because "can the forum serve this?" has to have
        // exactly one answer on this page -- the same one the middleware gave the visitor.
        $matcher = new LocaleMatcher($locales);

        return new Statistics(
            Mockery::mock(ConnectionInterface::class),
            new LanguageCatalog($matcher, Mockery::mock(ConnectionInterface::class)),
            $matcher
        );
    }

    /**
     * @param array<string, array{int, int}> $volumes key => [requests, visitors]
     *
     * @return array<string, array{requests: int, visitors: int}>
     */
    protected function volumes(array $volumes): array
    {
        $shaped = [];

        foreach ($volumes as $key => [$requests, $visitors]) {
            $shaped[(string) $key] = ['requests' => $requests, 'visitors' => $visitors];
        }

        return $shaped;
    }
}
