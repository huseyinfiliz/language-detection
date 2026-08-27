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

use HuseyinFiliz\LanguageDetection\Cleanup;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin page's "delete old statistics now" button.
 *
 * `POST`, and the only endpoint here that writes anything. It shares `AbstractController` for the
 * `assertAdmin()` alone: the period deleted is the saved `retention_days` setting and never a number
 * out of the request, because a URL that could name its own cutoff could empty the table.
 *
 * Numbers come back rather than a sentence -- the admin's browser has the locale files and can say
 * "deleted 412 rows older than 90 days" in the admin's own language, which this cannot.
 */
class CleanupController extends AbstractController
{
    protected Cleanup $cleanup;

    public function __construct(Cleanup $cleanup)
    {
        $this->cleanup = $cleanup;
    }

    /**
     * @return array{days: int|null, deleted: int}
     */
    protected function data(ServerRequestInterface $request): array
    {
        $result = $this->cleanup->run();

        // `days: null` is retention switched off. Reporting it as `deleted: 0` would let the page
        // say "nothing was old enough" about a forum that is keeping its rows for ever.
        return $result ?? ['days' => null, 'deleted' => 0];
    }
}
