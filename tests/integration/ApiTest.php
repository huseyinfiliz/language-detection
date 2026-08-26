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
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The two admin endpoints, over HTTP, against a real database.
 *
 * `StatisticsQueryTest` covers the shaping and `LanguageCatalogTest` covers the missing-languages
 * decisions; what is left over is everything that can only be demonstrated end to end -- that the
 * routes are registered, that they are administrator-only, that the controllers autowire, that
 * `SUM()` and `GROUP BY` produce the numbers the shaping expects, and that the `days` parameter
 * survives the trip from a query string to a date range.
 *
 * The authorisation tests come first on purpose. They are the only security property this extension
 * has: a route registered without `assertAdmin()` returns the same payload to anyone who guesses the
 * URL, and it does it silently, so nothing about a passing dashboard would reveal it.
 *
 * Rows are seeded rather than accumulated through the middleware, for the reasons `StatisticsTest`
 * and `MissingLanguagesTest` already give -- building a month of history one request at a time would
 * pin every assertion to today and test `Analytics` for a third time.
 */
class ApiTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    const TABLE = 'language_detection_stats';

    const STATISTICS = '/api/language-detection/statistics';

    const MISSING = '/api/language-detection/missing';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('huseyinfiliz-language-detection');

        // Id 2, and deliberately not an administrator. The setup script's only user is the
        // administrator at id 1.
        $this->prepareDatabase(['users' => [$this->normalUser()]]);
    }

    public function test_a_guest_cannot_read_a_forums_traffic()
    {
        $this->assertSame(403, $this->status(self::STATISTICS, null));
        $this->assertSame(403, $this->status(self::MISSING, null));
    }

    public function test_an_ordinary_member_cannot_read_a_forums_traffic()
    {
        // Signed in is not the same as trusted. What language a forum's visitors ask for and which
        // countries they come from is not a member's business, and this is the assertion that says
        // an authenticated request is checked rather than merely authenticated.
        $this->assertSame(403, $this->status(self::STATISTICS, 2));
        $this->assertSame(403, $this->status(self::MISSING, 2));
    }

    public function test_an_administrator_can()
    {
        $this->assertSame(200, $this->status(self::STATISTICS, 1));
        $this->assertSame(200, $this->status(self::MISSING, 1));
    }

    public function test_the_payload_carries_every_section_the_dashboard_draws()
    {
        $payload = $this->payload(self::STATISTICS);

        $this->assertSame(['days', 'summary', 'languages', 'countries', 'trend'], array_keys($payload));
        $this->assertSame(30, $payload['days']);
        $this->assertSame(
            ['requests', 'visitors', 'languages', 'countries', 'served', 'unserved', 'unstated'],
            array_keys($payload['summary'])
        );
    }

    public function test_it_adds_up_a_language_across_several_days()
    {
        $this->seed([
            [$this->daysAgo(0), 'ja', 10, 4],
            [$this->daysAgo(1), 'ja', 20, 8],
            [$this->daysAgo(3), 'ja', 11, 3],
        ]);

        $payload = $this->payload(self::STATISTICS);

        $this->assertSame(41, $payload['summary']['requests']);
        $this->assertSame(15, $payload['summary']['visitors']);
        $this->assertSame(41, $payload['languages'][0]['requests']);

        // `SUM()` arrives from PDO as a string, and JSON has no way to hide it: shipped uncast the
        // payload would read `"requests": "41"`, and a JavaScript sort would rank `"9"` above it.
        $this->assertIsInt($payload['summary']['requests']);
        $this->assertIsInt($payload['languages'][0]['requests']);
        $this->assertIsInt($payload['languages'][0]['visitors']);
    }

    public function test_it_says_which_languages_the_forum_could_actually_serve()
    {
        $this->install('tr');

        $this->seed([
            [$this->daysAgo(0), 'tr', 100, 40],
            [$this->daysAgo(0), 'ja', 30, 10],
        ]);

        $payload = $this->payload(self::STATISTICS);

        $this->assertSame(['tr', 'ja'], array_column($payload['languages'], 'locale'));
        $this->assertTrue($payload['languages'][0]['served']);
        $this->assertFalse($payload['languages'][1]['served']);
        $this->assertSame('Japanese', $payload['languages'][1]['name']);

        $this->assertSame(100, $payload['summary']['served']);
        $this->assertSame(30, $payload['summary']['unserved']);
    }

    public function test_visitors_who_stated_no_preference_are_counted_but_not_called_a_language()
    {
        $this->seed([
            [$this->daysAgo(0), '', 70, 25],
            [$this->daysAgo(0), 'ja', 20, 8],
            [$this->daysAgo(0), 'de', 10, 4],
        ]);

        $payload = $this->payload(self::STATISTICS);

        // Three rows in the table, because the empty bucket is real traffic and usually the
        // biggest thing on the page.
        $this->assertCount(3, $payload['languages']);
        $this->assertSame('', $payload['languages'][0]['locale']);
        $this->assertNull($payload['languages'][0]['served']);

        // Two languages in the summary, though. `COUNT(DISTINCT locale)` counts `''` as a value,
        // which is why the count is taken from the rows that named a language instead.
        $this->assertSame(2, $payload['summary']['languages']);

        // And the split accounts for all hundred requests, with nothing quietly dropped.
        $this->assertSame(70, $payload['summary']['unstated']);
        $this->assertSame(100, $payload['summary']['requests']);
    }

    public function test_an_unplaceable_visitor_is_a_country_row_but_not_a_country()
    {
        $this->seed([
            [$this->daysAgo(0), 'ja', 90, 40, ''],
            [$this->daysAgo(0), 'ja', 10, 4, 'JP'],
        ]);

        $payload = $this->payload(self::STATISTICS);

        $this->assertSame(['', 'JP'], array_column($payload['countries'], 'country'));
        $this->assertSame(1, $payload['summary']['countries']);
    }

    public function test_the_trend_is_one_entry_per_day_oldest_first_with_the_quiet_days_filled_in()
    {
        $this->seed([
            [$this->daysAgo(6), 'ja', 10, 4],
            [$this->daysAgo(0), 'ja', 30, 12],
        ]);

        $trend = $this->payload(self::STATISTICS, ['days' => 7])['trend'];

        $this->assertCount(7, $trend);
        $this->assertSame($this->daysAgo(6), $trend[0]['date']);
        $this->assertSame($this->daysAgo(0), $trend[6]['date']);
        $this->assertSame([10, 0, 0, 0, 0, 0, 30], array_column($trend, 'requests'));
    }

    public function test_a_window_leaves_out_the_days_before_it()
    {
        $this->seed([
            [$this->daysAgo(0), 'ja', 10, 4],
            [$this->daysAgo(10), 'ja', 100, 40],
        ]);

        $this->assertSame(10, $this->payload(self::STATISTICS, ['days' => 7])['summary']['requests']);
        $this->assertSame(110, $this->payload(self::STATISTICS, ['days' => 30])['summary']['requests']);
    }

    public function test_both_endpoints_agree_on_where_seven_days_starts()
    {
        // The two window calculations live in different classes -- `Statistics::span()` and
        // `LanguageCatalog::missing()` -- and this is what stops them drifting apart. A dashboard
        // whose seven-day chart and seven-day missing-languages table covered different weeks would
        // be wrong in a way nobody would think to check by hand.
        $this->seed([
            [$this->daysAgo(6), 'ja', 10, 4],
            [$this->daysAgo(7), 'ja', 100, 40],
        ]);

        $this->assertSame(10, $this->payload(self::STATISTICS, ['days' => 7])['summary']['requests']);
        $this->assertSame(10, $this->payload(self::MISSING, ['days' => 7])['missing'][0]['requests']);
    }

    public function test_a_window_nobody_offered_falls_back_to_thirty_days()
    {
        // A whitelist, not a clamp, so `999` is not ninety and `abc` is not an error. Both are the
        // default, and the trend length is the assertion that the number reached the arithmetic
        // rather than merely being echoed back in the payload.
        foreach ([999, 0, -7, 'abc', ''] as $days) {
            $payload = $this->payload(self::STATISTICS, ['days' => $days]);

            $this->assertSame(30, $payload['days'], "days: '$days'");
            $this->assertCount(30, $payload['trend'], "days: '$days'");
        }
    }

    public function test_the_missing_report_lists_what_to_install()
    {
        $this->install('tr');

        $this->seed([
            [$this->daysAgo(0), 'tr', 900, 400],
            [$this->daysAgo(0), 'ja', 41, 15],
        ]);

        $payload = $this->payload(self::MISSING);

        $this->assertSame(['days', 'missing'], array_keys($payload));
        $this->assertSame(30, $payload['days']);

        // Turkish is installed, so it is not missing however much of the traffic it is.
        $this->assertCount(1, $payload['missing']);
        $this->assertSame('ja', $payload['missing'][0]['locale']);
        $this->assertSame('flarum-lang/japanese', $payload['missing'][0]['package']);
        $this->assertSame(['ja'], $payload['missing'][0]['tags']);
    }

    public function test_an_empty_table_is_a_payload_of_zeros_and_not_an_error()
    {
        // A forum that switched analytics on this morning, which is every forum on its first day.
        // Nulls here would render as "null" on the cards and divide by zero in the chart.
        $payload = $this->payload(self::STATISTICS);

        $this->assertSame([0], array_unique(array_values($payload['summary'])));
        $this->assertSame([], $payload['languages']);
        $this->assertSame([], $payload['countries']);
        $this->assertCount(30, $payload['trend']);

        $this->assertSame([], $this->payload(self::MISSING)['missing']);
    }

    /**
     * The decoded body of a successful request as the administrator.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    protected function payload(string $path, array $query = []): array
    {
        $response = $this->send($this->get($path, 1, $query));

        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * The status code of a request as a given user, or as a guest when `$as` is null.
     */
    protected function status(string $path, ?int $as): int
    {
        return $this->send($this->get($path, $as))->getStatusCode();
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function get(string $path, ?int $as, array $query = []): ServerRequestInterface
    {
        $request = $this->request('GET', $path, $as === null ? [] : ['authenticatedAs' => $as]);

        // Set explicitly rather than appended to the path. `TestCase::request()` builds a
        // `ServerRequest` with its `$queryParams` argument left at `[]`, so a `?days=7` in the path
        // would reach the URI and never reach `getQueryParams()` -- the controller would silently
        // see the default and every window assertion here would pass for the wrong reason. In
        // production the params are populated by `ServerRequestFactory::fromGlobals()`, which is
        // what `Http\Server` uses.
        return $query === [] ? $request : $request->withQueryParams($query);
    }

    /**
     * Register locales as installed, as a language pack would.
     *
     * `LocaleManager` is a container singleton, so this is what `LocaleMatcher` sees when the
     * request is handled -- and therefore what decides the `served` column.
     */
    protected function install(string ...$codes): void
    {
        $locales = $this->app()->getContainer()->make(LocaleManager::class);

        foreach ($codes as $code) {
            $locales->addLocale($code, $code);
        }
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
                // Part of the table's unique key, so every seeded row needs one.
                'country_code'    => $row[4] ?? '',
            ];
        }

        $this->database()->table(self::TABLE)->insert($insert);
    }

    /**
     * A date `$days` before today, read from the same clock the controllers read.
     */
    protected function daysAgo(int $days): string
    {
        $this->app();

        return Carbon::now()->subDays($days)->toDateString();
    }
}
