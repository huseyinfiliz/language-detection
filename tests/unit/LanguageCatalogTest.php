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
use HuseyinFiliz\LanguageDetection\LanguageCatalog;
use HuseyinFiliz\LanguageDetection\LocaleMatcher;
use Illuminate\Database\ConnectionInterface;
use Mockery;

/**
 * Which languages the report calls missing, and which pack it blames.
 *
 * Everything here goes through `missingFrom()`, so no query is ever issued -- and the connection
 * handed in carries no Mockery expectations at all, which turns that into an assertion rather than
 * a convention: touch the database from this half of the class and these tests fail.
 *
 * The catalog under test is the real `resources/languages.php`. It is committed data that ships to
 * users, not a fixture, and a stub catalog would prove nothing about the file that actually gets
 * read -- the irregular keys (`es_AR`, `uzb`, `zh-Hans`) are the whole difficulty, and inventing
 * tidy ones would test the easy case only.
 *
 * `LocaleManager` and `LocaleMatcher` are real for the same reason they are real in
 * `LocaleMatcherTest`: doubling them would turn every assertion below into a claim about which
 * methods got called instead of which languages are missing.
 */
class LanguageCatalogTest extends TestCase
{
    public function test_a_language_nobody_installed_is_missing_and_names_its_package()
    {
        // The headline case, and the reason the statistics table records the requested locale
        // rather than the resolved one: this forum has no Japanese, and now its admin knows.
        $report = $this->report(['tr'], ['ja' => [41, 12]]);

        $this->assertCount(1, $report);
        $this->assertSame('ja', $report[0]['locale']);
        $this->assertSame('Japanese', $report[0]['name']);
        $this->assertSame('日本語', $report[0]['native']);
        $this->assertSame('flarum-lang/japanese', $report[0]['package']);
        $this->assertSame(41, $report[0]['requests']);
        $this->assertSame(12, $report[0]['visitors']);
    }

    public function test_a_tag_the_forum_can_already_serve_is_not_missing()
    {
        // `tr-tr` is not installed as such, but `LocaleMatcher` truncates it to the installed
        // `tr` and the visitor was served Turkish. A hand-rolled `in_array()` over the installed
        // codes would have reported this as missing -- a request that succeeded, listed as one
        // that failed.
        $this->assertSame([], $this->report(['tr'], ['tr-tr' => [41, 12]]));
    }

    public function test_a_regional_variant_is_not_missing_when_its_base_language_is_installed()
    {
        // The case that decides the order of operations. `pt-br` belongs to the `pt-BR` pack,
        // which is not installed -- but `pt` is, so the visitor got Portuguese and this is not a
        // missing language. Group by pack first and filter second, and `pt-BR` would be reported
        // to an admin whose Portuguese readers were served perfectly well.
        $this->assertSame([], $this->report(['pt'], ['pt-br' => [41, 12]]));
    }

    public function test_a_regional_variant_is_missing_when_nothing_serves_it()
    {
        // Same tag, no Portuguese at all, and now it resolves to the Brazilian pack rather than
        // to plain `pt` -- the more specific pack is the better suggestion, and exact matching on
        // the folded index is what finds it.
        $report = $this->report(['tr'], ['pt-br' => [7, 3]]);

        $this->assertCount(1, $report);
        $this->assertSame('pt-BR', $report[0]['locale']);
        $this->assertSame('flarum-lang/brazilian', $report[0]['package']);
    }

    public function test_a_script_pack_is_found_by_truncating_one_subtag_at_a_time()
    {
        // `zh-hans-cn` -> `zh-hans` -> the `zh-Hans` pack. Worth asserting the pack and not
        // merely that something was found: there is no `zh` key in the catalog, so a shortcut
        // that stripped straight to the base language would resolve Chinese to nothing and
        // report it as a language Flarum does not translate.
        $report = $this->report(['tr'], ['zh-hans-cn' => [5, 5]]);

        $this->assertSame('zh-Hans', $report[0]['locale']);
        $this->assertSame('flarum-lang/chinese-simplified', $report[0]['package']);
    }

    public function test_the_non_iso_code_is_reached_from_either_direction()
    {
        // `CODE_ALIASES` maps catalog `uzb` to the `uz` a browser sends, so folding both sides
        // is what makes these meet. Both spellings are asserted because the alias is applied
        // while building the index *and* while resolving, and only one of those is enough to
        // pass either assertion on its own.
        $this->assertSame('uzb', $this->catalog(['tr'])->keyFor('uz'));
        $this->assertSame('uzb', $this->catalog(['tr'])->keyFor('uzb'));
        $this->assertSame('Uzbek', $this->catalog(['tr'])->entryFor('uz')['name']);
    }

