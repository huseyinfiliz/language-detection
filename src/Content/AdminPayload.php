<?php

/*
 * This file is part of huseyinfiliz/language-detection.
 *
 * Copyright (c) 2026 Hüseyin Filiz.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace HuseyinFiliz\LanguageDetection\Content;

use Flarum\Frontend\Document;
use HuseyinFiliz\LanguageDetection\IpCountryLookup;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Tells the admin page when the bundled IP dataset was built.
 *
 * An invokable class rather than a closure in `extend.php`, because the date is read through
 * `IpCountryLookup` -- which owns where the dataset lives -- and a closure passed to
 * `Extend\Frontend::content()` is handed a document and a request, not container dependencies.
 *
 * Three states reach the frontend and all three are distinguishable:
 *
 * - `null` -- no dataset installed, so IP detection is inactive and the page says so.
 * - `['date' => '2026-08-24']` -- installed and dated, and the notice can name the date.
 * - `['date' => null]` -- installed, but the sidecar could not be read for a date. The page shows
 *   nothing rather than claiming the lookup is inactive, which it is not.
 */
class AdminPayload
{
    /**
     * Read in the admin frontend as `app.data['huseyinfiliz-language-detection.ipData']`.
     */
    const KEY = 'huseyinfiliz-language-detection.ipData';

    protected IpCountryLookup $lookup;

    public function __construct(IpCountryLookup $lookup)
    {
        $this->lookup = $lookup;
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $info = $this->lookup->datasetInfo();

        if ($info === null) {
            $document->payload[self::KEY] = null;

            return;
        }

        // `data_date` is the date the registries say their statistics describe, which is the one worth
        // showing; `built` is when the file was generated and is the near-enough fallback. Neither is
        // read from `filemtime()`, because git sets that to checkout time.
        $date = $info['data_date'] ?? $info['built'] ?? null;

        $document->payload[self::KEY] = [
            'date' => is_string($date) && $date !== '' ? $date : null,
        ];
    }
}
