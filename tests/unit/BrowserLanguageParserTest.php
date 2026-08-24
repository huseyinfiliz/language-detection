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
use HuseyinFiliz\LanguageDetection\BrowserLanguageParser;

class BrowserLanguageParserTest extends TestCase
{
    protected BrowserLanguageParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new BrowserLanguageParser();
    }

    /**
     * @dataProvider headerProvider
     */
    public function test_it_parses_headers_into_ordered_tags(?string $header, array $expected)
    {
        $this->assertSame($expected, $this->parser->parse($header));
    }

    public static function headerProvider(): array
    {
        return [
            'a weighted tag ranks below an unweighted one' => ['tr,en;q=0.8', ['tr', 'en']],

            // q wins over position: the browser listed `en` first but weighted it lower.
            'quality beats header position' => ['en;q=0.8,tr', ['tr', 'en']],

            'a full browser chain keeps its order' => [
                'de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
                ['de-DE', 'de', 'en-US', 'en'],
            ],

            // PHP 8 sorts stably, so equal weights fall back to header order -- which is
            // itself a preference order.
            'equal quality preserves header order' => ['fr;q=0.5,de;q=0.5', ['fr', 'de']],

            // Truncating to the base language is LocaleMatcher's job, not the parser's.
            'a region subtag is preserved' => ['tr-TR', ['tr-TR']],

            'a script subtag is preserved' => ['zh-Hans-CN,zh-Hans;q=0.9', ['zh-Hans-CN', 'zh-Hans']],

            // Case is handed back verbatim; normalisation belongs to the matcher.
            'case is preserved verbatim' => ['EN-us', ['EN-us']],

            // Browsers send hyphens, but a client sending an underscore is still giving
            // us real information.
            'an underscore separator is accepted' => ['tr_TR', ['tr_TR']],

            'a q of one is accepted' => ['tr;q=1.0', ['tr']],
            'whitespace around elements is tolerated' => [' tr , en ; q=0.8 ', ['tr', 'en']],
            'unknown parameters are ignored' => ['tr;foo=bar;q=0.8,en;q=0.7', ['tr', 'en']],

            // `*` carries no signal of its own, and the caller's fallback chain already
            // covers "anything".
            'a wildcard is dropped' => ['*', []],
            'a wildcard alongside a real tag is dropped' => ['tr,*;q=0.5', ['tr']],

            // q=0 means "explicitly not acceptable", so the tag goes rather than ranking
            // last.
            'a q of zero drops the tag' => ['en;q=0', []],
            'a q of zero drops only that tag' => ['en;q=0,tr', ['tr']],
            'a fractional zero q drops the tag' => ['en;q=0.0,tr', ['tr']],

            'null yields nothing' => [null, []],
            'an empty header yields nothing' => ['', []],
            'a whitespace header yields nothing' => ['   ', []],
            'a bare comma yields nothing' => [',', []],
            'bare semicolons yield nothing' => [';;;', []],

            // The shape check is the injection guard: header text that fails it never
            // reaches the matcher, and one bad element never discards the good ones.
            'markup is rejected without losing the valid tag' => ['<script>alert(1)</script>,tr', ['tr']],
            'a path traversal attempt is rejected' => ['../../etc/passwd,tr', ['tr']],
            'a tag starting with a digit is rejected' => ['1tr,tr', ['tr']],
            'an over-long subtag is rejected' => ['abcdefghij,tr', ['tr']],
            'a dangling separator is rejected' => ['tr-,en', ['en']],

            // An unparseable q still leaves a usable tag behind.
            'an unparseable q is treated as one' => ['en;q=abc', ['en']],
            'an empty q is treated as one' => ['en;q=', ['en']],
            'an unparseable q outranks a valid lower one' => ['en;q=abc,tr;q=0.5', ['en', 'tr']],

            'a q above one is clamped' => ['en;q=5,tr', ['en', 'tr']],
            'a negative q clamps to zero and drops the tag' => ['en;q=-1,tr', ['tr']],
        ];
    }

    public function test_it_deduplicates_case_and_separator_insensitively()
    {
        // The three spellings are one tag. `en_US` carries the highest q, so it is the
        // spelling that survives -- the parser never rewrites what the client sent.
        $this->assertSame(
            ['en_US', 'tr'],
            $this->parser->parse('en-US;q=0.5,en_US;q=0.9,EN-us;q=0.4,tr;q=0.7')
        );
    }

    public function test_it_caps_the_number_of_tags()
    {
        $elements = [];

        for ($i = 0; $i < 20; $i++) {
            // Twenty distinct two-letter tags of descending quality, well inside the byte
            // cap, so the tag cap is what limits the result.
            $elements[] = sprintf(
                '%s%s;q=0.%02d',
                chr(ord('a') + intdiv($i, 10)),
                chr(ord('a') + $i % 10),
                99 - $i
            );
        }

        $parsed = $this->parser->parse(implode(',', $elements));

        $this->assertCount(BrowserLanguageParser::MAX_TAGS, $parsed);

        // The cap keeps the most preferred tags rather than an arbitrary slice.
        $this->assertSame('aa', $parsed[0]);
    }

    public function test_it_survives_a_pathological_header()
    {
        // Roughly 5 KB of header. Everything past the byte cap is ignored, and what is
        // left still parses and deduplicates normally.
        $this->assertSame(['tr', 'en'], $this->parser->parse('tr,'.str_repeat('en,', 1700)));
    }

    public function test_it_truncates_to_whole_elements()
    {
        // The byte cap lands inside an element here. Cutting blindly would turn `de-DE`
        // into a plausible but invented `de-D`, so a partial element is dropped -- which
        // is why the result is exactly one tag and not two.
        $this->assertSame(['de-DE'], $this->parser->parse(str_repeat('de-DE,', 200).'tr-TR'));
    }

    public function test_an_over_long_single_element_yields_nothing()
    {
        // Nothing complete survives the cap, and no partial tag is invented from it.
        $this->assertSame([], $this->parser->parse(str_repeat('a', 2000)));
    }
}
