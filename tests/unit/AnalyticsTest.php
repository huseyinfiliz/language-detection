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
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\unit\TestCase;
use HuseyinFiliz\LanguageDetection\Analytics;
use HuseyinFiliz\LanguageDetection\BotDetector;
use HuseyinFiliz\LanguageDetection\BrowserLanguageParser;
use HuseyinFiliz\LanguageDetection\IpCountryLookup;
use HuseyinFiliz\LanguageDetection\LanguageDetector;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Expression;
use Laminas\Diactoros\ServerRequest;
use Mockery;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What `Analytics` decides, not what MySQL does with it.
 *
 * The decisions are all here -- whether to count at all, which language tag to record, which
 * country, whether the visitor is new -- and they are worth testing in isolation because each
 * of them is a judgement that could plausibly have gone the other way. What is deliberately not
 * tested here is the SQL: that the upsert increments atomically, and that the unique index
 * collapses two views into one row, are facts about MySQL and are only demonstrable against a
 * real one. `tests/integration/StatisticsTest.php` does that.
 *
 * The connection is doubled rather than faked, and in two shapes. Where a write is expected, a
 * spy captures the arguments `upsert()` was called with. Where no write is expected, the double
 * carries no expectations at all -- so any call to it fails the test, which makes "nothing was
 * written" an assertion rather than a hope.
 */
class AnalyticsTest extends TestCase
{
    /**
     * A fixed clock, so that the date on the row is a value this test chose.
     *
     * `Analytics` never reads a clock of its own -- the middleware owns it and hands it over --
     * which is what makes asserting on the date possible without freezing time globally.
     */
    const NOW = '2026-08-25 10:30:00';

    /**
     * The arguments the last `upsert()` call received: `[$values, $uniqueBy, $update]`.
     */
    protected ?array $upsert = null;

    public function test_it_records_the_language_the_visitor_asked_for()
    {
        $analytics = $this->analytics();

        $this->assertTrue($analytics->record($this->request('tr'), true, $this->now()));

        $this->assertSame('tr', $this->values()['locale']);
    }

    public function test_it_records_the_most_preferred_tag_rather_than_the_shortest()
    {
        // The parser has already sorted by quality value, so the first tag it returns is the
        // one the visitor most wanted -- and `tr-TR` rather than `tr` is what they asked for.
        // Recording the region carries information the shorter tag does not: it separates
        // Turkish read in Turkey from Turkish read in Germany.
        $this->analytics()->record($this->request('tr-TR,tr;q=0.9,en;q=0.4'), true, $this->now());

        $this->assertSame('tr-tr', $this->values()['locale']);
    }

    public function test_it_folds_case_and_underscores_so_one_language_is_one_row()
    {
        // `tr-TR`, `tr_TR` and `TR-tr` are the same request written three ways. Left alone they
        // would be three rows, and a report that split one language across them would be
        // wrong in a way an admin could not see.
        foreach (['tr-TR', 'tr_TR', 'TR-tr', 'tr-tr'] as $tag) {
            $this->analytics()->record($this->request($tag), true, $this->now());

            $this->assertSame('tr-tr', $this->values()['locale'], "tag: '$tag'");
        }
    }

    public function test_a_visitor_who_asked_for_nothing_is_still_counted()
    {
        // Recorded under the empty locale rather than skipped. A large `''` bucket is worth
        // seeing: it tells an admin that most of their traffic states no preference at all,
        // which is the difference between "nobody wants Turkish" and "nobody says".
        $this->analytics()->record($this->request(null), true, $this->now());

        $this->assertSame('', $this->values()['locale']);
    }

    public function test_a_header_with_nothing_usable_in_it_is_recorded_as_no_preference()
    {
        // The parser rejects malformed tags outright, so a header can arrive non-empty and
        // leave nothing behind. That is the same state as sending no header.
        $this->analytics()->record($this->request('*'), true, $this->now());

        $this->assertSame('', $this->values()['locale']);
    }

