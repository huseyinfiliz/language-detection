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
use HuseyinFiliz\LanguageDetection\BotDetector;

class BotDetectorTest extends TestCase
{
    /**
     * @dataProvider bots
     */
    public function test_it_recognises_automated_traffic(string $userAgent, string $why)
    {
        $this->assertTrue((new BotDetector())->isBot($userAgent), $why);
    }

    /**
     * Real `User-Agent` strings, copied rather than composed: a handwritten "Googlebot/2.1"
     * would pass a test that the header these crawlers actually send might not.
     *
     * @return array<string, array{string, string}>
     */
    public static function bots(): array
    {
        return [
            'Googlebot' => [
                'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
                'the crawler this setting exists for',
            ],
            'bingbot' => [
                'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
                'lowercase `bot`, mid-string',
            ],
            'YandexBot' => [
                'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
                'capital B, so matching has to be case-insensitive',
            ],
            'AhrefsBot' => [
                'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
                'an SEO crawler, and one of the heaviest sources of automated views',
            ],
            'facebookexternalhit' => [
                'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
                'says nothing about being a bot, which is why it is listed by name',
            ],
            'Slurp' => [
                'Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)',
                'Yahoo, which calls itself neither a bot nor a crawler',
            ],
            'Applebot' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 '
                    .'(KHTML, like Gecko) Version/16.0 Safari/605.1.15 (Applebot/0.1; '
                    .'+http://www.apple.com/go/applebot)',
                'a full Safari UA with the bot token appended -- everything before it looks human',
            ],
            'curl' => [
                'curl/8.4.0',
                'a script or a monitor, not a reader',
            ],
            'python-requests' => [
                'python-requests/2.31.0',
                'the library most scrapers are written with',
            ],
            'HeadlessChrome' => [
                'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
                    .'HeadlessChrome/120.0.0.0 Safari/537.36',
                'a real browser engine with nobody in front of it',
            ],
            'Googlebot Smartphone' => [
                'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 '
                    .'(KHTML, like Gecko) Chrome/120.0.6099.109 Mobile Safari/537.36 '
                    .'(compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
                'indistinguishable from an Android phone until the very end of the string',
            ],
        ];
    }

    /**
     * @dataProvider humans
     */
    public function test_it_leaves_real_browsers_alone(string $userAgent, string $why)
    {
        $this->assertFalse((new BotDetector())->isBot($userAgent), $why);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function humans(): array
    {
        return [
            'Chrome on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
                    .'Chrome/120.0.0.0 Safari/537.36',
                'the single most common UA on the web -- if this were excluded the statistic '
                    .'would be worthless',
            ],
            'Safari on iPhone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 '
                    .'(KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
                'mobile Safari, which contains `Mobile` but nothing bot-like',
            ],
            'Firefox on Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
                'Gecko, not to be confused with anything in the signature list',
            ],
            'Edge on macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like '
                    .'Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.2210.91',
                'Edge, whose token is `Edg` -- close to nothing here, but worth pinning',
            ],
        ];
    }

    public function test_a_phone_whose_brand_name_contains_bot_is_counted_as_one()
    {
        // Documented rather than fixed, and asserted deliberately so that nobody reads the
        // behaviour as an oversight. CUBOT is a real Android phone brand, and matching the bare
        // `bot` token means its owners' page views are not counted.
        //
        // The alternative -- requiring a word boundary, or a `/` after the token -- would let
        // through more crawlers than it would rescue phones: plenty of bots carry the token
        // inside a longer word, and `bot` in a UA is otherwise almost always a bot. The trade
        // is also asymmetric in a way that decides it. Both failures distort an already
        // approximate statistic, but under-counting leaves an admin with a number that is a
        // little low, while over-counting hands them inflated traffic figures they might act
        // on -- installing a language pack for readers who were never there.
        $cubot = 'Mozilla/5.0 (Linux; Android 11; CUBOT NOTE 20) AppleWebKit/537.36 '
            .'(KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

        $this->assertTrue((new BotDetector())->isBot($cubot));
    }

    public function test_a_missing_user_agent_is_not_treated_as_a_bot()
    {
        $detector = new BotDetector();

        // Null is what a nullable getter would hand over; an empty string is what
        // `ServerRequestInterface::getHeaderLine()` returns for a header that was never sent,
        // and so is the value `Analytics` actually passes in. Both have to be answered.
        $this->assertFalse($detector->isBot(null));
        $this->assertFalse($detector->isBot(''));
        $this->assertFalse($detector->isBot('   '));

        // Absence is not a claim. Stripping `User-Agent` is a real privacy-tool setting, and
        // excluding those visitors would bias this count against precisely the people the rest
        // of the extension is built to protect. It is a weak signal in any case: a great deal
        // of automated traffic sends a browser's UA, and very little sends none.
    }

    public function test_an_unremarkable_string_is_not_a_bot()
    {
        // A UA does not have to be a browser to be a person: obscure and homegrown clients
        // exist, and the list is an allowlist for nothing -- only the listed signatures
        // exclude a view.
        $this->assertFalse((new BotDetector())->isBot('SomeUnknownBrowser/1.0'));
    }
}