    public function test_an_underscored_pack_is_reached_by_the_hyphenated_tag_a_browser_sends()
    {
        // No browser sends `es_AR`. The pack is published that way regardless, and the folded
        // index is what bridges the two -- while `locale` still comes back spelled the way the
        // pack publishes it, because that is the string an admin installs.
        $report = $this->report(['tr'], ['es-ar' => [9, 4]]);

        $this->assertSame('es_AR', $report[0]['locale']);
        $this->assertSame('flarum-lang/spanish-argentina', $report[0]['package']);
    }

    public function test_a_macrolanguage_is_not_missing_when_one_of_its_members_is_installed()
    {
        // Bokmål is installed and `LocaleMatcher`'s macrolanguage map resolves a bare `no` to
        // it, so the visitor got Norwegian. This is the case that map was added for: without it
        // this report would tell an admin who has Norwegian installed that Norwegian is missing.
        $this->assertSame([], $this->report(['nb'], ['no' => [41, 12]]));
    }

    public function test_a_macrolanguage_with_no_single_pack_is_reported_without_one()
    {
        // No Norwegian installed, so the request really was unserved -- but `no` is not a pack
        // and both `nb` and `nn` would answer it. Choosing one on the admin's behalf would be a
        // guess dressed up as a recommendation, so the demand is reported and the pack is left
        // blank for a human to decide.
        $report = $this->report(['tr'], ['no' => [41, 12]]);

        $this->assertCount(1, $report);
        $this->assertSame('no', $report[0]['locale']);
        $this->assertNull($report[0]['name']);
        $this->assertNull($report[0]['native']);
        $this->assertNull($report[0]['package']);
        $this->assertSame(41, $report[0]['requests']);
    }

    public function test_a_language_flarum_has_no_pack_for_is_still_reported()
    {
        // Swahili has no `flarum-lang` pack, so there is nothing to suggest and nothing to link.
        // Reported anyway: demand nobody can act on is exactly the demand an admin most needs to
        // see, and dropping it would make every total on the page smaller than the truth.
        $report = $this->report(['tr'], ['sw' => [30, 20]]);

        $this->assertCount(1, $report);
        $this->assertSame('sw', $report[0]['locale']);
        $this->assertNull($report[0]['package']);
    }

    public function test_a_region_that_implies_a_script_is_not_guessed()
    {
        // Pinned rather than endorsed. `zh-cn` means Simplified Chinese to any reader, but
        // mapping region to script would need a table this extension does not have, and
        // `LocaleMatcher` declines the same guess at detection time (`['zh-CN']` matches nothing
        // even with `zh-Hans` installed). Guessing here alone would be worse than not guessing:
        // an admin would install the pack this report named and those visitors would *still* not
        // be served Chinese, because detection would go on declining. Fixing it means fixing the
        // matcher, which is a decision for a later phase and is recorded as such.
        $report = $this->report(['tr'], ['zh-cn' => [12, 6]]);

        $this->assertSame('zh-cn', $report[0]['locale']);
        $this->assertNull($report[0]['package']);
    }

    public function test_stating_no_preference_is_not_a_missing_language()
    {
        // `''` is how `Analytics` records a visitor whose `Accept-Language` said nothing usable,
        // and on most forums it is the biggest number in the table. It is not a language, and
        // left in it would head this report as a missing language with no name.
        $this->assertSame([], $this->report(['tr'], ['' => [9000, 4000]]));
    }

    public function test_tags_that_resolve_to_one_pack_are_rolled_up_into_one_row()
    {
        // Three ways of asking for German, one pack to install. Reported as three rows the
        // numbers would each look small enough to ignore, and the row that matters -- 60
        // requests for German -- would not appear anywhere on the page.
        $report = $this->report(['tr'], ['de' => [30, 10], 'de-de' => [20, 8], 'de-at' => [10, 2]]);

        $this->assertCount(1, $report);
        $this->assertSame('de', $report[0]['locale']);
        $this->assertSame(60, $report[0]['requests']);
        $this->assertSame(20, $report[0]['visitors']);

        // And the row says which tags it is made of, so the roll-up is auditable rather than
        // something an admin has to take on trust.
        $this->assertSame(['de', 'de-at', 'de-de'], $report[0]['tags']);
    }