    public function test_an_over_long_tag_is_skipped_in_favour_of_the_next_one()
    {
        // The column is twenty bytes and the parser bounds each subtag but not the whole tag,
        // so an over-long tag is possible. It is dropped rather than cut to fit -- twenty bytes
        // of `zh-Hans-CN-x-aaaaaaaa` is `zh-Hans-CN-x-aaaaaaa`, which still looks like a
        // language tag and is not one. Preference order then does the rest: the visitor's
        // second choice is a real language they really asked for.
        $this->analytics()->record(
            $this->request('zh-Hans-CN-x-aaaaaaaa,tr;q=0.9'),
            true,
            $this->now()
        );

        $this->assertSame('tr', $this->values()['locale']);
    }

    public function test_a_visitor_whose_every_tag_is_over_long_is_recorded_as_no_preference()
    {
        $this->analytics()->record(
            $this->request('zh-Hans-CN-x-aaaaaaaa,zh-Hant-TW-x-bbbbbbbb;q=0.9'),
            true,
            $this->now()
        );

        $this->assertSame('', $this->values()['locale']);
    }

    public function test_it_records_the_country_the_view_came_from()
    {
        $this->analytics()->record(
            $this->request('tr')->withHeader('CF-IPCountry', 'TR'),
            true,
            $this->now()
        );

        $this->assertSame('TR', $this->values()['country_code']);
    }

    public function test_an_unplaceable_visitor_is_recorded_under_an_empty_country()
    {
        // Empty string rather than null, and not for tidiness: `country_code` is part of the
        // unique index, and MySQL counts NULLs in a unique index as distinct from one another.
        // A null here would mean every unknown-country view inserted its own row instead of
        // incrementing a shared one, and the table would grow without bound.
        $this->analytics()->record($this->request('tr'), true, $this->now());

        $this->assertSame('', $this->values()['country_code']);
    }

    public function test_it_records_the_date_from_the_clock_it_was_given()
    {
        // Not `today`, and not a clock of its own. The middleware reads `Carbon::now()` once per
        // request and passes it down precisely so that this is asserted here rather than
        // assumed: the date on the row and the date in the day cookie have to be the same
        // string, or a visitor arriving at midnight is counted twice.
        $this->analytics()->record($this->request('tr'), true, $this->now());

        $this->assertSame('2026-08-25', $this->values()['date']);
    }

    public function test_it_sets_the_timestamps_itself()
    {
        // The query builder maintains no timestamps -- that is Eloquent's job, and there is no
        // model here -- and the migration declares both columns nullable with no default. So
        // omitting them would leave every row with two nulls.
        $now = $this->now();

        $this->analytics()->record($this->request('tr'), true, $now);

        $this->assertSame($now, $this->values()['created_at']);
        $this->assertSame($now, $this->values()['updated_at']);
        $this->assertSame($now, $this->update()['updated_at']);
    }

    public function test_a_new_visitor_adds_a_view_and_a_visitor()
    {
        $this->assertTrue($this->analytics()->record($this->request('tr'), true, $this->now()));

        $this->assertSame(1, $this->values()['requests']);
        $this->assertSame(1, $this->values()['unique_visitors']);

        // And on the collision path, where the row already exists, the same two increments have
        // to be expressed as SQL rather than as values.
        $this->assertSame('requests + 1', (string) $this->update()['requests']);
        $this->assertSame('unique_visitors + 1', (string) $this->update()['unique_visitors']);
    }

    public function test_a_returning_visitor_adds_a_view_but_not_a_visitor()
    {
        // The one place the caller's answer changes the row. `false` here means the day cookie
        // said "already counted today", so the view counts and the visitor does not.
        $this->assertFalse($this->analytics()->record($this->request('tr'), false, $this->now()));

        $this->assertSame(1, $this->values()['requests']);
        $this->assertSame(0, $this->values()['unique_visitors']);

        $this->assertSame('requests + 1', (string) $this->update()['requests']);
        $this->assertSame('unique_visitors + 0', (string) $this->update()['unique_visitors']);
    }

