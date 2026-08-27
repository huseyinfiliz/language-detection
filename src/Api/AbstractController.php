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

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * What every endpoint in this extension has in common: it is for administrators only.
 *
 * The assertion lives here rather than in each controller because of what it guards -- these
 * endpoints report which languages a forum's visitors ask for and which countries they come from, and
 * an endpoint registered without the check would publish that to anyone who guessed the URL, silently,
 * because the payload looks the same either way.
 *
 * There is no permission to check and none declared. Flarum 1.x's admin frontend is reachable by
 * administrators alone, so `assertAdmin()` is the whole authorisation model here.
 *
 * These are plain PSR-15 handlers returning `JsonResponse` rather than JSON:API documents. Nothing
 * here is a resource with an id, a relationship or a page of siblings; it is four aggregates over a
 * counter table, and JSON:API would add a serializer per shape to describe data with no identity.
 */
abstract class AbstractController implements RequestHandlerInterface
{
    /**
     * The windows the dashboard offers, and the only values `days` accepts.
     */
    const WINDOWS = [7, 30, 90];

    const DEFAULT_WINDOW = 30;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse($this->data($request));
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function data(ServerRequestInterface $request): array;

    /**
     * How many days the caller asked about.
     *
     * A whitelist, not a clamp. The dashboard offers exactly three windows, so any other value is a
     * typo or somebody poking at the endpoint, and it keeps an arbitrary integer out of the date
     * arithmetic downstream.
     *
     * Note what this cannot return: null. All-time is a real code path -- `LanguageCatalog::missing(null)`
     * -- but nothing in the UI asks for it, and a window-less aggregate is not something a URL should
     * be able to request on a forum with years of rows.
     */
    protected function days(ServerRequestInterface $request): int
    {
        $days = $request->getQueryParams()['days'] ?? null;

        if (! is_scalar($days) || ! in_array((int) $days, self::WINDOWS, true)) {
            return self::DEFAULT_WINDOW;
        }

        return (int) $days;
    }
}
