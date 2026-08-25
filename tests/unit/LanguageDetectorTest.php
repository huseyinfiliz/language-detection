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

use Flarum\Locale\LocaleManager;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\unit\TestCase;
use HuseyinFiliz\LanguageDetection\BrowserLanguageParser;
use HuseyinFiliz\LanguageDetection\CountryLanguage;
use HuseyinFiliz\LanguageDetection\IpCountryLookup;
use HuseyinFiliz\LanguageDetection\LanguageDetector;
use HuseyinFiliz\LanguageDetection\LocaleMatcher;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

class LanguageDetectorTest extends TestCase
{
    public function test_it_detects_the_language_the_browser_asks_for()
    {
        $detector = $this->detector(['en', 'tr']);

        $this->assertSame('tr', $detector->detect($this->request('tr')));
    }

    public function test_it_hands_the_whole_header_to_the_parser()
    {
        // Not a re-test of the parser: this is the seam between them, and a header that
        // needs both quality ordering and truncation to come out as `tr` proves the real
        // header reaches the real parser rather than something pre-cleaned.
        $detector = $this->detector(['en', 'tr']);

        $this->assertSame('tr', $detector->detect($this->request('tr-TR,tr;q=0.9,en;q=0.4')));
    }

    public function test_an_unmatched_header_falls_back_to_the_configured_default()
    {
        $detector = $this->detector(['en', 'tr'], ['default_locale' => 'tr']);

        $this->assertSame('tr', $detector->detect($this->request('ja')));
    }

    public function test_a_matched_header_beats_the_configured_default()
    {
        $detector = $this->detector(['en', 'tr', 'de'], ['default_locale' => 'de']);

        $this->assertSame('tr', $detector->detect($this->request('tr')));
    }

    public function test_it_detects_nothing_when_no_default_is_configured()
    {
        // Null is the whole point: the forum's own default locale stays in charge, and the
        // caller leaves the request alone.
        $detector = $this->detector(['en', 'tr']);

        $this->assertNull($detector->detect($this->request('ja')));
    }

    public function test_a_default_that_is_not_installed_is_not_applied()
    {
        // Settings outlive language packs -- an admin can pick Turkish and later remove the
        // pack. Applying it then would hand `setLocale()` a locale with no catalogue.
        $detector = $this->detector(['en'], ['default_locale' => 'tr']);

        $this->assertNull($detector->detect($this->request('ja')));
    }

    public function test_a_missing_header_behaves_like_an_unmatched_one()
    {
        // Laminas answers `getHeaderLine()` with an empty string for a header that was
        // never sent, so this path must not depend on a null check anywhere.
        $this->assertNull($this->detector(['en', 'tr'])->detect($this->request(null)));

        $withDefault = $this->detector(['en', 'tr'], ['default_locale' => 'tr']);

        $this->assertSame('tr', $withDefault->detect($this->request(null)));
    }

    public function test_it_detects_nothing_when_no_locales_are_installed()
    {
        $detector = $this->detector([], ['default_locale' => 'tr']);

        $this->assertNull($detector->detect($this->request('tr')));
    }

    public function test_it_detects_the_country_the_address_is_in()
    {
        // The second source, end to end: an address in the fixture dataset, through the
        // country map, through the same matcher the browser source uses.
        $detector = $this->detector(['en', 'tr']);

        $this->assertSame('tr', $detector->detect($this->request(null, '20.30.40.50')));
    }

    public function test_the_browser_outranks_the_address_by_default()
    {
        // `browser_ip` is the shipped default, and this is the case it decides: someone
        // reading German from a Turkish address gets German. Their browser is a statement of
        // preference; their address is an inference.
        $detector = $this->detector(['en', 'tr', 'de']);

        $this->assertSame('de', $detector->detect($this->request('de', '20.30.40.50')));
    }

    public function test_the_address_outranks_the_browser_when_configured_to()
    {
        // The mirror image, and the first time `detection_order` is observable at all: the
        // same request, the same installed locales, a different answer.
        $detector = $this->detector(['en', 'tr', 'de'], ['detection_order' => 'ip_browser']);

        $this->assertSame('tr', $detector->detect($this->request('de', '20.30.40.50')));
    }

    public function test_the_address_answers_when_the_browser_cannot()
    {
        // What the second source is for: a browser asking for a language this forum does not
        // have, from an address whose country it can serve.
        $detector = $this->detector(['en', 'tr']);

        $this->assertSame('tr', $detector->detect($this->request('ja', '20.30.40.50')));
    }