    public function test_the_increments_are_raw_expressions_and_carry_no_bindings()
    {
        // Which is why the visitor count is interpolated into the string rather than bound: an
        // `Expression` is stripped out of the binding list, so there would be no placeholder
        // for a bound value to fill. What makes that safe is the cast in `increment()` -- the
        // interpolated value is an int that is 0 or 1 and cannot be anything else.
        $this->analytics()->record($this->request('tr'), true, $this->now());

        $this->assertInstanceOf(Expression::class, $this->update()['requests']);
        $this->assertInstanceOf(Expression::class, $this->update()['unique_visitors']);
    }

    public function test_the_update_is_keyed_by_column_name()
    {
        // A list of column names -- the numeric-key form of `$update` -- makes the query
        // builder call the deprecated `values()` on the row instead, and the increments would
        // be replaced by the literal `1` the insert carries. Every view would then write
        // `requests = 1` and nothing would ever accumulate.
        $this->analytics()->record($this->request('tr'), true, $this->now());

        $this->assertSame(['requests', 'unique_visitors', 'updated_at'], array_keys($this->update()));
    }

    public function test_it_declares_the_columns_the_upsert_collides_on()
    {
        // MySQL's grammar ignores this argument and compiles `on duplicate key update`, letting
        // the index decide. It is passed regardless, because it names the index the migration
        // has to have created for any of this to be an upsert at all.
        $this->analytics()->record($this->request('tr'), true, $this->now());

        $this->assertSame(['date', 'locale', 'country_code'], $this->uniqueBy());
    }

    public function test_it_writes_one_row_and_stores_nothing_that_identifies_anybody()
    {
        // The privacy promise, asserted as a whitelist rather than trusted to review. If a
        // column is ever added that holds an address, a hash, a user agent or a URL, this fails.
        $this->analytics()->record(
            $this->request('tr')
                ->withHeader('CF-IPCountry', 'TR')
                ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)')
                ->withHeader('Referer', 'https://example.test/somewhere'),
            true,
            $this->now()
        );

