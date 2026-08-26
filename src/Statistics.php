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
 * Reads only. No writes, no deletes -- deleting old rows is the cleanup command's job, and this
 * class has no business knowing that retention exists.
 *
 * Three things here are easy to get wrong, and all three are decisions rather than details.
 *
 * **The summary is derived from the rows the tables render, not queried separately.** `report()`
 * fetches the grouped languages and countries once and hands them to `summaryFrom()`, so a card
 * that disagrees with the table below it is not a bug that can happen. It also means the
 * distinct-language count is the number of table rows rather than a `COUNT(DISTINCT locale)` --
 * which is the safer of the two, because SQL counts `''` as a value and would report one language
 * more than the forum has on every forum that has any traffic at all.
 *
 * **`''` is a third case, not an unserved one.** A row with an empty locale is a visitor whose
 * `Accept-Language` said nothing usable, and on most forums it is the largest bucket in the table.
 * It is real traffic and it stays, but it did not ask for a language, so it can be neither served
 * nor unserved: counted as unserved it would put most forums at "80% of visitors unserved" and
 * send an admin looking for a problem that does not exist. So requests are split three ways --
 * `served`, `unserved`, `unstated` -- which add up to `requests` exactly, with nothing to explain
 * away. In the languages table the same distinction is a `served` flag of true, false or **null**.
 *
 * **`visitors` is daily visitors summed, and is not a count of people.** `Analytics` increments
 * `unique_visitors` once per visitor per day, so a reader who comes back on three days contributes
 * three. Over a 30-day window the figure is meaningful and it is not 30-day uniques. The field name
 * matches the column and the honesty lives in the locale strings, because inventing a second name
 * for the same number would only move the confusion somewhere less visible.
 *
 * @see LanguageCatalog for the other half of the dashboard -- the languages that are missing
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
     * The clock is read once, here, and passed down. Four sections each calling `Carbon::now()`
     * for themselves would be four windows, and a request that crossed midnight between the first
     * query and the last would ship a payload describing two different weeks -- with a trend whose
     * length no longer matched the total printed above it.
     *
     * There are no row caps on any of this, deliberately. Every dimension is naturally bounded --
     * countries by about 250, distinct locales by what browsers actually send -- and a silent
     * top-N would make an admin's totals disagree with the table they are printed above. If a cap
     * ever becomes necessary it has to arrive as a visible field in the payload, not as a
     * `limit()` nobody notices.
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
     * `languages` counts the rows that named a language, so it excludes `''` by construction
     * rather than by remembering to exclude it. `countries` excludes `''` the same way, because
     * "unknown" is not a country an admin can do anything with -- but the unknown row itself stays
     * in the countries table, where it is worth seeing.
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
     * "Could the forum serve this?" is `LocaleMatcher::match()` and nothing else -- the same call
     * the middleware made when the request came in, so this column reports what visitors actually
     * got rather than a second opinion about it. That is Phase 7's reasoning and it holds here for
     * the same reason: one place in the extension decides what "installed" means.
     *
     * @param array<string, array{requests: int, visitors: int}> $volumes requested tag => totals
     *
     * @return array<int, array<string, mixed>>
     */
    public function languagesFrom(array $volumes): array
    {
        $languages = [];

        foreach ($volumes as $tag => $volume) {
            $tag = (string) $tag;

            // `entryFor()` would return null for `''` anyway; short-circuiting says out loud that
            // "no preference stated" is not a language whose display name went missing.
            $entry = $tag === '' ? null : $this->catalog->entryFor($tag);

            $languages[] = [
                'locale'   => $tag,
                'name'     => $entry['name'] ?? null,
                'native'   => $entry['native'] ?? null,
                'served'   => $tag === '' ? null : $this->matcher->match([$tag]) !== null,
                'requests' => (int) ($volume['requests'] ?? 0),
                'visitors' => (int) ($volume['visitors'] ?? 0),
            ];
        }

        return $this->ordered($languages, 'locale');
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
     * query's own output would draw a chart in which a forum with traffic on Monday and Friday
     * shows two adjacent bars -- a quiet week rendered as a busy two-day one, with the x-axis
     * silently compressed. Zero is a fact about that day and belongs in the payload.
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
     * Busiest first, then alphabetically.
     *
     * The tie-break is not cosmetic: MySQL's row order for equal sums is unspecified, so without
     * it a table of quiet languages would reshuffle between refreshes and any test that asserted
     * its order would be intermittent.
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
     * The `(int)` casts are the point of this method existing. `SUM()` arrives from PDO as a
     * string, and uncast the payload would ship `"requests": "9"` -- which a JavaScript sort ranks
     * above `"41"`, so the dashboard would confidently name the wrong busiest language.
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
     * seven-bar chart describe the same seven days. `LanguageCatalog::missing()` computes its
     * cutoff the same way, and `ApiTest` pins the two to the same boundary so that the missing
     * languages an admin sees are the ones from the window they selected.
     *
     * The clamp stops a zero or a negative asking for a cutoff in the future, which would report
     * an empty week -- and an empty week looks like good news rather than like a bad argument.
     */
    protected function span(int $days): int
    {
        return max(1, $days) - 1;
    }
}
