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
 * Inserted immediately before core's `Http\Middleware\SetLocale`, which is the only useful
 * position: the actor is authenticated by then, and core still gets the last word. So a
 * visitor who has chosen a language explicitly always keeps it -- core's middleware runs
 * after us and re-applies their choice regardless of what we did.
 *
 * The locale is applied by calling `LocaleManager::setLocale()` rather than by faking a
 * `locale` cookie for core to read. Core writes no such cookie itself, and forging one
 * would be indistinguishable from a real choice made by the visitor -- the one thing this
 * extension must never fabricate.
 *
 * The two jobs are kept apart. Deciding a language is `LanguageDetector`'s, counting the view
 * is `Analytics`', and this class does neither -- it establishes the order (detect and apply
 * first, count afterwards), owns the clock, and owns the two cookies. Counting deliberately
 * does not depend on detection having produced anything: a signed-in member with a language of
 * their own is still a page view, and a request for a language this forum has never had is the
 * most valuable row in the table.
 */
class DetectLanguage implements Middleware
{
    /**
     * Name of the memo cookie, before `CookieFactory` prefixes it.
     *
     * `flarum_language_detection_locale` on the wire. Its only job is to keep detection
     * from running twice for the same guest; because it is prefixed, core's `SetLocale`
     * never reads it, which is exactly right -- it records what we decided, not what the
     * visitor chose.
     */
    const LOCALE_COOKIE = 'language_detection_locale';

    /**
     * Name of the counted-today cookie, before `CookieFactory` prefixes it.
     *
     * `flarum_language_detection_day` on the wire, and it holds one thing: today's date, as
     * `Y-m-d`. That is the whole mechanism for counting unique visitors -- if the date in the
     * cookie is today's, this visitor has already been counted; otherwise they have not.
     *
     * A date is not an identifier. It is the same value for every visitor on a given day, so
     * it cannot be used to recognise anyone, correlate two visits, or tell one browser from
     * another. It is also useless to anyone who reads it, which is the point: the alternative
     * -- a visitor ID, or a hashed address -- would work just as well for counting and would
     * make this extension something an admin has to disclose.
     */
    const DAY_COOKIE = 'language_detection_day';

    /**
     * One year, in seconds.
     */
    const COOKIE_LIFETIME = 31536000;

    /**
     * What a day cookie has to look like to be believed.
     *
     * Cookies arrive from the client, so the value is checked before it is compared rather
     * than trusted because we wrote it. Nothing here would break on a junk value -- it would
     * simply fail to equal today's date and the visitor would be counted again -- but a
     * cookie is a place an attacker can write, and reading one without validating it is a
     * habit worth not having.
     */
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
        // The forum stack carries form submissions and API writes as well as page views,
        // and a visitor's language has no business being decided by one of those. It is
        // also the right guard for counting: a POST is not a page view.
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
     *
     * Runs after the response exists so that the day cookie is added to the same response the
     * detection branches produced, rather than to one of two responses depending on which
     * branch ran. Counting is also independent of detection on purpose: a signed-in visitor
     * with a language of their own is still a page view, and their `Accept-Language` still
     * says which language they would have asked for.
     */
    protected function count(Request $request, Response $response): Response
    {
        // One clock for the whole request, read here rather than inside `Analytics`, because
        // `flarum.forum.handler` is a container singleton -- this middleware object is built
        // once per booted application and then reused for every request through it. A `Carbon`
        // memoised on `Analytics` would freeze at the time of the first request and stay
        // there, which in a long-lived process means views recorded against a date that has
        // already passed. Reading it per call is also what guarantees the date on the row and
        // the date in the cookie are the same string, rather than two reads of a clock that
        // may cross midnight between them.
        $now = Carbon::now();
        $today = $now->toDateString();

        $counted = $this->analytics->record($request, ! $this->countedToday($request, $today), $now);

        // Only a newly counted visitor gets the cookie. A repeat view has one already, and a
        // view that was not counted at all -- analytics switched off, or a crawler the admin
        // asked to ignore -- must not be given one: writing a cookie to say "counted today"
        // when nothing was counted would suppress the visitor's real first view tomorrow.
        if (! $counted) {
            return $response;
        }

        return FigResponseCookies::set(
            $response,
            $this->cookies->make(self::DAY_COOKIE, $today, self::COOKIE_LIFETIME)
        );
    }

    /**
     * Whether this visitor has already been counted today.
     */
    protected function countedToday(Request $request, string $today): bool
    {
        $counted = Arr::get($request->getCookieParams(), $this->cookies->getName(self::DAY_COOKIE));

        if (! is_string($counted) || preg_match(self::DATE_PATTERN, $counted) !== 1) {
            return false;
        }

        // Compared for equality rather than ordering, so a cookie bearing a future date --
        // forged, or written by a client with a badly wrong clock -- counts the visitor
        // instead of silently excluding them until that date arrives.
        return $counted === $today;
    }

    /**
     * A signed-in visitor remembers their language in their own preferences.
     */
    protected function forUser(User $actor, Request $request, Handler $handler): Response
    {
        // The rule this whole extension answers to: a language someone already has is
        // never touched. Not their preference, and not this request either -- core's
        // `SetLocale` applies their choice a moment from now.
        if ($actor->getPreference('locale') !== null) {
            return $handler->handle($request);
        }

        $detected = $this->detector->detect($request);

        if ($detected === null) {
            return $handler->handle($request);
        }

        $actor->setPreference('locale', $detected);
        $actor->save();

        // Applied as well as stored, so the page being loaded right now is already in
        // their language instead of the next one. Core would read the preference back and
        // do this too; not relying on that keeps the two independent.
        $this->locales->setLocale($detected);

        return $handler->handle($request);
    }

    /**
     * A guest remembers their language in a cookie. Nothing about them is written
     * server-side, and the cookie holds an installed locale code -- nothing else.
     */
    protected function forGuest(Request $request, Handler $handler): Response
    {
        $remembered = Arr::get($request->getCookieParams(), $this->cookies->getName(self::LOCALE_COOKIE));

        // Detection has already run for this visitor. Running it again on every page view
        // could quietly move them off the language they were served the first time, so it
        // does not run twice. A value that is not an installed locale -- tampered with, or
        // from a language pack since removed -- is ignored and detected afresh, which is
        // also what keeps an arbitrary cookie value from ever reaching `setLocale()`.
        if (is_string($remembered) && $this->locales->hasLocale($remembered)) {
            $this->locales->setLocale($remembered);

            return $handler->handle($request);
        }

        $detected = $this->detector->detect($request);

        // Nothing to apply, so the forum's own default stands. No memo is written either:
        // a visitor whose language is installed later should be picked up then.
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
