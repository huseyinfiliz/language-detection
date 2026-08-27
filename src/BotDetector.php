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
 * Used by `Analytics` alone, and only to decide whether a page view is counted. A crawler's language
 * is still detected and applied -- what a bot is refused is a row, not a translation. The header is
 * read here and dropped: never stored, never logged, never put in a cookie.
 *
 * Substring matching against a fixed list is crude, deliberately. `User-Agent` is self-reported, so
 * a crawler that wanted to be counted could just claim to be Chrome; a cleverer test would buy
 * accuracy it could not keep.
 */
class BotDetector
{
    /**
     * Lowercase substrings that mark a request as automated.
     */
    const SIGNATURES = [
        // Generic tokens. Googlebot, bingbot, YandexBot, AhrefsBot, SemrushBot, Applebot,
        // Discordbot and Twitterbot all carry `bot` somewhere. `crawl` rather than `crawler` so
        // that `crawling` and `Crawler` are caught by the same entry.
        'bot',
        'crawl',
        'spider',
        'slurp',

        // Agents that announce themselves as something other than a bot.
        'bingpreview',
        'facebookexternalhit',
        'feedfetcher',
        'mediapartners-google',
        'ia_archiver',

        // Headless browsers and HTTP libraries -- a script, a monitor or a scraper.
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

    public function isBot(?string $userAgent): bool
    {
        // A missing header is not a claim to be a bot. Stripping it is a real privacy-tool setting,
        // and excluding those visitors would bias the statistic against exactly the people the rest
        // of this extension is built around.
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
