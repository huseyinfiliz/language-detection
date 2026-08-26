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

use HuseyinFiliz\LanguageDetection\Statistics;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/language-detection/statistics?days=30`
 *
 * The whole dashboard in one response: the summary cards, the languages table, the countries table
 * and the trend. One request rather than four, so that every figure on the page comes from the same
 * instant and the same window -- four endpoints polled separately could straddle midnight, and the
 * cards would then disagree with the chart underneath them for reasons no admin could diagnose.
 *
 * @see Statistics::report() for the window arithmetic and for what `visitors` actually counts
 */
class StatisticsController extends AbstractController
{
    protected Statistics $statistics;

    public function __construct(Statistics $statistics)
    {
        $this->statistics = $statistics;
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(ServerRequestInterface $request): array
    {
        return $this->statistics->report($this->days($request));
    }
}
