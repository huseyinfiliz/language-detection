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
use Illuminate\Database\ConnectionInterface;

/**
 * Everything the admin dashboard shows, read back out of the statistics table.
 *
 * Reads only -- deleting old rows is `Cleanup`'s job, and this class has no business knowing that
 * retention exists. Three things here are decisions rather than details:
 *
 * **The summary is derived from the rows the tables render, not queried separately.** `report()`
 * fetches the grouped languages and countries once and hands them to `summaryFrom()`, so a card that
 * disagrees with the table below it cannot happen. It also makes the distinct-language count the
 * number of table rows rather than a `COUNT(DISTINCT locale)`, which would count `''` as a language
 * -- and, since `languagesFrom()` now groups spelling variants of the same served language onto one
 * row, would also have overcounted a language requested under several tags.
 *
 * **`''` is a third case, not an unserved one.** A row with an empty locale is a visitor whose
 * `Accept-Language` said nothing usable, and on most forums it is the largest bucket in the table. It
 * did not ask for a language, so it can be neither served nor unserved -- counted as unserved it would
 * put most forums at "80% of visitors unserved". Requests therefore split three ways (`served`,
 * `unserved`, `unstated`) which add up to `requests` exactly, and the languages table carries a
 * `served` flag of true, false or **null**.
 *
 * **`visitors` is daily visitors summed, and is not a count of people.** `Analytics` increments
 * `unique_visitors` once per visitor per day, so a reader who comes back on three days contributes
 * three. The field name matches the column and the honesty lives in the locale strings.
 */
class Statistics
{
    protected ConnectionInterface $db;

    protected LanguageCatalog $catalog;

    protected LocaleMatcher $matcher;

    public function __construct(ConnectionInterface $db, LanguageCatalog $catalog, LocaleMatcher $matcher)
    {
        $this->db = $db;
        $this->catalog = $catalog;
        $this->matcher = $matcher;
    }

    /**
     * The whole dashboard payload for a window of days.
     *
     * The clock is read once, here, and passed down. Four sections each calling `Carbon::now()` would
     * be four windows, and a request crossing midnight would ship a payload describing two different
     * weeks.
     *
     * There are no row caps on any of this, deliberately: every dimension is naturally bounded, and a
     * silent top-N would make an admin's totals disagree with the table they are printed above.
     *
     * @return array<string, mixed>
     */
    public function report(int $days): array
    {
        $now = Carbon::now();

        $languages = $this->languagesFrom($this->languageRows($now, $days));
        $countries = $this->countriesFrom($this->countryRows($now, $days));

        return [
            'days'      => $days,
            'summary'   => $this->summaryFrom($languages, $countries),
            'languages' => $languages,
            'countries' => $countries,
            'trend'     => $this->trendFrom($this->trendRows($now, $days), $now, $days),
        ];
    }

    /**
     * The headline totals.
     *
     * @return array<string, int>
     */
    public function summary(int $days): array
    {
        $now = Carbon::now();

        return $this->summaryFrom(
            $this->languagesFrom($this->languageRows($now, $days)),
            $this->countriesFrom($this->countryRows($now, $days))
        );
    }

    /**
     * One row per language visitors asked for, busiest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function languages(int $days): array
    {
        $now = Carbon::now();

        return $this->languagesFrom($this->languageRows($now, $days));
    }

    /**
     * One row per country visitors came from, busiest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function countries(int $days): array
    {
        $now = Carbon::now();

        return $this->countriesFrom($this->countryRows($now, $days));
    }

    /**
     * One entry per calendar day in the window, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function trend(int $days): array
    {
        $now = Carbon::now();

        return $this->trendFrom($this->trendRows($now, $days), $now, $days);
    }

    /**
     * Totals over rows already shaped by `languagesFrom()` and `countriesFrom()`.
     *
     * `languages` counts the rows that named a language, so it excludes `''` by construction.
     * `countries` excludes `''` the same way, because "unknown" is not a country an admin can act on
     * -- but the unknown row itself stays in the countries table, where it is worth seeing.
     *
     * @param array<int, array<string, mixed>> $languages
     * @param array<int, array<string, mixed>> $countries
     *
     * @return array<string, int>
     */
    public function summaryFrom(array $languages, array $countries): array
    {
        $summary = [
            'requests'  => 0,
            'visitors'  => 0,
            'languages' => 0,
            'countries' => 0,
            'served'    => 0,
            'unserved'  => 0,
            'unstated'  => 0,
        ];

        foreach ($languages as $row) {
            $summary['requests'] += (int) $row['requests'];
            $summary['visitors'] += (int) $row['visitors'];

            // Null rather than false: this request stated no language, so it belongs to neither
            // side of the served/unserved split. See the class docblock.
            if ($row['served'] === null) {
                $summary['unstated'] += (int) $row['requests'];

                continue;
            }

            $summary['languages']++;
            $summary[$row['served'] ? 'served' : 'unserved'] += (int) $row['requests'];
        }

        foreach ($countries as $row) {
            if ($row['country'] !== '') {
                $summary['countries']++;
            }
        }

        return $summary;
    }