    public function test_a_served_tag_is_dropped_before_its_siblings_are_rolled_up()
    {
        // `pt` is installed, so `pt-br` was served and is filtered out, while `pt-pt` -- which
        // truncates to the same installed `pt` -- is filtered out too. Nothing survives, and in
        // particular no row appears that quietly averages a served tag with an unserved one.
        $this->assertSame([], $this->report(['pt'], ['pt-br' => [30, 10], 'pt-pt' => [20, 8]]));
    }

    public function test_the_most_requested_language_comes_first()
    {
        $report = $this->report(['tr'], ['ja' => [10, 5], 'de' => [50, 20], 'fr' => [30, 15]]);

        $this->assertSame(['de', 'fr', 'ja'], array_column($report, 'locale'));
    }

    public function test_equal_demand_is_ordered_by_code_rather_than_by_luck()
    {
        // MySQL's row order for equal sums is unspecified, so without a tie-break this report
        // would reshuffle between refreshes and its integration test would be intermittent.
        $report = $this->report(['tr'], ['ja' => [10, 1], 'de' => [10, 9], 'fr' => [10, 5]]);

        $this->assertSame(['de', 'fr', 'ja'], array_column($report, 'locale'));
    }

    public function test_an_unfolded_tag_is_tolerated()
    {
        // `Analytics` lowercases before storing, so this should not arise from the middleware --
        // but a hand-seeded row or a later caller could carry `TR`, and folding both sides means
        // it costs nothing to be right about it.
        $report = $this->report(['en'], ['TR' => [4, 2], 'tr' => [6, 3]]);

        $this->assertCount(1, $report);
        $this->assertSame('tr', $report[0]['locale']);
        $this->assertSame(10, $report[0]['requests']);
    }

    public function test_nothing_requested_means_nothing_missing()
    {
        $this->assertSame([], $this->report(['tr'], []));
    }

    public function test_an_unresolvable_tag_resolves_to_no_pack()
    {
        $catalog = $this->catalog(['tr']);

        $this->assertNull($catalog->keyFor('xx'));
        $this->assertNull($catalog->entryFor('xx'));

        // Empty and whitespace-only codes are not errors and not matches. A caller reading an
        // unset setting is the realistic source of both.
        $this->assertNull($catalog->keyFor(''));
        $this->assertNull($catalog->keyFor('   '));
    }

    public function test_the_shipped_catalog_is_shaped_the_way_the_report_expects()
    {
        // A structural check on committed data rather than on code. Every row of this report
        // reads all three fields, so a pack added later with a missing `native` or a typo'd key
        // would surface as null columns in an admin's table -- here instead.
        $catalog = $this->catalog(['tr'])->all();

        $this->assertNotEmpty($catalog);

        foreach ($catalog as $code => $entry) {
            $this->assertSame(['name', 'native', 'package'], array_keys($entry), "entry: '$code'");
            $this->assertNotSame('', $entry['name'], "entry: '$code'");
            $this->assertNotSame('', $entry['native'], "entry: '$code'");

            if ($entry['package'] !== null) {
                $this->assertStringStartsWith('flarum-lang/', $entry['package'], "entry: '$code'");
            }
        }

        // English is in the catalog for its display name alone: core ships English as
        // translations, not as a pack, so there is nothing to install and nothing to link.
        $this->assertNull($catalog['en']['package']);
    }

    /**
     * The report, for a given installed set and a given set of requested volumes.
     *
     * @param string[]                 $installed locale codes, spelled as a pack publishes them
     * @param array<string, array{int, int}> $volumes requested tag => [requests, visitors]
     */
    protected function report(array $installed, array $volumes): array
    {
        $shaped = [];

        foreach ($volumes as $tag => [$requests, $visitors]) {
            $shaped[$tag] = ['requests' => $requests, 'visitors' => $visitors];
        }

        return $this->catalog($installed)->missingFrom($shaped);
    }

    /**
     * @param string[] $installed locale codes, spelled as a language pack would publish them
     */
    protected function catalog(array $installed): LanguageCatalog
    {
        $locales = new LocaleManager(new Translator('en'));

        foreach ($installed as $code) {
            $locales->addLocale($code, $code);
        }

        // No expectations on the connection, so Mockery throws on any call to it. Every test in
        // this file goes through `missingFrom()`, which has no business issuing a query, and this
        // is what says so out loud.
        return new LanguageCatalog(new LocaleMatcher($locales), Mockery::mock(ConnectionInterface::class));
    }
}
