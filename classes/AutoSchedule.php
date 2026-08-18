<?php

declare(strict_types=1);

namespace Grav\Plugin\PageInsights;

use DateTimeImmutable;

/**
 * Deterministic, per-installation cron scheduling for the two optional
 * automatic maintenance jobs (geo-db update, data prune - see
 * PageInsightsPlugin::onSchedulerInitialized()).
 *
 * The admin only ever picks "disabled"/"weekly"/"monthly" (see
 * blueprints.yaml, geo_db_auto_update / data_auto_prune) - never a concrete
 * weekday or time. Left to the admin, that choice tends to cluster on a
 * handful of "obvious" round times (Sunday nights, 00:00/00:05, the top of
 * an hour) - harmless for one site, but if Page Insights is running on many
 * independent installations, all of them defaulting to the same instinctive
 * time would pile onto shared cron/hosting infrastructure at once. Instead,
 * the actual weekday/day-of-month and time-of-day are derived from a hash
 * of this installation's own filesystem root plus a per-job key, so
 * different installations (and this installation's different jobs) land on
 * different, but individually stable, points in the week/month - no state
 * to persist, no migration if the plugin/Grav version changes.
 *
 * GRAV_ROOT (not e.g. the request's hostname) is deliberately used as the
 * seed: it's the one identifying value that's available and stable
 * regardless of *how* this ends up running - a real HTTP request, the
 * Admin's Scheduler status page, or `bin/grav scheduler`'s own CLI context,
 * which has no HTTP host to read at all. The trade-off: moving a whole site
 * to a different path/server shifts its computed schedule - accepted as a
 * rare, harmless side effect rather than persisting extra state to avoid it.
 */
final class AutoSchedule
{
    private const MODES = ['weekly', 'monthly'];

    /**
     * @return string|null A 5-field cron expression, or null if $mode isn't
     *   "weekly"/"monthly" (i.e. auto-scheduling is disabled for this job).
     */
    public static function cronExpression(string $seed, string $jobKey, string $mode): ?string
    {
        $point = self::point($seed, $jobKey, $mode);
        if ($point === null) {
            return null;
        }
        [$minute, $hour, $dayOfMonth, $weekday] = $point;

        return sprintf('%d %d %s * %s', $minute, $hour, $dayOfMonth ?? '*', $weekday ?? '*');
    }

    /**
     * The next actual occurrence after $from (defaults to now) - e.g. for a
     * read-only "next scheduled run: ..." hint in the admin UI. Deliberately
     * plain DateTime arithmetic rather than pulling in a cron-expression
     * parser: the schedule here is always exactly one weekday+time or one
     * day-of-month+time, never a general cron expression, so the "next
     * occurrence" logic is a handful of lines either way.
     */
    public static function nextRun(string $seed, string $jobKey, string $mode, ?DateTimeImmutable $from = null): ?DateTimeImmutable
    {
        $point = self::point($seed, $jobKey, $mode);
        if ($point === null) {
            return null;
        }
        [$minute, $hour, $dayOfMonth, $weekday] = $point;
        $from ??= new DateTimeImmutable();

        if ($weekday !== null) {
            $currentWeekday = (int) $from->format('w'); // 0 (Sun) - 6 (Sat), matches cron's day-of-week field
            $daysAhead = ($weekday - $currentWeekday + 7) % 7;
            $candidate = $from->modify("+{$daysAhead} days")->setTime($hour, $minute);
            if ($candidate <= $from) {
                $candidate = $candidate->modify('+7 days');
            }

            return $candidate;
        }

        $candidate = $from->setDate((int) $from->format('Y'), (int) $from->format('n'), $dayOfMonth)->setTime($hour, $minute);
        if ($candidate <= $from) {
            $candidate = $candidate->modify('first day of next month')->setDate(
                (int) $candidate->format('Y'),
                (int) $candidate->format('n'),
                $dayOfMonth
            )->setTime($hour, $minute);
        }

        return $candidate;
    }

    /**
     * @return array{0: int, 1: int, 2: int|null, 3: int|null}|null [minute, hour, dayOfMonth, weekday]
     */
    private static function point(string $seed, string $jobKey, string $mode): ?array
    {
        if (!in_array($mode, self::MODES, true)) {
            return null;
        }

        $hash = crc32($seed . ':' . $jobKey);

        // Spreads across the whole day, not just round hours/the top of an
        // hour - both are, independently of the weekday/monthday question
        // above, already popular default cron minutes on shared hosting.
        $minuteOfDay = ($hash >> 3) % (24 * 60);
        $hour = intdiv($minuteOfDay, 60);
        $minute = $minuteOfDay % 60;

        if ($mode === 'weekly') {
            return [$minute, $hour, null, $hash % 7];
        }

        // Capped to 1-28 so every month actually has this day - sidesteps
        // cron's (and PHP DateTime's) inconsistent handling of day-of-month
        // values that don't exist in shorter months, rather than working
        // around it.
        return [$minute, $hour, 1 + ($hash % 28), null];
    }
}