    /**
     * Name each requested locale, say whether the forum could serve it, and order by demand.
     *
     * "Could the forum serve this?" is `LocaleMatcher::match()` and nothing else -- the same call the
     * middleware made when the request came in, so this column reports what visitors actually got
     * rather than a second opinion about it.
     *
     * Grouped by *resolved* locale, not by the raw tag the database stored. `Analytics` records the
     * exact tag a browser sent, so `tr` and `tr-TR` and `TR` are three different rows in the database
     * -- and, more visibly, so are `zh-CN` and `zh-Hans`, which resolve to the very same installed
     * pack. Without this grouping, one language would show up as several rows with the same name,
     * each with a fraction of its real traffic, and the same language could appear once as "Served"
     * and once as "Not served" purely because of which spelling happened to be requested. The grouping
     * key is:
     *
     *   - the empty string, kept apart from every other tag: "no preference stated" is a case of its
     *     own (see the class docblock), never merged with a named language;
     *   - the installed locale's own spelling, when `LocaleMatcher` resolves the tag to one -- this is
     *     what makes `tr-TR` and `TR` collapse onto the same row as `tr`, and `zh-CN` onto `zh-Hans`;
     *   - otherwise, the catalog key that *would* serve the tag if a matching pack were installed, via
     *     `LanguageCatalog::keyFor()` -- the same rule `missingFrom()` uses, so an unserved language
     *     rolls up here exactly as it does on the Missing tab;
     *   - failing both, the raw tag itself, for a tag that names nothing this catalog recognises.
     *
     * `tags` on each row lists every raw spelling that rolled up into it, so nothing requested is
     * hidden -- it is just no longer spread across duplicate rows.
     *
     * @param array<string, array{requests: int, visitors: int}> $volumes requested tag => totals
     *
     * @return array<int, array<string, mixed>>
     */
    public function languagesFrom(array $volumes): array
    {
        $groups = [];

        foreach ($volumes as $tag => $volume) {
            $tag = (string) $tag;

            if ($tag === '') {
                $key = '';
                $served = null;
                $entry = null;
            } else {
                $installed = $this->matcher->match([$tag]);
                $served = $installed !== null;

                // Prefer the installed spelling as the group key when served, so every variant of a
                // served language collapses onto the row for the pack that actually answered it.
                // Fall back to the catalog's own key, then to the raw tag, when nothing is installed.
                $key = $installed ?? ($this->catalog->keyFor($tag) ?? $tag);

                // The catalog is keyed by its own canonical spelling, so look up by that first; if
                // the group key came from `LocaleMatcher` and happens not to be a catalog key at all
                // (an installed locale outside the official catalog), fall back to the raw tag.
                $entry = $this->catalog->entryFor($key) ?? $this->catalog->entryFor($tag);
            }

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'locale'   => $key,
                    'name'     => $entry['name'] ?? null,
                    'native'   => $entry['native'] ?? null,
                    'served'   => $served,
                    'requests' => 0,
                    'visitors' => 0,
                    'tags'     => [],
                ];
            }