        $this->assertSame(
            ['date', 'locale', 'country_code', 'requests', 'unique_visitors', 'created_at', 'updated_at'],
            array_keys($this->values())
        );
    }

    public function test_it_records_nothing_when_analytics_are_switched_off()
    {
        // The connection double carries no expectations, so any query at all fails this test.
        // Switching analytics off has to cost one settings read and nothing else: no header
        // parsed, no address looked up, no row written, and -- because `record()` answers
        // `false` -- no day cookie either.
        $analytics = $this->analytics(['enable_analytics' => '0'], $this->forbiddenDb());

        $this->assertFalse($analytics->record($this->request('tr'), true, $this->now()));
    }

    public function test_it_records_nothing_for_a_bot_when_bots_are_ignored()
    {
        $analytics = $this->analytics([], $this->forbiddenDb());

        $this->assertFalse($analytics->record($this->botRequest(), true, $this->now()));
    }

    public function test_it_records_a_bot_when_the_admin_has_not_asked_to_ignore_them()
    {
        // `ignore_bots` is a setting rather than a rule, and an admin who turns it off is
        // asking for raw traffic.
        $analytics = $this->analytics(['ignore_bots' => '0']);

        $this->assertTrue($analytics->record($this->botRequest(), true, $this->now()));

        $this->assertSame('tr', $this->values()['locale']);
    }

    public function test_analytics_are_on_and_bots_ignored_before_any_setting_is_written()
    {
        // A fresh install runs on `extend.php`'s defaults, which are only written to the
        // settings table when something writes them. Until then both reads come back null, and
        // the fallbacks here have to agree with what the extension ships -- `LanguageDetector`
        // reads an unrecognised `detection_order` the same way.
        $this->assertTrue($this->analytics()->record($this->request('tr'), true, $this->now()));

        $this->assertFalse(
            $this->analytics([], $this->forbiddenDb())->record($this->botRequest(), true, $this->now())
        );
    }

    /**
     * The values `upsert()` was asked to insert.
     */
    protected function values(): array
    {
        $this->assertNotNull($this->upsert, 'nothing was written');

        return $this->upsert[0];
    }

    /**
     * The columns the upsert declares it collides on.
     */
    protected function uniqueBy(): array
    {
        $this->assertNotNull($this->upsert, 'nothing was written');

        return $this->upsert[1];
    }

    /**
     * What `upsert()` was asked to do when the row already exists.
     */
    protected function update(): array
    {
        $this->assertNotNull($this->upsert, 'nothing was written');

        return $this->upsert[2];
    }

    /**
     * @param array<string, string> $settings keys without the extension's settings prefix
     */
    protected function analytics(array $settings = [], ?ConnectionInterface $db = null): Analytics
    {
        // The parser, the lookup and the bot detector are real. None of them has a dependency
        // worth doubling, and doubling them would turn every assertion below into a claim about
        // which methods this class calls rather than about which row it writes. The lookup
        // points at the same small fixture dataset `IpCountryLookupTest` documents.
        return new Analytics(
            $db ?? $this->capturingDb(),
            new BrowserLanguageParser(),
            new IpCountryLookup(__DIR__.'/../fixtures/ip-dataset'),
            new BotDetector(),
            $this->settings($settings)
        );
    }

    /**
     * A connection that records the upsert instead of running it.
     */
    protected function capturingDb(): ConnectionInterface
    {
        $this->upsert = null;

        // Untyped, because a real `Query\Builder` cannot be constructed without a real
        // connection, grammar and processor -- and building those would be building a database.
        $builder = Mockery::mock();

        $builder->shouldReceive('upsert')->once()->andReturnUsing(function (...$arguments) {
            $this->upsert = $arguments;

            return 1;
        });

        $db = Mockery::mock(ConnectionInterface::class);

        // `with()` rather than a bare `shouldReceive`, so that writing to some other table
        // fails here rather than passing quietly.
        $db->shouldReceive('table')->with(Analytics::TABLE)->andReturn($builder);

        // Real expressions from the double, because what the increments compile to is the thing
        // being asserted -- a stub returning its argument would let a missing `raw()` pass.
        $db->shouldReceive('raw')->andReturnUsing(fn ($value) => new Expression($value));

        return $db;
    }

    /**
     * A connection that fails the test if it is touched at all.
     */
    protected function forbiddenDb(): ConnectionInterface
    {
        $this->upsert = null;

        // No expectations, so Mockery throws on any call. That is the whole assertion: there is
        // no query to count, because there is no query that would be allowed.
        return Mockery::mock(ConnectionInterface::class);
    }

    /**
     * @param array<string, string> $settings keys without the extension's settings prefix
     */
    protected function settings(array $settings): SettingsRepositoryInterface
    {
        $prefixed = [];

        foreach ($settings as $key => $value) {
            $prefixed[LanguageDetector::SETTINGS_PREFIX.$key] = $value;
        }

        // Mocked rather than stubbed, unusually for this suite, and only because
        // `LanguageDetectorTest` already declares a `SettingsStub` in the same namespace.
        // `tests/unit/` is not autoloadable -- the namespace segment is `Unit` and the
        // directory is `unit` -- so PHPUnit loads each test file whole, and a second
        // declaration of that class would be a fatal error rather than a duplicate.
        $repository = Mockery::mock(SettingsRepositoryInterface::class);

        // An unset key falls through to the caller's default, which is how a fresh install
        // behaves: `extend.php`'s defaults are not in the settings table until something
        // writes them, so `get()` really does answer null there.
        $repository->shouldReceive('get')->andReturnUsing(
            fn ($key, $default = null) => $prefixed[$key] ?? $default
        );

        return $repository;
    }

    protected function request(?string $acceptLanguage): ServerRequestInterface
    {
        $request = new ServerRequest([], [], '/', 'GET');

        return $acceptLanguage === null
            ? $request
            : $request->withHeader('Accept-Language', $acceptLanguage);
    }

    /**
     * A crawler asking for Turkish -- so that what changes between the ignore-bots cases is the
     * setting and nothing else.
     */
    protected function botRequest(): ServerRequestInterface
    {
        return $this->request('tr')->withHeader(
            'User-Agent',
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
        );
    }

    protected function now(): Carbon
    {
        return Carbon::parse(self::NOW);
    }
}
