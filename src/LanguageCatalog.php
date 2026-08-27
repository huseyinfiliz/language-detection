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
 * Which languages visitors asked for that this forum cannot serve them.
 *
 * This is what the statistics table was filled for. `Analytics` records the locale each visitor
 * *asked for* rather than the one the forum managed to serve, and the difference is exactly this
 * report: a forum with no Japanese pack accumulates `ja` rows.
 *
 * Two independent questions are answered here, and collapsing them is the one mistake that turns this
 * report from incomplete into wrong:
 *
 *   1. *Is this tag served by anything installed?* That is `LocaleMatcher::match()` and nothing else,
 *      so the answer agrees with what the visitor actually got.
 *   2. *Which pack would serve it?* That is this catalog. `pt-br` belongs to the `pt-BR` pack -- but
 *      on a forum with `pt` installed the visitor was served Portuguese, so the tag is not missing.
 *
 * Hence the order in `missingFrom()`: filter by question 1, *then* group by question 2. Grouped
 * first, a single row could mix served tags with unserved ones.
 */
class LanguageCatalog
{
    protected LocaleMatcher $matcher;

    protected ConnectionInterface $db;

    protected string $path;

    /**
     * @var array<string, array{name: string, native: string, package: string|null}>|null
     */
    protected ?array $catalog = null;

    /**
     * Folded code => catalog key, verbatim. Built lazily, once per instance.
     *
     * @var array<string, string>|null
     */
    protected ?array $index = null;

    /**
     * @param string|null $path override for tests; defaults to the shipped catalog
     */
    public function __construct(LocaleMatcher $matcher, ConnectionInterface $db, ?string $path = null)
    {
        $this->matcher = $matcher;
        $this->db = $db;
        $this->path = $path ?? dirname(__DIR__).'/resources/languages.php';
    }

    /**
     * The languages visitors asked for and did not get, most asked-for first.
     *
     * @param int|null $days how many days back to look, counting today; null for all time
     *
     * @return list<array{locale: string, name: string|null, native: string|null,
     *                    package: string|null, requests: int, visitors: int, tags: list<string>}>
     */
    public function missing(?int $days): array
    {
        $query = $this->db->table(Analytics::TABLE)
            ->selectRaw('locale, SUM(requests) AS requests, SUM(unique_visitors) AS visitors')
            // Not a language: `''` is how `Analytics` records a visitor who stated no preference
            // at all, and on most forums it is the largest bucket in the table. Left in, it would
            // top this report as a missing language with no name.
            ->where('locale', '!=', '')
            ->groupBy('locale');

        if ($days !== null) {
            // `$days - 1`, so a window of 7 covers seven calendar days *including* today rather
            // than eight -- the dashboard puts a seven-day figure next to a seven-bar chart. The
            // clamp keeps a zero or a negative from asking for a window in the future.
            $query->where(
                'date',
                '>=',
                Carbon::now()->subDays(max(1, $days) - 1)->toDateString()
            );
        }

        $volumes = [];

        foreach ($query->get() as $row) {
            // Cast, because `SUM()` arrives from PDO as a string: a JSON response would otherwise
            // ship `"requests": "41"`, and the dashboard would sort it as text.
            $volumes[(string) $row->locale] = [
                'requests' => (int) $row->requests,
                'visitors' => (int) $row->visitors,
            ];
        }

        return $this->missingFrom($volumes);
    }

