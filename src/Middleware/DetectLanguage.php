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

use Dflydev\FigCookies\FigResponseCookies;
use Flarum\Http\CookieFactory;
use Flarum\Http\RequestUtil;
use Flarum\Locale\LocaleManager;
use Flarum\User\User;
use HuseyinFiliz\LanguageDetection\LanguageDetector;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Applies a detected language to the request, once per visitor.
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
    const COOKIE = 'language_detection_locale';

    /**
     * One year, in seconds.
     */
    const COOKIE_LIFETIME = 31536000;

    protected LanguageDetector $detector;

    protected LocaleManager $locales;

    protected CookieFactory $cookies;

    public function __construct(LanguageDetector $detector, LocaleManager $locales, CookieFactory $cookies)
    {
        $this->detector = $detector;
        $this->locales = $locales;
        $this->cookies = $cookies;
    }

    public function process(Request $request, Handler $handler): Response
    {
        // The forum stack carries form submissions and API writes as well as page views,
        // and a visitor's language has no business being decided by one of those.
        if ($request->getMethod() !== 'GET') {
            return $handler->handle($request);
        }

        $actor = RequestUtil::getActor($request);

        return $actor->exists
            ? $this->forUser($actor, $request, $handler)
            : $this->forGuest($request, $handler);
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
        $remembered = Arr::get($request->getCookieParams(), $this->cookies->getName(self::COOKIE));

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
            $this->cookies->make(self::COOKIE, $detected, self::COOKIE_LIFETIME)
        );
    }
}
