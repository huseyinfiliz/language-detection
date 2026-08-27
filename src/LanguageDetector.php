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
 * The resolution chain lives here rather than in the middleware, which keeps the ordering rules
 * testable without an HTTP stack. Both sources are tried in the order `detection_order` asks for,
 * and both end at the same `LocaleMatcher`. What comes back is either an exact `getLocales()` key or
 * null, and null means "leave the locale alone" -- never a guess, and never `en`, which is not
 * guaranteed to be installed at all.
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
     * The sources that exist. `detection_order` supplies the order, this supplies the reality:
     * a source the setting names but this list does not carry is skipped.
     */
    const SOURCES = [self::SOURCE_BROWSER, self::SOURCE_IP];

    protected BrowserLanguageParser $parser;

    protected LocaleMatcher $matcher;

    protected IpCountryLookup $lookup;

    protected CountryLanguage $countries;

    protected SettingsRepositoryInterface $settings;

    protected LocaleManager $locales;

    public function __construct(
        BrowserLanguageParser $parser,
        LocaleMatcher $matcher,
        IpCountryLookup $lookup,
        CountryLanguage $countries,
        SettingsRepositoryInterface $settings,
        LocaleManager $locales
    ) {
        $this->parser = $parser;
        $this->matcher = $matcher;
        $this->lookup = $lookup;
        $this->countries = $countries;
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

        if ($source === self::SOURCE_IP) {
            return $this->fromIp($request);
        }

        return null;
    }

    protected function fromBrowser(ServerRequestInterface $request): ?string
    {
        // An absent header comes back as an empty string, which the parser reads as no tags at
        // all. The matcher only ever returns a key it took from `getLocales()`, so there is
        // nothing to validate afterwards either.
        return $this->matcher->match(
            $this->parser->parse($request->getHeaderLine('Accept-Language'))
        );
    }

    protected function fromIp(ServerRequestInterface $request): ?string
    {
        $country = $this->lookup->countryFor($request);

        // No country, or a country nobody has mapped a language to: this source has no opinion
        // and the next one runs. The two cases mean the same thing to the caller.
        if ($country === null) {
            return null;
        }

        // Through the same matcher the browser source uses, so candidate languages from a
        // country inherit subtag truncation, the code aliases and the unambiguous-sibling rule,
        // and one place in the extension decides what "installed" means.
        return $this->matcher->match($this->countries->candidatesFor($country));
    }

    /**
     * The locale configured as this extension's fallback, if it is usable.
     */
    protected function configuredDefault(): ?string
    {
        $default = (string) $this->settings->get(self::SETTINGS_PREFIX.'default_locale');

        // Empty means "whatever the forum default is", which is core's business: returning null
        // leaves the locale untouched. A configured locale that is no longer installed is treated
        // the same way rather than applied blindly.
        if ($default === '' || ! $this->locales->hasLocale($default)) {
            return null;
        }

        return $default;
    }
}
