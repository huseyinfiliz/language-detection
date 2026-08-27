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
 * Turns a raw `Accept-Language` header into an ordered list of language tags, most preferred first.
 *
 * Pure string work: no dependencies, no I/O, and no knowledge of which locales are installed.
 * Deciding which of these tags a forum can serve belongs to `LocaleMatcher`, and keeping the two
 * apart is what makes both testable on their own.
 *
 * Flarum core has no `Accept-Language` handling of any kind -- `Http\Middleware\SetLocale` reads
 * only the user preference and the `locale` cookie -- so there is nothing here to extend or reuse.
 */
class BrowserLanguageParser
{
    /**
     * Longest header, in bytes, that will be looked at. Bounds the whole parse before any work
     * happens.
     */
    const MAX_HEADER_LENGTH = 1024;

    /**
     * Most tags that will be handed back.
     */
    const MAX_TAGS = 10;

    /**
     * The shape an acceptable language tag must have. This is the injection guard: header text
     * that fails it is never passed on.
     *
     * Underscores are accepted even though browsers send hyphens, because a client sending `tr_TR`
     * is still giving us real information and `LocaleMatcher` normalises separators anyway.
     */
    const TAG_PATTERN = '/^[A-Za-z]{1,8}([-_][A-Za-z0-9]{1,8})*$/';

    /**
     * @return string[] language tags, most preferred first, original case preserved
     */
    public function parse(?string $header): array
    {
        if ($header === null) {
            return [];
        }

        $header = $this->truncate($header);

        if (trim($header) === '') {
            return [];
        }

        $tags = [];

        foreach (explode(',', $header) as $element) {
            $tag = $this->parseElement($element);

            // A malformed element is skipped on its own and never discards the valid ones
            // alongside it.
            if ($tag !== null) {
                $tags[] = $tag;
            }
        }

        // Sorting is stable in PHP 8, which composer.json's `php: ^8.0` guarantees, so header
        // order survives among equal q-values. Browsers list tags in preference order and
        // frequently omit q entirely.
        usort($tags, function (array $a, array $b) {
            return $b['q'] <=> $a['q'];
        });

        return array_slice($this->deduplicate($tags), 0, self::MAX_TAGS);
    }

    /**
     * Cut an over-long header down to whole elements.
     */
    protected function truncate(string $header): string
    {
        if (strlen($header) <= self::MAX_HEADER_LENGTH) {
            return $header;
        }

        $header = substr($header, 0, self::MAX_HEADER_LENGTH);

        // The cut can land inside an element, which would turn `de-DE` into a plausible-looking
        // but invented `de-D`. Keep only complete elements.
        $lastSeparator = strrpos($header, ',');

        return $lastSeparator === false ? '' : substr($header, 0, $lastSeparator);
    }

    /**
     * Parse one comma-separated element -- a tag, optionally followed by parameters.
     *
     * @return array{tag: string, q: float}|null null when the element carries no signal
     */
    protected function parseElement(string $element): ?array
    {
        $parameters = explode(';', $element);

        // `explode` always yields at least one part, so the cast only documents that the shift
        // cannot hand back null.
        $tag = trim((string) array_shift($parameters));

        // `*` carries no signal of its own, and the caller's fallback chain already covers
        // "anything".
        if ($tag === '' || $tag === '*') {
            return null;
        }

        if (! preg_match(self::TAG_PATTERN, $tag)) {
            return null;
        }

        $quality = $this->parseQuality($parameters);

        // q=0 means "explicitly not acceptable", so the tag is dropped rather than ranked last.
        if ($quality <= 0.0) {
            return null;
        }

        return ['tag' => $tag, 'q' => $quality];
    }

    /**
     * @param string[] $parameters
     */
    protected function parseQuality(array $parameters): float
    {
        foreach ($parameters as $parameter) {
            if (! preg_match('/^\s*q\s*=\s*(.*)$/i', $parameter, $matches)) {
                continue;
            }

            $value = trim($matches[1]);

            // An unparseable q (`;q=abc`, `;q=`) is treated as 1.0 and the tag is kept: the tag
            // itself is still a valid signal, whatever the client did to the weight.
            if (! is_numeric($value)) {
                return 1.0;
            }

            return max(0.0, min(1.0, (float) $value));
        }

        // No q parameter at all means the tag is fully acceptable.
        return 1.0;
    }

    /**
     * @param array<array{tag: string, q: float}> $tags sorted by q, descending
     * @return string[]
     */
    protected function deduplicate(array $tags): array
    {
        $unique = [];

        foreach ($tags as $tag) {
            // Compared the way `LocaleMatcher` compares, but handed back verbatim --
            // normalisation is the matcher's business, not ours.
            $key = strtolower(str_replace('_', '-', $tag['tag']));

            // The list arrives sorted by q descending, so the first occurrence of a tag is its
            // highest-q one.
            if (! isset($unique[$key])) {
                $unique[$key] = $tag['tag'];
            }
        }

        return array_values($unique);
    }
}
