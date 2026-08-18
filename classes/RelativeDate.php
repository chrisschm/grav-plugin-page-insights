<?php

declare(strict_types=1);

namespace Grav\Plugin\PageInsights;

use DateTimeImmutable;

/**
 * Parses the "--older-than" CLI option (cli/PruneCommand.php) and the
 * "data_auto_prune_older_than" config value (PageInsightsPlugin::
 * registerAutoPruneJob()) into a cutoff DateTimeImmutable. Shared by both so
 * the manual and the scheduled prune always agree on what a given value
 * means.
 *
 * Deliberately does NOT fall back to strtotime()/DateTime's free-form
 * natural-language parsing: this drives an irreversible DELETE (see
 * Stats::pruneData()), so an unrecognized or ambiguous value must fail
 * loudly and return null rather than be creatively guessed at. Exactly two
 * forms are accepted:
 *
 *  - a short relative offset: "<number><unit>", unit one of
 *    d(ays)/w(eeks)/m(onths)/y(ears) - e.g. "90d", "12w", "6m", "1y".
 *  - an absolute date, "Y-m-d" optionally followed by " H:i" or " H:i:s"
 *    (a literal "T" separator is also accepted) - e.g. "2025-01-01".
 */
final class RelativeDate
{
    private const RELATIVE_PATTERN = '/^(\d+)(d|w|m|y)$/i';
    private const ABSOLUTE_PATTERN = '/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/';

    public static function resolve(string $value, ?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match(self::RELATIVE_PATTERN, $value, $m)) {
            $amount = (int) $m[1];
            $modifier = match (strtolower($m[2])) {
                'd' => "-{$amount} days",
                'w' => "-{$amount} weeks",
                // PHP's month/year arithmetic can overflow past a shorter
                // target month (e.g. "31 Jan, -1 month" does not land
                // cleanly on the last day of February). Acceptable here -
                // a prune cutoff a day or two off from a calendar-exact
                // month/year practically never matters; use the "d"/"w"
                // forms instead if exact day counts are required.
                'm' => "-{$amount} months",
                'y' => "-{$amount} years",
            };

            return ($now ?? new DateTimeImmutable())->modify($modifier);
        }

        // DateTimeImmutable's constructor itself accepts far more than the
        // documented absolute form (e.g. "tomorrow", "next year" - anything
        // strtotime() understands). Gate on the strict pattern first so
        // only the two documented forms are ever accepted, not everything
        // PHP happens to parse.
        if (!preg_match(self::ABSOLUTE_PATTERN, $value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }
}
