<?php

/*
 * This file is part of huseyinfiliz/language-detection.
 *
 * Copyright (c) 2026 Hüseyin Filiz.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace HuseyinFiliz\LanguageDetection\Api;

use HuseyinFiliz\LanguageDetection\LanguageCatalog;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/language-detection/missing?days=30`
 *
 * The languages visitors asked for that this forum could not serve, each with the package that would
 * fix it.
 *
 * This delegates rather than aggregating: re-deriving the report here would fork the one non-obvious
 * decision it exists to make -- that a tag is filtered by what the forum can serve *before* it is
 * grouped by which pack would serve it.
 */
class MissingLanguagesController extends AbstractController
{
    protected LanguageCatalog $catalog;

    public function __construct(LanguageCatalog $catalog)
    {
        $this->catalog = $catalog;
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(ServerRequestInterface $request): array
    {
        $days = $this->days($request);

        // The window is echoed back because the frontend fires this alongside the statistics endpoint
        // and renders both under one heading, so a future divergence in defaults is visible rather
        // than mislabelling one table with the other's window.
        return [
            'days'    => $days,
            'missing' => $this->catalog->missing($days),
        ];
    }
}