    /**
     * The same report, from volumes already gathered.
     *
     * Split out from `missing()` so that everything worth arguing about -- what counts as served,
     * which pack answers a tag, how tags roll up, how ties break -- can be tested without a database.
     *
     * A row's `locale` is the catalog key where one was found, spelled as the pack publishes it
     * (`pt-BR`, `es_AR`, `zh-Hans`) so that it is directly installable. Where no pack answers, it is
     * the requested tag itself -- see `keyFor()`.
     *
     * @param array<string, array{requests: int, visitors: int}> $volumes requested tag => totals
     *
     * @return list<array{locale: string, name: string|null, native: string|null,
     *                    package: string|null, requests: int, visitors: int, tags: list<string>}>
     */
    public function missingFrom(array $volumes): array
    {
        $groups = [];

        foreach ($volumes as $tag => $volume) {
            // A key like `'123'` would have been cast to an int on the way into the array.
            $tag = (string) $tag;

            if ($tag === '' || $this->matcher->match([$tag]) !== null) {
                continue;
            }

            $key = $this->keyFor($tag) ?? $tag;

            if (! isset($groups[$key])) {
                $entry = $this->all()[$key] ?? null;

                $groups[$key] = [
                    'locale'   => $key,
                    'name'     => $entry['name'] ?? null,
                    'native'   => $entry['native'] ?? null,
                    'package'  => $entry['package'] ?? null,
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

        // Ties broken by code, because MySQL's row order for equal sums is unspecified and a
        // report that reshuffles itself between refreshes is a report nobody trusts.
        usort($groups, function (array $a, array $b) {
            return $b['requests'] <=> $a['requests'] ?: strcmp($a['locale'], $b['locale']);
        });

        return $groups;
    }

    /**
     * The catalog entry for a requested tag, or null if no official pack answers it.
     *
     * @return array{name: string, native: string, package: string|null}|null
     */
    public function entryFor(string $code): ?array
    {
        $key = $this->keyFor($code);

        return $key === null ? null : ($this->all()[$key] ?? null);
    }

    /**
     * The catalog key whose pack would serve a requested tag, verbatim.
     *
     * Deliberately narrower than `LocaleMatcher`: exact match on the folded index, then progressive
     * truncation, then null. There is no unambiguous-sibling tier, because the catalog holds every
     * regional variant Flarum publishes -- `sr` would be asked to choose between `sr-Cyrl` and
     * `sr-Latn` every time, and picking one would be a guess presented as a recommendation.
     *
     * Null therefore covers two situations, both reported rather than dropped: a language Flarum has
     * no pack for (`sw`, `zu`), and a macrolanguage with more than one candidate pack (`no`, `ku`,
     * `sr`, `zh`). Demand nobody can act on is precisely the demand an admin most needs to see.
     *
     * A *served* macrolanguage never reaches here: `LocaleMatcher` resolves `no` to `nb` when Bokmål
     * alone is installed, so `missingFrom()` has already filtered it out.
     */
    public function keyFor(string $code): ?string
    {
        $folded = $this->fold($code);

        if ($folded === '') {
            return null;
        }

        $index = $this->index();

        // 1. Exact match. With both sides folded the same way, this is what resolves `pt-br` to
        //    `pt-BR`, `es-ar` to `es_AR` and `uz` to `uzb`.
        if (isset($index[$folded])) {
            return $index[$folded];
        }

        // 2. Progressive truncation, one subtag at a time: `zh-hans-cn` -> `zh-hans`, which finds
        //    `zh-Hans`. Not a single strip to the base language -- there is no `zh` in the
        //    catalog, so stripping straight to it would resolve Chinese to nothing at all.
        $subtags = explode('-', $folded);

        while (count($subtags) > 1) {
            array_pop($subtags);

            $shorter = implode('-', $subtags);

            if (isset($index[$shorter])) {
                return $index[$shorter];
            }
        }

        return null;
    }

    /**
     * The whole catalog, keyed as each pack publishes its code.
     *
     * @return array<string, array{name: string, native: string, package: string|null}>
     */
    public function all(): array
    {
        if ($this->catalog === null) {
            // `require`, not `require_once`: a second `require_once` of the same file returns
            // `true` rather than the array. A missing catalog degrades to no report, which is the
            // right failure -- an empty list of suggestions, not a wrong one.
            $catalog = is_file($this->path) ? require $this->path : null;

            $this->catalog = is_array($catalog) ? $catalog : [];
        }

        return $this->catalog;
    }

    /**
     * @return array<string, string> folded code => catalog key, verbatim
     */
    protected function index(): array
    {
        if ($this->index === null) {
            $this->index = [];

            foreach (array_keys($this->all()) as $key) {
                $key = (string) $key;
                $folded = $this->fold($key);

                if ($folded !== '' && ! isset($this->index[$folded])) {
                    $this->index[$folded] = $key;
                }
            }
        }

        return $this->index;
    }

    /**
     * Fold a code into a form safe to compare -- never to output.
     *
     * The same three lines as `LocaleMatcher::normalize()`, repeated rather than shared for the same
     * reason `Analytics` repeats them: what is compared here is a requested tag against a *published
     * catalog*, not against a forum's installed locales.
     *
     * `CODE_ALIASES` is read rather than copied, and mind its direction -- it maps catalog key to the
     * code a browser sends (`uzb` => `uz`), so applying it to both sides files catalog `uzb` under
     * `uz` and lets a requested `uz` find it.
     */
    protected function fold(string $code): string
    {
        $code = strtolower(str_replace('_', '-', trim($code)));

        if ($code === '') {
            return '';
        }

        $subtags = explode('-', $code);

        if (isset(LocaleMatcher::CODE_ALIASES[$subtags[0]])) {
            $subtags[0] = LocaleMatcher::CODE_ALIASES[$subtags[0]];

            return implode('-', $subtags);
        }

        return $code;
    }
}
