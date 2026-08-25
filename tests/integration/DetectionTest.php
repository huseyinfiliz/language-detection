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

use Dflydev\FigCookies\SetCookie;
use Flarum\Locale\LocaleManager;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class DetectionTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /**
     * The memo cookie as it appears on the wire, spelled out rather than derived from
     * `CookieFactory`: the name is part of what this extension promises, and a test that
     * asked the same code the middleware asks could not notice it changing.
     */
    const COOKIE = 'flarum_language_detection_locale';

    /**
     * The locale the forum falls back to when nothing is detected.
     */
    protected string $forumDefault;

    protected bool $booted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('huseyinfiliz-language-detection');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                $this->userWhoChoseGerman(),
            ],
        ]);

        // Deliberately nothing here that touches `app()`: booting is what makes the harness's
        // `setting()` a no-op, so a test that needs to configure a setting has to be able to
        // do it before the first request. `send()` boots instead, on first use.
    }

    /**
     * Reading `localeManager()` boots the app, so everything that must happen before boot has
     * to have happened by now -- which is why this hangs off the first request rather than off
     * `setUp()`.
     */
    protected function send(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->booted) {
            $this->booted = true;

            // Reading the forum's own default instead of assuming `en` keeps the fallback
            // assertions true whatever the test installation is configured with.
            $this->forumDefault = $this->localeManager()->getLocale();

            // The test forum registers only its default locale, and `Extend\LanguagePack`
            // needs a real installed extension, so the locales these tests need are added
            // straight to the LocaleManager -- the same `getLocales()` keys a language pack
            // would add. No translations come with them, so Symfony serves English strings for
            // both; that is why every assertion below is about the document's `lang`
            // attribute, which reports the locale that was actually applied, rather than about
            // translated text.
            $this->localeManager()->addLocale('tr', 'Türkçe');
            $this->localeManager()->addLocale('de', 'Deutsch');
        }

        return parent::send($request);
    }

    public function test_a_guest_is_served_the_language_their_browser_asks_for()
    {
        $response = $this->send(
            $this->request('GET', '/')->withHeader('Accept-Language', 'tr-TR,tr;q=0.9,en;q=0.4')
        );

        $this->assertEquals(200, $response->getStatusCode());

        // Turkish is neither the forum default nor an exact match for the header's first
        // tag, so nothing but detection can have produced it.
        $this->assertStringContainsString('lang="tr"', (string) $response->getBody());
        $this->assertSame('tr', $this->memo($response));
    }

    public function test_a_guest_asking_for_an_uninstalled_language_keeps_the_forum_default()
    {
        $response = $this->send(
            $this->request('GET', '/')->withHeader('Accept-Language', 'ja')
        );

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('lang="'.$this->forumDefault.'"', (string) $response->getBody());

        // Nothing is remembered, so a Japanese pack installed tomorrow is picked up on the
        // visitor's next page view rather than never.
        $this->assertNull($this->memo($response));
    }

    public function test_a_guest_who_was_already_detected_is_served_the_remembered_language()
    {
        // The cookie stands in for a first visit that happened earlier. Sending no
        // `Accept-Language` at all is what makes this conclusive: the memo is the only
        // place Turkish can come from.
        $response = $this->send(
            $this->request('GET', '/')->withCookieParams([self::COOKIE => 'tr'])
        );

        $this->assertStringContainsString('lang="tr"', (string) $response->getBody());

        // And detection does not run again, so the memo is not rewritten on every view.
        $this->assertNull($this->memo($response));
    }

    public function test_a_guest_carrying_a_locale_that_is_not_installed_is_detected_afresh()
    {
        $response = $this->send(
            $this->request('GET', '/')
                ->withCookieParams([self::COOKIE => 'xx'])
                ->withHeader('Accept-Language', 'tr')
        );

        // A tampered or stale memo never reaches `setLocale()`; it is discarded and the
        // header decides instead.
        $this->assertStringContainsString('lang="tr"', (string) $response->getBody());
        $this->assertSame('tr', $this->memo($response));
    }

    public function test_the_memo_a_first_visit_writes_is_the_one_a_second_visit_reads()
    {
        $first = $this->send($this->request('GET', '/')->withHeader('Accept-Language', 'tr'));

        // No `Accept-Language` this time, and the cookies come from the real first
        // response. If the name the cookie is written under ever stopped matching the name
        // it is read back under, detection would run again -- and leave a second memo.
        $second = $this->send($this->request('GET', '/', ['cookiesFrom' => $first]));

        $this->assertSame('tr', $this->memo($first));
        $this->assertNull($this->memo($second));

        // Deliberately no `lang` assertion on the second response: LocaleManager is a
        // container singleton and nothing resets the translator between two requests in one
        // test, so its `lang` would read `tr` even if this middleware had done nothing.
        // The absent second memo is the real evidence, and
        // `test_a_guest_who_was_already_detected_is_served_the_remembered_language` covers
        // applying a memo from a clean start.
    }

    public function test_a_user_who_chose_a_language_is_never_overridden()
    {
        $response = $this->send(
            $this->request('GET', '/', ['authenticatedAs' => 3])->withHeader('Accept-Language', 'tr')
        );

        $body = (string) $response->getBody();

        // Their choice outranks the header...
        $this->assertStringContainsString('lang="de"', $body);
        $this->assertStringNotContainsString('lang="tr"', $body);

        // ...the preference they chose is left exactly as it was...
        $this->assertSame('de', $this->storedLocale(3));

        // ...and nothing is remembered on their behalf.
        $this->assertNull($this->memo($response));
    }

    public function test_a_user_with_no_language_preference_has_one_detected_and_stored()
    {
        $response = $this->send(
            $this->request('GET', '/', ['authenticatedAs' => 2])->withHeader('Accept-Language', 'tr-TR')
        );

        // Applied to the page they are loading now, not just to the next one.
        $this->assertStringContainsString('lang="tr"', (string) $response->getBody());

        // Stored as the installed locale key rather than as the tag the browser sent.
        $this->assertSame('tr', $this->storedLocale(2));

        // A signed-in visitor's language lives in their preferences, so no cookie is
        // written for them.
        $this->assertNull($this->memo($response));
    }

    public function test_a_guest_whose_browser_asks_for_nothing_is_served_their_countrys_language()
    {
        // The whole point of the second source: no `Accept-Language` at all -- the case a
        // browser-only extension can do nothing with -- and a country from the edge.
        $response = $this->send(
            $this->request('GET', '/')->withHeader('CF-IPCountry', 'TR')
        );

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('lang="tr"', (string) $response->getBody());
        $this->assertSame('tr', $this->memo($response));
    }

    public function test_a_country_with_no_installed_language_keeps_the_forum_default()
    {
        // Japan maps to `ja` alone, which this forum does not have. Nothing is invented and
        // nothing is remembered -- the same shape as the unmatched-header case.
        $response = $this->send(
            $this->request('GET', '/')->withHeader('CF-IPCountry', 'JP')
        );

        $this->assertStringContainsString('lang="'.$this->forumDefault.'"', (string) $response->getBody());
        $this->assertNull($this->memo($response));
    }

    public function test_a_country_header_that_is_not_a_country_detects_nothing()
    {
        // `XX` is Cloudflare's "could not place this address", and the connecting address is
        // `127.0.0.1` here -- ProcessIp's own default, since the harness sends no REMOTE_ADDR.
        // So this asserts both halves of the lookup declining at once.
        $response = $this->send(
            $this->request('GET', '/')->withHeader('CF-IPCountry', 'XX')
        );

        $this->assertStringContainsString('lang="'.$this->forumDefault.'"', (string) $response->getBody());
        $this->assertNull($this->memo($response));
    }

    public function test_the_browser_outranks_the_country_by_default()
    {
        // `browser_ip` is the shipped default, so this needs no `setting()` call: a German
        // browser from a Turkish address is served German. Their browser is a statement of
        // preference; their address is an inference.
        $response = $this->send(
            $this->request('GET', '/')
                ->withHeader('Accept-Language', 'de')
                ->withHeader('CF-IPCountry', 'TR')
        );

        $this->assertStringContainsString('lang="de"', (string) $response->getBody());
        $this->assertSame('de', $this->memo($response));
    }

    public function test_the_country_outranks_the_browser_when_the_setting_says_so()
    {
        // The first assertion in this suite that `detection_order` does anything. It is also
        // the reason `setUp()` no longer touches `app()`: `setting()` is seeded into the
        // container before boot, and has no effect once the app is up.
        $this->setting('huseyinfiliz-language-detection.detection_order', 'ip_browser');

        $response = $this->send(
            $this->request('GET', '/')
                ->withHeader('Accept-Language', 'de')
                ->withHeader('CF-IPCountry', 'TR')
        );

        // Byte for byte the same request as the test above, and a different answer.
        $this->assertStringContainsString('lang="tr"', (string) $response->getBody());
        $this->assertSame('tr', $this->memo($response));
    }

    public function test_the_browser_still_answers_under_ip_first_when_the_country_cannot()
    {
        // Ordering must not mean exclusivity: a country with no installed language has to
        // fall through to the browser rather than end detection.
        $this->setting('huseyinfiliz-language-detection.detection_order', 'ip_browser');

        $response = $this->send(
            $this->request('GET', '/')
                ->withHeader('Accept-Language', 'de')
                ->withHeader('CF-IPCountry', 'JP')
        );

        $this->assertStringContainsString('lang="de"', (string) $response->getBody());
        $this->assertSame('de', $this->memo($response));
    }

    public function test_a_country_never_overrides_a_language_someone_chose()
    {
        // The rule the extension answers to, restated for the source that did not exist when
        // it was first tested. German is this member's own choice; Turkey is an inference from
        // a header, and loses to it under either order.
        $this->setting('huseyinfiliz-language-detection.detection_order', 'ip_browser');

        $response = $this->send(
            $this->request('GET', '/', ['authenticatedAs' => 3])->withHeader('CF-IPCountry', 'TR')
        );

        $body = (string) $response->getBody();

        $this->assertStringContainsString('lang="de"', $body);
        $this->assertStringNotContainsString('lang="tr"', $body);
        $this->assertSame('de', $this->storedLocale(3));
        $this->assertNull($this->memo($response));
    }

    /**
     * The locale this extension remembered in its cookie, if it wrote one.
     */
    protected function memo(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $cookie = SetCookie::fromSetCookieString($header);

            if ($cookie->getName() === self::COOKIE) {
                return $cookie->getValue();
            }
        }

        return null;
    }

    /**
     * The `locale` preference as it is actually stored.
     *
     * Read back through the query builder rather than through Eloquent so that nothing an
     * in-memory model is holding can flatter the result.
     */
    protected function storedLocale(int $userId): ?string
    {
        $preferences = json_decode(
            (string) $this->database()->table('users')->where('id', $userId)->value('preferences'),
            true
        );

        // Saving one preference writes the whole merged set, every registered default
        // included, so this one key is the only thing worth asserting on.
        return is_array($preferences) ? ($preferences['locale'] ?? null) : null;
    }

    protected function localeManager(): LocaleManager
    {
        return $this->app()->getContainer()->make(LocaleManager::class);
    }

    /**
     * A member who has already chosen German -- the visitor whose choice must survive
     * everything this extension does.
     */
    protected function userWhoChoseGerman(): array
    {
        return array_merge($this->normalUser(), [
            'id' => 3,
            'username' => 'chose_german',
            'email' => 'chose_german@machine.local',
            'preferences' => json_encode(['locale' => 'de']),
        ]);
    }
}
