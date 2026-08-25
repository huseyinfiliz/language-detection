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
 * Turns a country into the languages a visitor from it is likely to read.
 *
 * The answer is a list of *candidates*, not a decision: what comes back is handed to
 * `LocaleMatcher` exactly as browser tags are, so IP-based candidates inherit subtag
 * truncation, the code aliases and the unambiguous-sibling rule for free, and there stays
 * one place in the extension that decides what "installed" means.
 *
 * The map itself lives in `resources/countries.php` and is documented there -- including
 * why the majority language leads each chain, and why entries after `en` are reachable
 * rather than dead weight. It is not admin-configurable.
 */
class CountryLanguage
{
    protected string $path;

    /**
     * @var array<string, string[]>|null
     */
    protected ?array $map = null;

    /**
     * @param string|null $path override for tests; defaults to the shipped map
     */
    public function __construct(?string $path = null)
    {
        $this->path = $path ?? dirname(__DIR__).'/resources/countries.php';
    }

    /**
     * @return string[] ordered candidate locale codes, most likely first; empty for a
     *                  country the map does not cover
     */
    public function candidatesFor(string $countryCode): array
    {
        // The lookup emits uppercase already, but a country code arriving from a CDN header
        // is only as tidy as the CDN made it, and this is the cheaper place to be sure.
        return $this->map()[strtoupper(trim($countryCode))] ?? [];
    }

    /**
     * @return array<string, string[]>
     */
    protected function map(): array
    {
        if ($this->map === null) {
            // `require`, not `require_once`: a second `require_once` of the same file
            // returns `true` rather than the array, which is a trap worth avoiding even
            // though this is memoised. A missing map degrades to no candidates at all --
            // browser detection then carries the extension on its own.
            $map = is_file($this->path) ? require $this->path : null;

            $this->map = is_array($map) ? $map : [];
        }

        return $this->map;
    }
}
