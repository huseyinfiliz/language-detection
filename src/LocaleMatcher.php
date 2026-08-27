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

use Flarum\Locale\LocaleManager;

/**
 * Resolves a list of requested language tags to a locale that is actually installed.
 *
 * The installed set is always read from `LocaleManager` -- no locale list is ever hardcoded -- and
 * what comes back is the installed key's *original* spelling, ready to hand to `hasLocale()` /
 * `setLocale()` unchanged. Flarum's published pack codes are irregular: `es_AR` and `es_MX` use an
 * underscore where `pt-BR` uses a hyphen, and script subtags are mixed case (`zh-Hans`, `sr-Cyrl`).
 *
 * Returns null when nothing matches, and never substitutes `en`: core registers only the configured
 * `default_locale` as a locale (English ships as core *translations*, not as a language pack), so on
 * a forum whose default is `tr`, `hasLocale('en')` is false. Applying the default locale is the
 * caller's job. The null is also the signal the missing-languages report is built on.
 */
class LocaleMatcher
{
    /**
     * Installed codes that no browser would ever send, mapped to the code it sends instead.
     *
     * Applied while normalising *both* sides, so installed `uzb` and a requested `uz` meet in the
     * middle and the original `uzb` is still what gets returned. Of the catalog's seven three-letter
     * keys, `uzb` is the only one with an ISO 639-1 equivalent -- `ast`, `fil`, `kab`, `ckb`, `kmr`
     * and `tok` have none, so browsers send them verbatim and exact matching already works.
     */
    const CODE_ALIASES = [
        'uzb' => 'uz',
    ];

    /**
     * Macrolanguages mapped to the concrete codes Flarum publishes packs for.
     *
     * These feed tier 3's existing "exactly one installed" rule rather than adding a mechanism:
     * `no` resolves to `nb` when Bokmål alone is installed, and declines when both Bokmål and
     * Nynorsk are. Real browsers do send bare `no`, and without this the missing-languages report
     * would list it as missing on a forum that has Norwegian installed.
     */
    const MACROLANGUAGES = [
        'no' => ['nb', 'nn'],
        'ku' => ['ckb', 'kmr'],
    ];

    protected LocaleManager $locales;

    /**
     * Normalised code => installed key, verbatim. Built lazily, once per instance.
     *
     * @var array<string, string>|null
     */
    protected ?array $installed = null;

    public function __construct(LocaleManager $locales)
    {
        $this->locales = $locales;
    }

    /**
     * @param string[] $candidates ordered, most preferred first
     * @return string|null an exact key from `getLocales()`, or null if none matches
     */
    public function match(array $candidates): ?string
    {
        $installed = $this->installed();

        if (empty($installed)) {
            return null;
        }

        // Every tier is applied to each candidate in turn, rather than sweeping one tier across
        // all candidates before moving to the next. With `en` and `pt` installed and
        // `['pt-BR', 'en']` requested, this yields `pt` -- the visitor's first preference, reached
        // by a lower tier -- where a tier-by-tier sweep would return the `en` they ranked second.
        // This is RFC 4647 "lookup" behaviour.
        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = $this->normalize($candidate);

            if ($normalized === '') {
                continue;
            }

            $subtags = explode('-', $normalized);

            // 1. Exact match. `es-MX` returns `es_MX`, underscore and all; `zh-hans`
            //    returns `zh-Hans`.
            if (isset($installed[$normalized])) {
                return $installed[$normalized];
            }

            // 2. Progressive truncation, one subtag at a time: `zh-Hans-CN` -> `zh-Hans` ->
            //    `zh`. Not a single strip to the base language, because `zh-Hans` and `sr-Cyrl`
            //    are real installed keys and stripping straight to `zh` would skip the pack
            //    that actually matches.
            while (count($subtags) > 1) {
                array_pop($subtags);

                $shorter = implode('-', $subtags);

                if (isset($installed[$shorter])) {
                    return $installed[$shorter];
                }
            }

            // 3. Unambiguous sibling.
            $sibling = $this->sibling($subtags[0], $installed);

            if ($sibling !== null) {
                return $sibling;
            }
        }

        return null;
    }

    /**
     * The one installed variant of a base language, if there is exactly one.
     *
     * `pt-PT` (or a bare `pt`) resolves to `pt-BR` when that is the only Portuguese pack installed.
     * Two variants is a genuine ambiguity -- is `sr` Cyrillic or Latin? -- and declining leaves the
     * caller free to try the next candidate, which is better than guessing wrong.
     *
     * Tiers 1 and 2 have already established that the base language itself is not installed by the
     * time this runs, so only its variants can match here.
     *
     * @param array<string, string> $installed
     */
    protected function sibling(string $base, array $installed): ?string
    {
        $matches = [];

        foreach ($installed as $normalized => $original) {
            if (strpos($normalized, $base.'-') === 0) {
                $matches[$normalized] = $original;
            }
        }

        foreach (self::MACROLANGUAGES[$base] ?? [] as $member) {
            if (isset($installed[$member])) {
                $matches[$member] = $installed[$member];
            }
        }

        return count($matches) === 1 ? reset($matches) : null;
    }

    /**
     * @return array<string, string> normalised code => installed key, verbatim
     */
    protected function installed(): array
    {
        if ($this->installed === null) {
            $this->installed = [];

            foreach (array_keys($this->locales->getLocales()) as $key) {
                $key = (string) $key;
                $normalized = $this->normalize($key);

                // If two installed keys ever normalised alike, the first would win. That
                // cannot happen with the official catalog, which has no two codes differing
                // only in case or separator.
                if ($normalized !== '' && ! isset($this->installed[$normalized])) {
                    $this->installed[$normalized] = $key;
                }
            }
        }

        return $this->installed;
    }

    /**
     * Fold a code into a form safe to compare -- never to output.
     *
     * Case- and separator-insensitive, so `TR`, `tr` and `es_MX` all compare as a client would mean
     * them. `CODE_ALIASES` is applied to the language subtag, which covers a requested `uzb-UZ` as
     * well as a bare `uzb`.
     */
    protected function normalize(string $code): string
    {
        $code = strtolower(str_replace('_', '-', trim($code)));

        if ($code === '') {
            return '';
        }

        $subtags = explode('-', $code);

        if (isset(self::CODE_ALIASES[$subtags[0]])) {
            $subtags[0] = self::CODE_ALIASES[$subtags[0]];

            return implode('-', $subtags);
        }

        return $code;
    }
}
