<?php

/*
 * This file is part of huseyinfiliz/language-detection.
 *
 * Copyright (c) 2026 Hüseyin Filiz.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace HuseyinFiliz\LanguageDetection\Tests\Integration;

use Carbon\Carbon;
use Flarum\Testing\integration\TestCase;
use HuseyinFiliz\LanguageDetection\Cleanup;

/**
 * Retention, against a real database.
 *
 * This is the only thing this extension does that cannot be undone, so what is pinned here is not
 * that the delete works -- it is where the boundary falls and which settings mean "delete nothing".
 * A cutoff off by one day deletes a day the dashboard still draws; a non-numeric setting read as a
 * number deletes everything.
 *
 * `setting()` before anything that touches `app()`, per the harness rule the earlier integration
 * tests record: booting makes the harness's `setting()` a no-op.
 */
class CleanupTest extends TestCase
{
    const TABLE = 'language_detection_stats';

    const SETTING = 'huseyinfiliz-language-detection.retention_days';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('huseyinfiliz-language-detection');
    }

    public function test_it_deletes_rows_older_than_the_retention_period()
    {
        $this->setting(self::SETTING, '30');
        $this->seed([0, 10, 29, 30, 100]);

        $result = $this->cleanup()->run();

        $this->assertSame(['days' => 30, 'deleted' => 2], $result);
        $this->assertSame(3, $this->rows());
    }

    /**
     * Thirty days means today and the twenty-nine before it -- `Statistics::span()`'s convention,
     * and the reason a thirty-day retention cannot delete a row the thirty-day window shows.
     */
    public function test_the_boundary_is_the_same_day_the_dashboard_window_reaches()
    {
        $this->setting(self::SETTING, '30');
        $this->seed([29, 30]);

        $this->cleanup()->run();

        $this->assertSame([$this->daysAgo(29)], $this->dates());
    }

    public function test_zero_means_never_delete()
    {
        $this->setting(self::SETTING, '0');
        $this->seed([0, 500]);

        $this->assertNull($this->cleanup()->run());
        $this->assertSame(2, $this->rows());
    }

    /**
     * A value that is not a number is a setting nobody saved, and a negative would put the cutoff
     * in the future and take the whole table with it. Both have to fail the never-delete way.
     */
    public function test_an_unusable_setting_deletes_nothing()
    {
        $this->setting(self::SETTING, 'soon');
        $this->seed([0, 500]);

        $this->assertNull($this->cleanup()->run());
        $this->assertSame(2, $this->rows());
    }

    public function test_a_negative_setting_deletes_nothing()
    {
        $this->setting(self::SETTING, '-30');
        $this->seed([0, 500]);

        $this->assertNull($this->cleanup()->run());
        $this->assertSame(2, $this->rows());
    }

    /**
     * Nothing old enough is not the same answer as retention being switched off, and the return
     * value has to keep them apart: the page says different things about them.
     */
    public function test_nothing_old_enough_still_reports_the_period()
    {
        $this->setting(self::SETTING, '90');
        $this->seed([0, 5]);

        $this->assertSame(['days' => 90, 'deleted' => 0], $this->cleanup()->run());
        $this->assertSame(2, $this->rows());
    }

    public function test_the_endpoint_deletes_for_an_administrator()
    {
        $this->setting(self::SETTING, '30');
        $this->seed([0, 100]);

        $response = $this->send($this->request('POST', '/api/language-detection/cleanup', ['authenticatedAs' => 1]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['days' => 30, 'deleted' => 1], json_decode((string) $response->getBody(), true));
        $this->assertSame(1, $this->rows());
    }

    public function test_the_endpoint_is_closed_to_everybody_else()
    {
        $this->setting(self::SETTING, '30');
        $this->seed([100]);

        $response = $this->send($this->request('POST', '/api/language-detection/cleanup', ['authenticatedAs' => 2]));

        $this->assertSame(403, $response->getStatusCode());

        // The point of the assertion: a rejected request must not have deleted anything either.
        $this->assertSame(1, $this->rows());
    }

    protected function cleanup(): Cleanup
    {
        // Resolved rather than constructed, so the autowiring this relies on is exercised.
        return $this->app()->getContainer()->make(Cleanup::class);
    }

    /**
     * One row per age in days, each with a distinct locale so the table's unique key is satisfied.
     *
     * @param int[] $ages
     */
    protected function seed(array $ages): void
    {
        $insert = [];

        foreach ($ages as $index => $age) {
            $insert[] = [
                'date'            => $this->daysAgo($age),
                'locale'          => 'l'.$index,
                'country_code'    => '',
                'requests'        => 1,
                'unique_visitors' => 1,
            ];
        }

        $this->database()->table(self::TABLE)->insert($insert);
    }

    protected function daysAgo(int $days): string
    {
        $this->app();

        return Carbon::now()->subDays($days)->toDateString();
    }

    protected function rows(): int
    {
        return $this->database()->table(self::TABLE)->count();
    }

    /**
     * @return string[]
     */
    protected function dates(): array
    {
        return $this->database()->table(self::TABLE)->orderBy('date')->pluck('date')->all();
    }
}
