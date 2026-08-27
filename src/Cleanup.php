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

/**
 * Deletes the daily statistics rows that have fallen outside the configured retention period.
 *
 * One implementation with two callers -- the scheduled `Console\CleanupCommand` and the button on the
 * admin page behind `Api\CleanupController` -- and neither computes the cutoff, because two copies of
 * this arithmetic could drift into one deleting rows the other still draws on a chart.
 *
 * The window convention is `Statistics::span()`'s: a retention of ninety days keeps today and the
 * eighty-nine days before it, which is exactly the span the dashboard's ninety-day window reads.
 *
 * Nothing here is reversible, which is why the disabled case is a distinct return value rather than a
 * zero -- a caller has to be able to tell "retention is off" from "nothing was old enough yet".
 */
class Cleanup
{
    const SETTING = 'huseyinfiliz-language-detection.retention_days';

    const DEFAULT_RETENTION = '90';

    protected ConnectionInterface $db;

    protected SettingsRepositoryInterface $settings;

    public function __construct(ConnectionInterface $db, SettingsRepositoryInterface $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    /**
     * Delete every row older than the retention period.
     *
     * @return array{days: int, deleted: int}|null null when retention is switched off, which is not
     *                                             the same answer as a run that deleted nothing
     */
    public function run(?Carbon $now = null): ?array
    {
        $days = $this->retentionDays();

        if ($days === null) {
            return null;
        }

        $cutoff = ($now ?? Carbon::now())->copy()->subDays($days - 1)->toDateString();

        return [
            'days' => $days,
            'deleted' => (int) $this->db->table(Analytics::TABLE)
                ->where('date', '<', $cutoff)
                ->delete(),
        ];
    }

    /**
     * How many days of statistics to keep, or null for never delete.
     *
     * Anything below one day reads as never-delete rather than being clamped: the setting offers `0`
     * for exactly that, an empty value is a setting that was never saved, and a negative would put
     * the cutoff in the future and delete the whole table.
     */
    public function retentionDays(): ?int
    {
        $days = (int) $this->settings->get(self::SETTING, self::DEFAULT_RETENTION);

        return $days < 1 ? null : $days;
    }
}
