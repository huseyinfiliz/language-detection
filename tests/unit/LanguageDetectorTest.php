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

    public function test_browser_detection_runs_whichever_order_is_configured()
    {
        // IP detection does not exist yet, so `ip_browser` cannot outrank anything -- but it
        // also must not silently drop the source that does exist. This is a guard on the
        // ordering filter, not a claim that the setting is observable: both values behave
        // identically until IP detection lands.
        foreach (['browser_ip', 'ip_browser', '', 'nonsense'] as $order) {
            $detector = $this->detector(['en', 'tr'], ['detection_order' => $order]);

            $this->assertSame('tr', $detector->detect($this->request('tr')), "detection_order: '$order'");
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
        // class calls the methods this class calls.
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
            new SettingsStub($prefixed),
            $locales
        );
    }

    protected function request(?string $acceptLanguage): ServerRequestInterface
    {
        $request = new ServerRequest([], [], '/', 'GET');

        return $acceptLanguage === null ? $request : $request->withHeader('Accept-Language', $acceptLanguage);
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