    public function test_the_browser_answers_when_the_address_cannot()
    {
        // And the same in reverse, under `ip_browser`: an unmapped address must not stop the
        // browser source from running.
        $detector = $this->detector(['en', 'tr'], ['detection_order' => 'ip_browser']);

        $this->assertSame('tr', $detector->detect($this->request('tr', '9.4.5.6')));
        $this->assertSame('tr', $detector->detect($this->request('tr', '127.0.0.1')));
    }

    public function test_a_country_whose_languages_are_not_installed_detects_nothing()
    {
        // Japan maps to `ja` alone. With no Japanese installed there is nothing to fall back
        // on -- and nothing invented: `en` is not a safety net.
        $detector = $this->detector(['en', 'tr']);

        $this->assertNull($detector->detect($this->request(null, '2001:200::1')));
    }

    public function test_an_ip_candidate_is_matched_the_same_way_a_browser_tag_is()
    {
        // Brazil maps to `pt-BR` then `pt`, so a forum with only `pt` installed reaches it by
        // truncation -- the matcher's second tier, inherited rather than reimplemented. This
        // is the point of handing country candidates to the same matcher.
        $detector = $this->detector(['en', 'pt'], ['detection_order' => 'ip_browser']);

        // The fixture dataset holds no Brazilian range, so the country arrives as a CDN header
        // here -- which is the other half of what `IpCountryLookup` answers with.
        $request = $this->request(null)->withHeader('CF-IPCountry', 'BR');

        $this->assertSame('pt', $detector->detect($request));
    }

    public function test_detection_order_falls_back_to_browser_first_when_it_is_not_recognised()
    {
        // An unset or nonsense setting is not an error: `browser_ip` is the documented
        // default and the only sensible reading of a value nobody wrote.
        foreach (['browser_ip', '', 'nonsense'] as $order) {
            $detector = $this->detector(['en', 'tr', 'de'], ['detection_order' => $order]);

            $this->assertSame(
                'de',
                $detector->detect($this->request('de', '20.30.40.50')),
                "detection_order: '$order'"
            );
        }
    }

    /**
     * @param string[] $installed
     * @param array<string, string> $settings keys without the extension's settings prefix
     */
    protected function detector(array $installed, array $settings = []): LanguageDetector
    {
        // A real LocaleManager and real collaborators: the parser and matcher have no
        // dependencies of their own, and doubling them here would only assert that this
        // class calls the methods this class calls. The lookup is real too, pointed at the
        // small fixture dataset IpCountryLookupTest documents.
        $locales = new LocaleManager(new Translator('en'));

        foreach ($installed as $code) {
            $locales->addLocale($code, $code);
        }

        $prefixed = [];

        foreach ($settings as $key => $value) {
            $prefixed[LanguageDetector::SETTINGS_PREFIX.$key] = $value;
        }

        return new LanguageDetector(
            new BrowserLanguageParser(),
            new LocaleMatcher($locales),
            new IpCountryLookup(__DIR__.'/../fixtures/ip-dataset'),
            new CountryLanguage(),
            new SettingsStub($prefixed),
            $locales
        );
    }

    protected function request(?string $acceptLanguage, ?string $ip = null): ServerRequestInterface
    {
        $request = new ServerRequest([], [], '/', 'GET');

        if ($acceptLanguage !== null) {
            $request = $request->withHeader('Accept-Language', $acceptLanguage);
        }

        // `ipAddress` is the attribute name `Http\Middleware\ProcessIp` sets, and the only way
        // the connecting address reaches detection.
        return $ip === null ? $request : $request->withAttribute('ipAddress', $ip);
    }
}

/**
 * An in-memory settings repository.
 *
 * Four small methods, so a stub says more than a mock would: these tests care what the
 * detector does with a setting, not how many times it reads one. Core 1.8 ships no
 * in-memory implementation to reuse.
 *
 * It lives in this file rather than in one of its own because `tests/unit/` is not on the
 * autoloader -- the namespace segment is `Unit` and the directory is `unit` -- while PHPUnit
 * loads a test file whole.
 */
class SettingsStub implements SettingsRepositoryInterface
{
    protected array $settings;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
    }

    public function all(): array
    {
        return $this->settings;
    }

    public function get($key, $default = null)
    {
        // An unset setting reads as null, the same as it would before the extension's
        // defaults are written -- which is a state a real forum passes through.
        return $this->settings[$key] ?? $default;
    }

    public function set($key, $value)
    {
        $this->settings[$key] = $value;
    }

    public function delete($keyLike)
    {
        unset($this->settings[$keyLike]);
    }
}
