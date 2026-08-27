<?php

/*
 * This file is part of huseyinfiliz/language-detection.
 *
 * Copyright (c) 2026 Hüseyin Filiz.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace HuseyinFiliz\LanguageDetection\Middleware;

use Carbon\Carbon;
use Dflydev\FigCookies\FigResponseCookies;
use Flarum\Http\CookieFactory;
use Flarum\Http\RequestUtil;
use Flarum\Locale\LocaleManager;
use Flarum\User\User;
use HuseyinFiliz\LanguageDetection\Analytics;
use HuseyinFiliz\LanguageDetection\LanguageDetector;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Applies a detected language to the request, once per visitor, and counts the page view.
 *
 * Inserted immediately before core's `Http\Middleware\SetLocale`, so the actor is authenticated by
 * then and core still gets the last word -- a visitor who has chosen a language keeps it.
 *
 * Deciding a language is `LanguageDetector`'s job and counting the view is `Analytics`'; this class
 * establishes the order, owns the clock, and owns the two cookies.
 */
class DetectLanguage implements Middleware
{
    /**
     * Records what we detected, so detection does not run again for the same guest. Prefixed by
     * `CookieFactory`, so core's `SetLocale` never reads it.
     */
    const LOCALE_COOKIE = 'language_detection_locale';

    /**
     * Holds today's date as `Y-m-d`, which is the whole mechanism for counting unique visitors. A
     * date is the same value for every visitor, so it cannot be used to recognise anyone.
     */
    const DAY_COOKIE = 'language_detection_day';

    /**
     * One year, in seconds.
     */
    const COOKIE_LIFETIME = 31536000;

    const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    protected LanguageDetector $detector;

    protected LocaleManager $locales;

    protected CookieFactory $cookies;

    protected Analytics $analytics;

    public function __construct(
        LanguageDetector $detector,
        LocaleManager $locales,
        CookieFactory $cookies,
        Analytics $analytics
    ) {
        $this->detector = $detector;
        $this->locales = $locales;
        $this->cookies = $cookies;
        $this->analytics = $analytics;
    }

    public function process(Request $request, Handler $handler): Response
    {
        // A form submission or an API write is not a page view, and has no business deciding a
        // visitor's language either.
        if ($request->getMethod() !== 'GET') {
            return $handler->handle($request);
        }

        $actor = RequestUtil::getActor($request);

        $response = $actor->exists
            ? $this->forUser($actor, $request, $handler)
            : $this->forGuest($request, $handler);

        return $this->count($request, $response);
    }

    /**
     * Record the view, and mark the visitor counted if they were not already.
     */
    protected function count(Request $request, Response $response): Response
    {
        // Read per request, not memoised: `flarum.forum.handler` is a container singleton, so a
        // `Carbon` held on this object would freeze at the first request's date.
        $now = Carbon::now();
        $today = $now->toDateString();

        $counted = $this->analytics->record($request, ! $this->countedToday($request, $today), $now);

        // A view that was not counted -- analytics off, or an ignored crawler -- must not get the
        // cookie, or it would suppress the visitor's real first view tomorrow.
        if (! $counted) {
            return $response;
        }

        return FigResponseCookies::set(
            $response,
            $this->cookies->make(self::DAY_COOKIE, $today, self::COOKIE_LIFETIME)
        );
    }

    protected function countedToday(Request $request, string $today): bool
    {
        $counted = Arr::get($request->getCookieParams(), $this->cookies->getName(self::DAY_COOKIE));

        if (! is_string($counted) || preg_match(self::DATE_PATTERN, $counted) !== 1) {
            return false;
        }

        // Equality rather than ordering, so a future date -- forged, or a badly wrong clock --
        // counts the visitor instead of excluding them until that date arrives.
        return $counted === $today;
    }

    /**
     * A signed-in visitor remembers their language in their own preferences.
     */
    protected function forUser(User $actor, Request $request, Handler $handler): Response
    {
        // The rule this whole extension answers to: a language someone already has is never
        // touched.
        if ($actor->getPreference('locale') !== null) {
            return $handler->handle($request);
        }

        $detected = $this->detector->detect($request);

        if ($detected === null) {
            return $handler->handle($request);
        }

        $actor->setPreference('locale', $detected);
        $actor->save();

        // Applied as well as stored, so this page is already in their language.
        $this->locales->setLocale($detected);

        return $handler->handle($request);
    }

    /**
     * A guest remembers their language in a cookie. Nothing about them is written server-side.
     */
    protected function forGuest(Request $request, Handler $handler): Response
    {
        $remembered = Arr::get($request->getCookieParams(), $this->cookies->getName(self::LOCALE_COOKIE));

        // Re-detecting on every view could quietly move the visitor off the language they were
        // served first. A value that is not installed -- tampered with, or from a pack since
        // removed -- is detected afresh, which keeps arbitrary cookie text out of `setLocale()`.
        if (is_string($remembered) && $this->locales->hasLocale($remembered)) {
            $this->locales->setLocale($remembered);

            return $handler->handle($request);
        }

        $detected = $this->detector->detect($request);

        // The forum's own default stands. No memo either: a visitor whose language is installed
        // later should be picked up then.
        if ($detected === null) {
            return $handler->handle($request);
        }

        $this->locales->setLocale($detected);

        return FigResponseCookies::set(
            $handler->handle($request),
            $this->cookies->make(self::LOCALE_COOKIE, $detected, self::COOKIE_LIFETIME)
        );
    }
}
