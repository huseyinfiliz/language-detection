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
use Dflydev\FigCookies\SetCookie;
use Flarum\Locale\LocaleManager;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What ends up in `language_detection_stats` after real requests through the real stack.
 *
 * `AnalyticsTest` covers the decisions; this covers the two things only a database can
 * demonstrate -- that the unique index collapses repeat views onto one row instead of
 * accumulating duplicates, and that `on duplicate key update` increments what is already there.
 * Rows are read back through the query builder rather than through anything the extension
 * provides, so nothing the code believes about its own writes can flatter the result.
 *
 * Every assertion counts rows as well as reading them. A test that only checked `requests` would
 * pass just as happily against a table quietly filling up with near-duplicate rows.
 */
class StatisticsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    const TABLE = 'language_detection_stats';

    /**
     * The day cookie as it appears on the wire, spelled out rather than derived from
     * `CookieFactory` -- the same reasoning as `DetectionTest::COOKIE`: a test that asked the
     * same code the middleware asks could not notice the name changing.
     */
    const DAY_COOKIE = 'flarum_language_detection_day';

    /**
     * And the memo cookie, needed here only to assert that detection still happens when
     * counting does not.
     *
     * Spelled out a second time rather than referenced from `DetectionTest`, because
     * `tests/integration/` is not on the autoloader -- the namespace segment is `Integration`
     * and the directory is `integration` -- and PHPUnit loads each test file on its own.
     */
    const LOCALE_COOKIE = 'flarum_language_detection_locale';

    protected bool $booted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('huseyinfiliz-language-detection');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
            ],
        ]);

        // Nothing here may touch `app()`: booting makes the harness's `setting()` a no-op, and
        // two tests below need a setting in place before the first request. `send()` boots.
    }

    /**
     * @see DetectionTest::send() -- the same deferred boot, for the same reason
     */
    protected function send(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->booted) {
            $this->booted = true;

            // Turkish only, and no German: the gap between what is asked for and what is
            // installed is the thing this table exists to record, so the fixture has to have
            // one.
            $this->localeManager()->addLocale('tr', 'Türkçe');
        }

        return parent::send($request);
    }

    public function test_one_page_view_writes_one_row()
    {
        $response = $this->send($this->request('GET', '/')->withHeader('Accept-Language', 'tr'));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame(1, $this->rowCount());

        $row = $this->row();

        $this->assertSame($this->today(), (string) $row->date);
        $this->assertSame('tr', $row->locale);
        // No CDN header and `127.0.0.1` from `ProcessIp` -- the harness sends no REMOTE_ADDR --
        // so the country is genuinely unknown, and unknown is stored as the empty string.
        $this->assertSame('', $row->country_code);
        $this->assertEquals(1, $row->requests);
        $this->assertEquals(1, $row->unique_visitors);

        // The visitor is now marked as counted, with today's date and nothing else.
        $this->assertSame($this->today(), $this->dayCookie($response));
    }

    public function test_a_second_view_by_the_same_visitor_is_a_second_request_on_the_same_row()
    {
        // The assertion the unique index and `on duplicate key update` exist for. The header is
        // resent deliberately: without it the second view would record the empty locale, land on
        // a different row, and prove nothing about incrementing.
        $first = $this->send($this->request('GET', '/')->withHeader('Accept-Language', 'tr'));

        $second = $this->send(
            $this->request('GET', '/', ['cookiesFrom' => $first])->withHeader('Accept-Language', 'tr')
        );

        $this->assertEquals(200, $second->getStatusCode());

        // One row, not two -- which is the half of this that a `requests` assertion alone
        // would not catch.
        $this->assertSame(1, $this->rowCount());

        $row = $this->row();

        $this->assertEquals(2, $row->requests);

        // Two views, one visitor: the day cookie from the first response is what says so.
        $this->assertEquals(1, $row->unique_visitors);

        // And no second cookie, since there is nothing new to mark.
        $this->assertNull($this->dayCookie($second));
    }

    public function test_a_visitor_carrying_yesterdays_cookie_is_a_new_visitor_again()
    {
        // Unique visitors are counted per day, so the cookie has to stop counting as proof once
        // its date is not today's. Yesterday's date stands in for a visitor who was last here
        // before midnight.
        $response = $this->send(
            $this->request('GET', '/')
                ->withHeader('Accept-Language', 'tr')
                ->withCookieParams([self::DAY_COOKIE => $this->yesterday()])
        );

        $this->assertSame(1, $this->rowCount());
        $this->assertEquals(1, $this->row()->unique_visitors);
        $this->assertSame($this->today(), $this->dayCookie($response));
    }

    public function test_a_junk_day_cookie_is_ignored_rather_than_trusted()
    {
        // Cookies come from the client. A value that is not a date is discarded and the visitor
        // counted, which is the safe direction: the alternative would let a forged cookie
        // suppress a real visitor.
        $response = $this->send(
            $this->request('GET', '/')
                ->withHeader('Accept-Language', 'tr')
                ->withCookieParams([self::DAY_COOKIE => 'not-a-date'])
        );

        $this->assertSame(1, $this->rowCount());
        $this->assertEquals(1, $this->row()->unique_visitors);
        $this->assertSame($this->today(), $this->dayCookie($response));
    }

    public function test_a_view_records_the_country_the_edge_reports()
    {
        $response = $this->send(
            $this->request('GET', '/')
                ->withHeader('Accept-Language', 'tr')
                ->withHeader('CF-IPCountry', 'TR')
        );

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame(1, $this->rowCount());

        $row = $this->row();

        $this->assertSame('TR', $row->country_code);
        $this->assertSame('tr', $row->locale);
    }

    public function test_the_same_language_from_two_countries_is_two_rows()
    {
        // `country_code` is part of the key, so this is what makes "Turkish read from Germany"
        // a thing the table can distinguish from "Turkish read from Turkey" -- and it is the
        // distinction the country column is carried for.
        $this->send(
            $this->request('GET', '/')
                ->withHeader('Accept-Language', 'tr')
                ->withHeader('CF-IPCountry', 'TR')
        );

        $this->send(
            $this->request('GET', '/')
                ->withHeader('Accept-Language', 'tr')
                ->withHeader('CF-IPCountry', 'DE')
        );

        $this->assertSame(2, $this->rowCount());
        $this->assertEquals(1, $this->rowFor('tr', 'TR')->requests);
        $this->assertEquals(1, $this->rowFor('tr', 'DE')->requests);
    }

    public function test_a_request_for_an_uninstalled_language_is_still_recorded()
    {
        // The single most valuable row in the table. This forum has no Japanese, so the visitor
        // is served the forum default -- and the request for `ja` is recorded anyway. That gap
        // between requested and installed is what `LanguageCatalog` reads to tell an admin which
        // language pack would be worth adding. Had the resolved locale been recorded instead, the
        // statistic could only ever report languages the forum already has.
        $response = $this->send($this->request('GET', '/')->withHeader('Accept-Language', 'ja'));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame(1, $this->rowCount());

        $row = $this->row();

        $this->assertSame('ja', $row->locale);
        $this->assertEquals(1, $row->requests);

        // Nothing was applied -- no memo cookie -- and yet the view was counted. The two are
        // independent on purpose.
        $this->assertNull($this->localeCookie($response));
    }

    public function test_a_visitor_who_asks_for_nothing_is_recorded_under_an_empty_locale()
    {
        // The harness sends no `Accept-Language` of its own, so this is simply a bare request.
        $this->send($this->request('GET', '/'));

        $this->assertSame(1, $this->rowCount());

        $row = $this->row();

        $this->assertSame('', $row->locale);
        $this->assertEquals(1, $row->requests);
        $this->assertEquals(1, $row->unique_visitors);
    }

    public function test_a_regional_tag_is_recorded_as_asked_for_and_not_as_resolved()
    {
        // `tr-TR` resolves to the installed `tr` by truncation, and the page comes back Turkish
        // -- but the row says `tr-tr`, lowercased. Which the visitor asked for and which the
        // forum could serve are different questions, and this table answers the first.
        $response = $this->send($this->request('GET', '/')->withHeader('Accept-Language', 'tr-TR'));

        $this->assertStringContainsString('lang="tr"', (string) $response->getBody());
        $this->assertSame('tr-tr', $this->row()->locale);
    }

    public function test_a_signed_in_member_is_counted_like_anyone_else()
    {
        // Counting does not depend on detection having anything to do. A member is a page view,
        // and their `Accept-Language` still says which language they would have asked for.
        $this->send(
            $this->request('GET', '/', ['authenticatedAs' => 2])->withHeader('Accept-Language', 'tr')
        );

        $this->assertSame(1, $this->rowCount());
        $this->assertEquals(1, $this->row()->requests);
    }

    public function test_a_bot_is_not_counted_when_bots_are_ignored()
    {
        // `ignore_bots` ships as `'1'`, so this needs no `setting()` call. The crawler still
        // gets its page -- and, if a language matched, still gets it in that language.
        $response = $this->send(
            $this->request('GET', '/')
                ->withHeader('Accept-Language', 'tr')
                ->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
        );

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame(0, $this->rowCount());

        // Nor is it marked as counted -- a cookie saying "counted today" when nothing was
        // counted would suppress a real first view.
        $this->assertNull($this->dayCookie($response));

        // But the language it asked for was still applied, which is the point of keeping the bot
        // check out of detection: search engines index what they are served.
        $this->assertStringContainsString('lang="tr"', (string) $response->getBody());
    }

    public function test_a_bot_is_counted_when_the_admin_has_not_asked_to_ignore_them()
    {
        $this->setting('huseyinfiliz-language-detection.ignore_bots', '0');

        $response = $this->send(
            $this->request('GET', '/')
                ->withHeader('Accept-Language', 'tr')
                ->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
        );

        $this->assertSame(1, $this->rowCount());
        $this->assertSame($this->today(), $this->dayCookie($response));
    }

    public function test_nothing_is_recorded_when_analytics_are_switched_off()
    {
        $this->setting('huseyinfiliz-language-detection.enable_analytics', '0');

        $response = $this->send($this->request('GET', '/')->withHeader('Accept-Language', 'tr'));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame(0, $this->rowCount());
        $this->assertNull($this->dayCookie($response));

        // Detection is a separate setting and keeps working, so an admin who wants the feature
        // without the statistics gets exactly that.
        $this->assertStringContainsString('lang="tr"', (string) $response->getBody());
        $this->assertSame('tr', $this->localeCookie($response));
    }

    public function test_a_post_is_not_counted()
    {
        // Not a page view, so the `GET` guard returns before counting.
        //
        // The route matters, and `POST /` would have made this test unfalsifiable. With no route to
        // resolve, the exception unwinds past this middleware before the response exists, so
        // nothing would be counted whether the guard were there or not. `/global-logout` is a real
        // forum POST route whose controller returns an `EmptyResponse`, and `requestAsUser()` sets
        // `bypassCsrfToken`, so the request completes normally. Remove the guard and this genuinely
        // writes a row.
        $this->send($this->request('POST', '/global-logout', ['authenticatedAs' => 2]));

        $this->assertSame(0, $this->rowCount());
    }

    public function test_no_row_holds_anything_that_identifies_the_visitor()
    {
        // The privacy promise, checked against the table itself rather than against the code
        // that writes to it. A request carrying every identifying header worth carrying leaves
        // behind seven columns, and none of them is any of those headers.
        $this->send(
            $this->request('GET', '/')
                ->withHeader('Accept-Language', 'tr')
                ->withHeader('CF-IPCountry', 'TR')
                ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0')
                ->withHeader('Referer', 'https://example.test/somewhere')
        );

        $this->assertSame(
            ['country_code', 'created_at', 'date', 'id', 'locale', 'requests', 'unique_visitors', 'updated_at'],
            $this->columns()
        );
    }

    /**
     * Today, as the application sees it.
     *
     * The date has to be read from the same clock the middleware reads, so it is read from
     * `Carbon` -- and read after the app exists, because boot is the one thing that could move
     * the process timezone out from under the test. This harness happens not to: it builds
     * `InstalledSite` directly, while it is the web entry point's `Site::fromPaths()` that calls
     * `date_default_timezone_set('UTC')`. Asking `app()` first costs nothing and makes the test
     * independent of which of those two paths does what.
     */
    protected function today(): string
    {
        $this->app();

        return Carbon::now()->toDateString();
    }

    protected function yesterday(): string
    {
        $this->app();

        return Carbon::now()->subDay()->toDateString();
    }

    /**
     * How many rows the table holds.
     */
    protected function rowCount(): int
    {
        return $this->database()->table(self::TABLE)->count();
    }

    /**
     * The only row in the table.
     */
    protected function row(): object
    {
        $this->assertSame(1, $this->rowCount(), 'expected exactly one row');

        return $this->database()->table(self::TABLE)->first();
    }

    protected function rowFor(string $locale, string $country): object
    {
        $row = $this->database()->table(self::TABLE)
            ->where('locale', $locale)
            ->where('country_code', $country)
            ->first();

        $this->assertNotNull($row, "no row for '$locale' from '$country'");

        return $row;
    }

    /**
     * The column names actually present on the stored row, sorted so the assertion does not
     * depend on column order.
     *
     * @return string[]
     */
    protected function columns(): array
    {
        $columns = array_keys((array) $this->row());

        sort($columns);

        return $columns;
    }

    protected function dayCookie(ResponseInterface $response): ?string
    {
        return $this->cookie($response, self::DAY_COOKIE);
    }

    protected function localeCookie(ResponseInterface $response): ?string
    {
        return $this->cookie($response, self::LOCALE_COOKIE);
    }

    protected function cookie(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $cookie = SetCookie::fromSetCookieString($header);

            if ($cookie->getName() === $name) {
                return $cookie->getValue();
            }
        }

        return null;
    }

    protected function localeManager(): LocaleManager
    {
        return $this->app()->getContainer()->make(LocaleManager::class);
    }
}
