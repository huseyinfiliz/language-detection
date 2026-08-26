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
 * One implementation with two callers -- the scheduled `Console\CleanupCommand` and the button on
 * the admin page behind `Api\CleanupController` -- and neither of them computes the cutoff. That
 * is deliberate: two copies of this arithmetic could drift apart, and the way they would drift is
 * one of them deleting rows the other still draws on a chart.
 *
 * The window convention is `Statistics::span()`'s. A retention of ninety days keeps today and the
 * eighty-nine days before it, which is exactly the span the dashboard's ninety-day window reads,
 * so a row is never deleted while a window that could still display it is selectable.
 *
 * Nothing here is reversible, which is why the disabled case is a distinct return value rather
 * than a zero: a caller has to be able to tell "retention is switched off" from "nothing was old
 * enough yet", and report the difference to whoever pressed the button.
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
     * The clock is an argument for the same reason it is one in `Analytics::count()`: it makes the
     * boundary testable without waiting for a date to pass.
     *
     * @return array{days: int, deleted: int}|null Null when retention is switched off, which is
     *                                             not the same answer as a run that deleted nothing.
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
     * Anything below one day is read as never-delete rather than clamped up to something. The
     * setting offers `0` for exactly that; an empty or non-numeric value is a setting that was
     * never saved; and a negative would put the cutoff in the *future*, which would delete the
     * whole table. All three fail the same safe way, because the unsafe way is unrecoverable.
     */
    public function retentionDays(): ?int
    {
        $days = (int) $this->settings->get(self::SETTING, self::DEFAULT_RETENTION);

        return $days < 1 ? null : $days;
    }
}
