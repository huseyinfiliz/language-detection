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

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Counts a page view against the day, the language it asked for and the country it came from.
 *
 * What is stored is one row per (date, locale, country) triple with two counters on it, and
 * nothing else -- no visitor identifier, no hashed or "anonymised" address, no user agent, no
 * URL, no referrer. That is not an implementation detail to be relaxed later: it is the reason
 * this extension can count anything at all without asking anybody's permission. A row says
 * "on this day, 41 views arrived from Turkey asking for Turkish". It cannot say who.
 *
 * The row that gets written records what the visitor *asked for*, not what the forum was able
 * to serve them. A request for Japanese on a forum with no Japanese pack still writes a `ja`
 * row, and that gap between requested and installed is the whole point -- it is what Phase 7
 * reads to tell an admin which language packs are worth installing. Recording the resolved
 * locale instead would make the statistic self-fulfilling: it could only ever report the
 * languages already present.
 *
 * @see BotDetector for why automated traffic is excluded from the counters but not from detection
 */
class Analytics
{
    const TABLE = 'language_detection_stats';

    /**
     * The width of the `locale` column, which is `string(20)` and NOT NULL.
     *
     * A tag longer than this is dropped rather than cut down to fit. `zh-Hans-CN-x-private`
     * truncated to twenty bytes is still a plausible-looking language tag, and a fabricated
     * language code in a report an admin acts on is worse than a view nobody counted.
     */
    const MAX_LOCALE_LENGTH = 20;

    protected ConnectionInterface $db;

    protected BrowserLanguageParser $parser;

    protected IpCountryLookup $lookup;

    protected BotDetector $bots;

    protected SettingsRepositoryInterface $settings;

    public function __construct(
        ConnectionInterface $db,
        BrowserLanguageParser $parser,
        IpCountryLookup $lookup,
        BotDetector $bots,
        SettingsRepositoryInterface $settings
    ) {
        $this->db = $db;
        $this->parser = $parser;
        $this->lookup = $lookup;
        $this->bots = $bots;
        $this->settings = $settings;
    }

    /**
     * Count one page view, and say whether the caller should now mark the visitor as counted.
     *
     * The clock arrives as an argument rather than being read here because the middleware
     * holding this object is built once per booted application, not once per request -- see
     * `Middleware\DetectLanguage::count()`. It also means the date on the row and the date in
     * the cookie are the same string by construction, which matters at midnight.
     *
     * @param bool $isNewVisitor whether this visitor has not yet been counted today
     *
     * @return bool true when a unique visitor was recorded, and so when the caller should
     *              write the day cookie. False means either nothing was counted at all, or a
     *              repeat view by someone already counted -- in both cases there is no cookie
     *              to set.
     */
    public function record(ServerRequestInterface $request, bool $isNewVisitor, Carbon $now): bool
    {
        // Checked before anything else, so switching analytics off costs a single settings
        // read: no header parsed, no address looked up, no query issued, no cookie set.
        if (! $this->enabled() || $this->ignored($request)) {
            return false;
        }

        $this->increment(
            $now,
            $this->requestedLocale($request),
            // `''` rather than null for an unplaceable address: the column is part of the
            // unique index, and MySQL treats NULLs in a unique index as distinct from each
            // other -- so every unknown-country view would insert a new row instead of
            // incrementing one.
            $this->lookup->countryFor($request) ?? '',
            $isNewVisitor
        );

        return $isNewVisitor;
    }

    protected function enabled(): bool
    {
        return (bool) $this->settings->get(LanguageDetector::SETTINGS_PREFIX.'enable_analytics', '1');
    }

    /**
     * Whether this view is automated traffic the admin has asked not to count.
     *
     * Note what this does *not* do: it does not stop the language being detected. A crawler
     * fetching a Turkish-language page still gets Turkish, because search engines index what
     * they are served and serving them the wrong language would be a real bug. The bot check
     * lives here, in counting, and nowhere near `LanguageDetector`.
     */
    protected function ignored(ServerRequestInterface $request): bool
    {
        if (! $this->settings->get(LanguageDetector::SETTINGS_PREFIX.'ignore_bots', '1')) {
            return false;
        }

        return $this->bots->isBot($request->getHeaderLine('User-Agent'));
    }

    /**
     * The language this visitor asked for, normalised for grouping.
     *
     * Lowercased with underscores folded to hyphens, so that `tr-TR`, `tr_TR` and `TR-tr`
     * are one row rather than three. That repeats a line of `LocaleMatcher`'s normalisation
     * rather than calling into it, which is the honest choice: the matcher normalises in
     * order to compare a tag against installed locales, this normalises in order to group
     * rows in a table, and tying the two together would mean a future change to matching
     * silently re-keys a year of history.
     *
     * @return string the empty string when the visitor asked for nothing usable
     */
    protected function requestedLocale(ServerRequestInterface $request): string
    {
        foreach ($this->parser->parse($request->getHeaderLine('Accept-Language')) as $tag) {
            $normalised = strtolower(str_replace('_', '-', $tag));

            // Preference order is preserved by the parser, so taking the first tag that fits
            // records a visitor's second choice rather than throwing the whole header away
            // over an over-long first one. `strlen` deliberately, not `mb_strlen`: the column
            // is twenty *bytes*, and the tag pattern admits ASCII only anyway.
            if (strlen($normalised) <= self::MAX_LOCALE_LENGTH) {
                return $normalised;
            }
        }

        // Either no `Accept-Language`, or nothing in it survived parsing. Both are worth
        // knowing about -- a large `''` bucket is how an admin discovers that most of their
        // traffic arrives with no stated preference at all -- so it is recorded, not skipped.
        return '';
    }

    /**
     * Add one view, and possibly one visitor, to today's row for this language and country.
     *
     * One statement, so two simultaneous views of the same page cannot read-modify-write over
     * each other: the increments happen inside MySQL under the unique index, not in PHP.
     */
    protected function increment(Carbon $now, string $locale, string $country, bool $newVisitor): void
    {
        $visitor = (int) $newVisitor;

        $this->db->table(self::TABLE)->upsert(
            [
                'date'            => $now->toDateString(),
                'locale'          => $locale,
                'country_code'    => $country,
                'requests'        => 1,
                'unique_visitors' => $visitor,
                // The query builder does not maintain timestamps -- that is Eloquent's doing
                // -- and the migration declares both columns nullable with no default, so
                // they are set here or they stay null.
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            // Ignored by the MySQL grammar, which compiles `on duplicate key update` and lets
            // the index decide, but passed anyway: it documents which index has to exist for
            // this to be an upsert rather than an ever-growing pile of rows.
            ['date', 'locale', 'country_code'],
            [
                'requests'        => $this->db->raw('requests + 1'),
                // Interpolated because a raw expression carries no bindings -- `cleanBindings`
                // strips it -- so there is no placeholder to bind to. The `(int)` cast above
                // makes that provably safe: `$visitor` is 0 or 1 and cannot be anything else.
                'unique_visitors' => $this->db->raw('unique_visitors + '.$visitor),
                'updated_at'      => $now,
            ]
        );
    }
}
