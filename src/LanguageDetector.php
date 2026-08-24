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

use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Works out which installed locale a request should be served in.
 *
 * The resolution chain lives here rather than in the middleware, which keeps the ordering
 * rules testable without an HTTP stack and leaves one obvious place for a second
 * detection source to slot into. What comes back is either an exact `getLocales()` key or
 * null, and null means "leave the locale alone" -- never a guess, and never `en`, which is
 * not guaranteed to be installed at all.
 */
class LanguageDetector
{
    const SETTINGS_PREFIX = 'huseyinfiliz-language-detection.';

    /**
     * Detection sources, named as the `detection_order` setting names them.
     */
    const SOURCE_BROWSER = 'browser';
    const SOURCE_IP = 'ip';

    /**
     * The detection sources that exist.
     *
     * `detection_order` supplies the order and this supplies the reality: a source the
     * setting names but this list does not carry is skipped. Only browser detection is
     * implemented so far, so both values of that setting currently resolve to the same
     * one-element list -- which is exactly why the setting has nothing to show yet.
     */
    const SOURCES = [self::SOURCE_BROWSER];

    protected BrowserLanguageParser $parser;

    protected LocaleMatcher $matcher;

    protected SettingsRepositoryInterface $settings;

    protected LocaleManager $locales;

    public function __construct(
        BrowserLanguageParser $parser,
        LocaleMatcher $matcher,
        SettingsRepositoryInterface $settings,
        LocaleManager $locales
    ) {
        $this->parser = $parser;
        $this->matcher = $matcher;
        $this->settings = $settings;
        $this->locales = $locales;
    }

    /**
     * @return string|null an exact installed locale key, or null to leave the locale alone
     */
    public function detect(ServerRequestInterface $request): ?string
    {
        foreach ($this->sources() as $source) {
            $detected = $this->fromSource($source, $request);

            if ($detected !== null) {
                return $detected;
            }
        }

        return $this->configuredDefault();
    }

    /**
     * The sources to try, in the order the `detection_order` setting asks for.
     *
     * @return string[]
     */
    protected function sources(): array
    {
        $order = $this->settings->get(self::SETTINGS_PREFIX.'detection_order') === 'ip_browser'
            ? [self::SOURCE_IP, self::SOURCE_BROWSER]
            : [self::SOURCE_BROWSER, self::SOURCE_IP];

        // `array_intersect` keeps the order of its first argument, so the setting decides
        // precedence and `SOURCES` decides what is available.
        return array_values(array_intersect($order, self::SOURCES));
    }

    protected function fromSource(string $source, ServerRequestInterface $request): ?string
    {
        if ($source === self::SOURCE_BROWSER) {
            return $this->fromBrowser($request);
        }

        return null;
    }

    protected function fromBrowser(ServerRequestInterface $request): ?string
    {
        // An absent header comes back as an empty string, which the parser reads as no
        // tags at all, so there is nothing to guard here. The matcher only ever returns a
        // key it took from `getLocales()`, so there is nothing to validate afterwards
        // either -- re-checking with `hasLocale()` would only hide a regression.
        return $this->matcher->match(
            $this->parser->parse($request->getHeaderLine('Accept-Language'))
        );
    }

    /**
     * The locale configured as this extension's fallback, if it is usable.
     */
    protected function configuredDefault(): ?string
    {
        $default = (string) $this->settings->get(self::SETTINGS_PREFIX.'default_locale');

        // Empty means "whatever the forum default is", which is core's business, not ours:
        // returning null leaves the locale untouched. A configured locale that is no
        // longer installed is treated the same way rather than applied blindly.
        if ($default === '' || ! $this->locales->hasLocale($default)) {
            return null;
        }

        return $default;
    }
}
