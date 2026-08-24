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
use Flarum\Testing\unit\TestCase;
use HuseyinFiliz\LanguageDetection\LocaleMatcher;

class LocaleMatcherTest extends TestCase
{
    /**
     * A representative installed set: a plain code, a hyphenated regional pack, two
     * script variants, an underscored pack, and the catalog's one non-ISO code.
     */
    const INSTALLED = ['en', 'tr', 'pt-BR', 'zh-Hans', 'zh-Hant', 'sr-Cyrl', 'sr-Latn', 'es_MX', 'uzb'];

    /**
     * @dataProvider candidateProvider
     */
    public function test_it_resolves_candidates_against_the_installed_set(array $candidates, ?string $expected)
    {
        $this->assertSame($expected, $this->matcher(self::INSTALLED)->match($candidates));
    }

    public static function candidateProvider(): array
    {
        return [
            'an exact match' => [['tr'], 'tr'],
            'a region subtag truncates to the base language' => [['tr-TR'], 'tr'],
            'matching is case-insensitive' => [['TR'], 'tr'],
            'a hyphenated regional pack matches exactly' => [['pt-BR'], 'pt-BR'],

            // The installed key is returned verbatim, underscore and all, so it can go
            // straight to `hasLocale()` / `setLocale()`.
            'separators are interchangeable when comparing' => [['es-MX'], 'es_MX'],
            'an underscored candidate matches an underscored key' => [['es_MX'], 'es_MX'],

            // Truncation goes one subtag at a time. Stripping straight to `zh` would skip
            // the script pack that actually matches.
            'truncation stops at the script subtag' => [['zh-Hans-CN'], 'zh-Hans'],
            'a lowercased script subtag still matches' => [['zh-hans'], 'zh-Hans'],
            'a script subtag matches exactly' => [['sr-Cyrl'], 'sr-Cyrl'],

            // The only Portuguese pack installed is the Brazilian one, so it is an
            // unambiguous answer for any Portuguese request.
            'an absent regional variant falls back to its one sibling' => [['pt-PT'], 'pt-BR'],
            'a bare base language falls back to its one sibling' => [['pt'], 'pt-BR'],

            // Two siblings is a genuine ambiguity, and guessing is worse than declining:
            // the caller is free to try the next candidate, or the default locale.
            'two script variants are ambiguous' => [['sr'], null],

            // Mapping zh-CN to zh-Hans would need a region-to-script table. It is not
            // worth one: `resources/countries.php` already covers CN and TW via IP
            // detection.
            'a region that implies a script is not guessed' => [['zh-CN'], null],

            // Installed `uzb` and a requested `uz` meet in the middle, and the original
            // key comes back.
            'a non-ISO installed code is reached by its ISO alias' => [['uz'], 'uzb'],
            'the alias survives truncation' => [['uz-UZ'], 'uzb'],
            'the non-ISO code still matches itself' => [['uzb'], 'uzb'],

            'an unmatched candidate defers to the next one' => [['de', 'tr'], 'tr'],
            'nothing matches' => [['xx-YY'], null],
            'no candidates at all' => [[], null],

            // A caller may hand us an unset setting or a stray space; neither should
            // derail the rest of the list.
            'an empty candidate is skipped' => [['', 'tr'], 'tr'],
            'surrounding whitespace is tolerated' => [[' tr '], 'tr'],
        ];
    }

    public function test_it_applies_every_tier_to_each_candidate_in_turn()
    {
        // The most important test in this file. With `pt` reached only by truncation and
        // `en` an exact match, a tier-by-tier sweep would return `en` -- the visitor's
        // second choice -- because its exact-match pass would run first. Walking the
        // candidates in preference order instead gives the visitor the Portuguese they
        // actually asked for. This is RFC 4647 "lookup" behaviour.
        $this->assertSame('pt', $this->matcher(['en', 'pt'])->match(['pt-BR', 'en']));
    }

    public function test_it_never_falls_back_to_english()
    {
        // On a forum whose `default_locale` is `tr`, English is not a registered locale at
        // all: core ships English as translations rather than as a language pack, and
        // `addLocale()` is only ever called for installed packs and for the configured
        // default. So an English request has no match here, and the caller -- not the
        // matcher -- decides what to do about it.
        $this->assertNull($this->matcher(['tr'])->match(['en']));
    }

    public function test_two_regional_variants_of_one_language_are_ambiguous()
    {
        $this->assertNull($this->matcher(['es_AR', 'es_MX'])->match(['es']));
    }

    public function test_a_macrolanguage_resolves_when_only_one_member_is_installed()
    {
        // Real browsers send a bare `no`, which is neither installed nor a prefix of any
        // installed key, so without the macrolanguage map a Norwegian visitor would get
        // nothing and the missing-languages report would list `no` as missing on a forum
        // that has Norwegian.
        $this->assertSame('nb', $this->matcher(['en', 'nb'])->match(['no']));
        $this->assertSame('ckb', $this->matcher(['en', 'ckb'])->match(['ku']));
    }

    public function test_a_macrolanguage_with_two_members_installed_is_ambiguous()
    {
        // Handled by tier 3's existing "exactly one" rule, so `no` declines between
        // Bokmål and Nynorsk exactly as `sr` declines between Cyrillic and Latin.
        $this->assertNull($this->matcher(['nb', 'nn'])->match(['no']));
        $this->assertNull($this->matcher(['ckb', 'kmr'])->match(['ku']));
    }

    public function test_it_matches_nothing_when_no_locales_are_installed()
    {
        $this->assertNull($this->matcher([])->match(['tr', 'en']));
    }

    /**
     * @param string[] $installed locale codes, spelled as a language pack would publish them
     */
    protected function matcher(array $installed): LocaleMatcher
    {
        // A real LocaleManager rather than a double: it is a plain class whose
        // constructor takes a Translator, and `addLocale()` / `getLocales()` are simple
        // array operations, so there is nothing worth mocking.
        $locales = new LocaleManager(new Translator('en'));

        foreach ($installed as $code) {
            // Only the keys of `getLocales()` drive matching; the display name is
            // irrelevant here.
            $locales->addLocale($code, $code);
        }

        return new LocaleMatcher($locales);
    }
}
