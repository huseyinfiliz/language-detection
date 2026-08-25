<?php

/*
 * This file is part of huseyinfiliz/language-detection.
 *
 * Copyright (c) 2026 Hüseyin Filiz.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace HuseyinFiliz\LanguageDetection;

/**
 * Decides whether a `User-Agent` belongs to something that reads pages for a living.
 *
 * Used by `Analytics` alone, and only to decide whether a page view is counted. A crawler's
 * language is still detected and still applied -- what a bot is refused is a row, not a
 * translation. The header itself is read here and dropped: it is never written to the
 * database, never logged, and never put in a cookie.
 *
 * Substring matching against a fixed list is crude, and deliberately so. There is no
 * registry of bots to check against, `User-Agent` is self-reported anyway, and a crawler
 * that wanted to be counted could simply claim to be Chrome -- so a cleverer test would buy
 * accuracy it could not keep. What this does buy is that the crawlers which make up almost
 * all automated traffic announce themselves plainly, and are excluded at the cost of one
 * `strtolower` and a short loop.
 */
class BotDetector
{
    /**
     * Lowercase substrings that mark a request as automated.
     *
     * Grouped by what they are rather than alphabetically, because the groups are the
     * argument for the list.
     */
    const SIGNATURES = [
        // The generic tokens, which between them cover most well-behaved crawlers:
        // Googlebot, bingbot, YandexBot, AhrefsBot, SemrushBot, Applebot, Discordbot,
        // Twitterbot and the rest all carry `bot` somewhere. `crawl` rather than `crawler`
        // so that `crawling` and `Crawler` are caught by the same entry.
        'bot',
        'crawl',
        'spider',
        'slurp',

        // Agents that announce themselves as something other than a bot, so the tokens
        // above miss them entirely.
        'bingpreview',
        'facebookexternalhit',
        'feedfetcher',
        'mediapartners-google',
        'ia_archiver',

        // Headless browsers and HTTP libraries. Nobody reads a forum through one of these;
        // when they appear it is a script, a monitor or a scraper.
        'headlesschrome',
        'phantomjs',
        'scrapy',
        'curl',
        'wget',
        'python-requests',
        'python-urllib',
        'go-http-client',
        'libwww-perl',
        'okhttp',
        'axios',
        'java/',
    ];

    /**
     * @param string|null $userAgent the header as it arrived, read and then discarded
     */
    public function isBot(?string $userAgent): bool
    {
        // A missing or empty `User-Agent` is not a claim to be a bot, so it is not read as
        // one. Stripping the header is a real privacy-tool configuration, and refusing to
        // count those visitors would bias this statistic against exactly the people whose
        // privacy the rest of the extension is built around. An empty header is also a
        // weaker signal than it looks: plenty of automated traffic sends a browser's.
        if ($userAgent === null || trim($userAgent) === '') {
            return false;
        }

        $userAgent = strtolower($userAgent);

        foreach (self::SIGNATURES as $signature) {
            if (str_contains($userAgent, $signature)) {
                return true;
            }
        }

        return false;
    }
}