            $groups[$key]['requests'] += (int) ($volume['requests'] ?? 0);
            $groups[$key]['visitors'] += (int) ($volume['visitors'] ?? 0);
            $groups[$key]['tags'][] = $tag;
        }

        foreach ($groups as &$group) {
            sort($group['tags']);
        }

        unset($group);

        return $this->ordered(array_values($groups), 'locale');
    }

    /**
     * Order countries by demand. `''` is a row like any other here, and means "unknown".
     *
     * @param array<string, array{requests: int, visitors: int}> $volumes country code => totals
     *
     * @return array<int, array<string, mixed>>
     */
    public function countriesFrom(array $volumes): array
    {
        $countries = [];

        foreach ($volumes as $code => $volume) {
            $countries[] = [
                'country'  => (string) $code,
                'requests' => (int) ($volume['requests'] ?? 0),
                'visitors' => (int) ($volume['visitors'] ?? 0),
            ];
        }

        return $this->ordered($countries, 'country');
    }

    /**
     * Every day in the window, including the quiet ones.
     *
     * `GROUP BY date` returns only days that have rows, so the gaps are filled here. Shipping the
     * query's own output would draw a forum with traffic on Monday and Friday as two adjacent bars --
     * a quiet week rendered as a busy two-day one.
     *
     * @param array<string, array{requests: int, visitors: int}> $volumes date => totals
     *
     * @return array<int, array<string, mixed>>
     */
    public function trendFrom(array $volumes, Carbon $now, int $days): array
    {
        $trend = [];

        // Counting down to zero gives oldest-first without sorting, and `copy()` per step keeps
        // `$now` untouched -- Carbon dates are mutable, and `$now` is the window every other
        // section of the payload was built from.
        for ($offset = $this->span($days); $offset >= 0; $offset--) {
            $date = $now->copy()->subDays($offset)->toDateString();
            $volume = $volumes[$date] ?? [];

            $trend[] = [
                'date'     => $date,
                'requests' => (int) ($volume['requests'] ?? 0),
                'visitors' => (int) ($volume['visitors'] ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * Busiest first, then alphabetically. The tie-break is not cosmetic: MySQL's row order for equal
     * sums is unspecified, so without it a table of quiet languages would reshuffle between refreshes.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    protected function ordered(array $rows, string $label): array
    {
        usort($rows, function (array $a, array $b) use ($label) {
            return $b['requests'] <=> $a['requests'] ?: strcmp($a[$label], $b[$label]);
        });

        return $rows;
    }

    /**
     * @return array<string, array{requests: int, visitors: int}>
     */
    protected function languageRows(Carbon $now, int $days): array
    {
        return $this->volumes(
            $this->windowed($now, $days)
                ->selectRaw('locale, SUM(requests) AS requests, SUM(unique_visitors) AS visitors')
                ->groupBy('locale')
                ->get(),
            'locale'
        );
    }

    /**
     * @return array<string, array{requests: int, visitors: int}>
     */
    protected function countryRows(Carbon $now, int $days): array
    {
        return $this->volumes(
            $this->windowed($now, $days)
                ->selectRaw('country_code, SUM(requests) AS requests, SUM(unique_visitors) AS visitors')
                ->groupBy('country_code')
                ->get(),
            'country_code'
        );
    }

    /**
     * @return array<string, array{requests: int, visitors: int}>
     */
    protected function trendRows(Carbon $now, int $days): array
    {
        return $this->volumes(
            $this->windowed($now, $days)
                ->selectRaw('date, SUM(requests) AS requests, SUM(unique_visitors) AS visitors')
                ->groupBy('date')
                ->get(),
            'date'
        );
    }

    /**
     * Fold a grouped result set into totals keyed by one column.
     *
     * The `(int)` casts are the point of this method existing. `SUM()` arrives from PDO as a string,
     * and uncast the payload would ship `"requests": "9"` -- which a JavaScript sort ranks above
     * `"41"`, so the dashboard would name the wrong busiest language.
     *
     * @param iterable<object> $rows
     *
     * @return array<string, array{requests: int, visitors: int}>
     */
    protected function volumes(iterable $rows, string $column): array
    {
        $volumes = [];

        foreach ($rows as $row) {
            $volumes[(string) $row->$column] = [
                'requests' => (int) $row->requests,
                'visitors' => (int) $row->visitors,
            ];
        }

        return $volumes;
    }

    /**
     * The statistics table, filtered to the window.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function windowed(Carbon $now, int $days)
    {
        return $this->db->table(Analytics::TABLE)
            ->where('date', '>=', $now->copy()->subDays($this->span($days))->toDateString());
    }

    /**
     * How many days back the window reaches.
     *
     * Seven days means today and the six before it, which is what makes a seven-day total and a
     * seven-bar chart describe the same week. `LanguageCatalog::missing()` computes its cutoff the
     * same way, so the missing languages an admin sees are the ones from the window they selected.
     *
     * The clamp stops a zero or a negative asking for a cutoff in the future, which would report an
     * empty week -- and an empty week looks like good news.
     */
    protected function span(int $days): int
    {
        return max(1, $days) - 1;
    }
}