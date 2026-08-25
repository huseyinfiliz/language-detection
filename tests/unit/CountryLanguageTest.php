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

use Flarum\Testing\unit\TestCase;
use HuseyinFiliz\LanguageDetection\CountryLanguage;

/**
 * These run against the shipped resources/countries.php rather than a fixture. The map is
 * hand-maintained and deliberate -- unlike the IP dataset, which the registries rewrite every
 * day -- so its contents are exactly the sort of thing worth pinning: the tests below fail if
 * somebody reorders a chain, and reordering a chain changes which language a visitor is shown.
 */
class CountryLanguageTest extends TestCase
{
    public function test_it_answers_with_the_languages_a_country_reads()
    {
        $countries = new CountryLanguage();

        $this->assertSame(['de'], $countries->candidatesFor('DE'));
        $this->assertSame(['tr', 'kmr'], $countries->candidatesFor('TR'));
    }

    public function test_the_majority_language_comes_first()
    {
        // The ordering rule the map is built on, and the reason a list is returned rather than
        // one language: `LocaleMatcher` walks it in order, so Canada offers English before
        // French on a forum that has both, and French on a forum that has only French.
        $countries = new CountryLanguage();

        $this->assertSame(['en', 'fr'], $countries->candidatesFor('CA'));
        $this->assertSame(['de', 'fr', 'it'], $countries->candidatesFor('CH'));
        $this->assertSame(['nl', 'fr', 'de'], $countries->candidatesFor('BE'));
    }

    public function test_a_chain_may_continue_past_english()
    {
        // Not dead weight: `US => ['en', 'es']` reaches `es` on a Spanish-only forum, which is
        // the case a bare `en` fallback would get wrong.
        $this->assertSame(['en', 'es'], (new CountryLanguage())->candidatesFor('US'));
    }

    public function test_a_country_the_map_does_not_cover_yields_nothing()
    {
        // `EU` and `AP` are real codes in the registry data that span countries, and `ZZ` is
        // the RIR stand-in for none. All three are absent from the map on purpose: no
        // candidates is the honest answer, and an empty list means the next source runs.
        $countries = new CountryLanguage();

        $this->assertSame([], $countries->candidatesFor('EU'));
        $this->assertSame([], $countries->candidatesFor('AP'));
        $this->assertSame([], $countries->candidatesFor('ZZ'));
        $this->assertSame([], $countries->candidatesFor('QQ'));
        $this->assertSame([], $countries->candidatesFor(''));
    }

    public function test_a_code_is_matched_however_it_was_written()
    {
        // The lookup uppercases before this class is reached, but a country code that came out
        // of a CDN header is only as tidy as the CDN made it.
        $countries = new CountryLanguage();

        $this->assertSame(['tr', 'kmr'], $countries->candidatesFor('tr'));
        $this->assertSame(['tr', 'kmr'], $countries->candidatesFor(' Tr '));
    }

    public function test_it_returns_codes_rather_than_locales()
    {
        // The map holds language codes, and deciding which of them a forum actually has
        // installed is `LocaleMatcher`'s job alone. If this class ever starts filtering, there
        // are two places that know what "installed" means instead of one.
        $countries = new CountryLanguage();

        // `kmr` is installed on approximately no forum, and comes back regardless.
        $this->assertContains('kmr', $countries->candidatesFor('TR'));
        // `pt-BR` keeps its region subtag, because truncating it is the matcher's second tier.
        $this->assertSame(['pt-BR', 'pt'], $countries->candidatesFor('BR'));
    }

    public function test_a_missing_map_yields_nothing_rather_than_failing()
    {
        // Not a state a release can be in, but the constructor takes a path, and a class that
        // reads a file off disk should say what it does when the file is not there.
        $countries = new CountryLanguage(__DIR__.'/nowhere/countries.php');

        $this->assertSame([], $countries->candidatesFor('TR'));
    }

    public function test_every_entry_in_the_map_is_shaped_the_way_the_matcher_expects()
    {
        // A sweep rather than a spot check: the map is 250-odd hand-written lines, and one
        // stray uppercase key or bare string value would fail silently at runtime -- an
        // unmatched country simply detects nothing, which looks exactly like a visitor from
        // somewhere unmapped.
        $map = require dirname(__DIR__, 2).'/resources/countries.php';

        $this->assertIsArray($map);
        $this->assertNotEmpty($map);

        foreach ($map as $country => $candidates) {
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', (string) $country);
            $this->assertIsArray($candidates, $country);
            $this->assertNotEmpty($candidates, $country);

            foreach ($candidates as $candidate) {
                // Lowercase language, optional region or script. Either separator: Flarum's
                // published pack codes are irregular -- `es_AR` and `es_MX` carry an
                // underscore where `pt-BR` carries a hyphen -- and the map spells each one the
                // way the pack does. `LocaleMatcher::normalize()` folds the separator on both
                // sides, so this is a real inconsistency that costs nothing rather than one to
                // tidy up here.
                $this->assertMatchesRegularExpression('/^[a-z]{2,3}([-_][A-Za-z]{2,4})?$/', $candidate, $country);
            }

            $this->assertSame(array_values(array_unique($candidates)), $candidates, $country);
        }
    }
}
