<?php

declare(strict_types=1);

namespace Grav\Plugin\PageInsights;

/**
 * Locale-aware date formatting for the admin UIs - kept as its own tiny,
 * UI-independent (no Grav/Twig types in its signature) class rather than a
 * method on Stats.php, which is explicitly the data layer only (see
 * docs/ARCHITECTURE.md, "Two Admin UIs, one data layer"). Used by Classic
 * Admin via a Twig filter (see PageInsightsPlugin::onTwigExtensions()) -
 * Admin2 (admin-next/pages/page-insights.js) handles this independently
 * with the browser's native Intl.DateTimeFormat instead, since it has no
 * PHP involved on that side at all.
 */
class LocalizedDate
{
    /**
     * ICU locale for each of this plugin's shipped admin languages (see
     * languages/{en,de,fr}.yaml) - deliberately the same short-code set
     * PageInsightsPlugin::mergeAdmin2TranslationAliases() already maps to
     * BCP47 codes, just aimed at IntlDateFormatter instead of Admin2's
     * /translations endpoint. Not a general-purpose locale mapping - only
     * covers the languages this plugin actually ships translations for.
     */
    private const ICU_LOCALES = [
        'de' => 'de_DE',
        'fr' => 'fr_FR',
        'en' => 'en_US',
    ];

    /**
     * Formats an ISO 'YYYY-MM-DD' day string (as produced by SQLite's
     * date() - see Stats::recentPages()) as a locale-aware long date, e.g.
     * "21. August 2026" for 'de', "August 21, 2026" for 'en'.
     *
     * Requires PHP's `intl` extension, which - like in Grav core itself
     * (see `Pages::orderCollection()`/`PageCollection::order()`, both
     * guarded by the same `extension_loaded('intl')` check before using
     * it for natural-language sorting) - is used opportunistically, not
     * assumed present: this plugin's composer.json does not (and should
     * not) list it as a hard requirement, since Grav itself doesn't treat
     * it as one either. Falls back to a neutral, unambiguous 'Y-m-d'
     * rendering rather than the previous hardcoded English `F jS` format
     * (see docs/HISTORY.md) when the extension
     * is missing or formatting otherwise fails - wrong-language-once was a
     * bug, "no worse than before" a safe floor to fall back to, following
     * the same fail-safe principle already used for the geo country lookup
     * (a missing optional capability degrades gracefully, never breaks
     * rendering or silently keeps showing the wrong thing).
     */
    public static function longDay(string $isoDay, string $languageCode): string
    {
        if ($isoDay === '') {
            return $isoDay;
        }

        try {
            $date = new \DateTimeImmutable($isoDay);
        } catch (\Throwable $e) {
            return $isoDay;
        }

        if (extension_loaded('intl')) {
            $locale = self::ICU_LOCALES[$languageCode] ?? self::ICU_LOCALES['en'];
            $formatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::LONG,
                \IntlDateFormatter::NONE
            );
            $formatted = $formatter->format($date);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        return $date->format('Y-m-d');
    }

    /**
     * Locale-appropriate day/month order and separator, no year - e.g.
     * "21.08." for 'de', "08/21" for 'en', "21/08" for 'fr'. No standard
     * ICU length constant produces "day+month, no year" directly (LONG/
     * MEDIUM/SHORT all include a year), so this uses a fixed custom
     * pattern per shipped locale instead - chosen to match, byte-for-byte,
     * what Admin2's own `Intl.DateTimeFormat(locale, {day: '2-digit',
     * month: '2-digit'})` already produces (admin-next/pages/
     * page-insights.js, `_formatDayLabel()`), so a chart axis looks the
     * same regardless of which admin UI is open. Same intl-availability
     * and fallback behaviour as longDay() above - falls back to the
     * previous fixed 'd.m.' rendering (this plugin's original, pre-
     * localization chart-axis format) rather than a neutral ISO one,
     * since axis labels need to stay short and this is what every
     * dashboard already showed before today.
     *
     * Deliberately omits the year, same as the Admin2 axis label it
     * mirrors - both only ever chart a single, currently-selected date
     * range shown elsewhere on the page, not an arbitrary multi-year
     * history, so the day+month is unambiguous in context.
     */
    public static function shortDay(string $isoDay, string $languageCode): string
    {
        if ($isoDay === '') {
            return $isoDay;
        }

        try {
            $date = new \DateTimeImmutable($isoDay);
        } catch (\Throwable $e) {
            return $isoDay;
        }

        if (extension_loaded('intl')) {
            $locale = self::ICU_LOCALES[$languageCode] ?? self::ICU_LOCALES['en'];
            $pattern = self::SHORT_DAY_PATTERNS[$languageCode] ?? self::SHORT_DAY_PATTERNS['en'];
            $formatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                null,
                null,
                $pattern
            );
            $formatted = $formatter->format($date);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        return $date->format('d.m.');
    }

    /**
     * Custom ICU patterns behind shortDay() - see its doc comment. Verified
     * to produce the exact same output as Admin2's
     * Intl.DateTimeFormat(locale, {day: '2-digit', month: '2-digit'}) for
     * each shipped locale: 'de' -> "21.08.", 'en' -> "08/21", 'fr' ->
     * "21/08".
     */
    private const SHORT_DAY_PATTERNS = [
        'de' => 'dd.MM.',
        'en' => 'MM/dd',
        'fr' => 'dd/MM',
    ];

    /**
     * Formats a Unix timestamp (not a day-only string like longDay()/
     * shortDay() above - the status displays this backs always carry a
     * time-of-day too) as a locale-aware date+time, e.g. "22.08.2026,
     * 09:15" for 'de', "8/22/2026, 9:15 AM" for 'en'. Used for the three
     * Classic Admin "next scheduled run" / "built at" status lines
     * (`next_geo_db_update`/`next_auto_prune` in stats.html.twig,
     * `builtAt` in widgets/geo-db-status.html.twig) - see
     * docs/ADMIN-UI.md's "Localized date formatting" section for why these
     * three were still unlocalized after that section's first round of
     * fixes (they didn't exist yet at the time), and docs/HISTORY.md
     * for how this one was found. Deliberately mirrors Admin2's own
     * `new Date(ts * 1000).toLocaleString()` for these exact same fields
     * (see admin-next/pages/page-insights.js) closely enough that both
     * admin UIs read the same way side by side, using IntlDateFormatter's
     * MEDIUM/SHORT (date/time) length rather than a fixed custom pattern
     * like shortDay() - unlike that day-only axis label, there's no
     * cross-UI byte-for-byte output to match here, `toLocaleString()`'s
     * own exact formatting is itself locale/browser-dependent, so this is
     * simply "the closest standard ICU equivalent", not a pixel-perfect
     * match. MEDIUM rather than SHORT for the date part specifically to
     * keep a full 4-digit year (`22.08.2026`, not the 2-digit `22.08.26`
     * ICU's own SHORT length uses for 'de') - a "next run" status line is
     * exactly the kind of place a truncated year invites misreading.
     *
     * Same intl-availability and fail-safe fallback approach as longDay()/
     * shortDay(): falls back to the previous fixed 'Y-m-d H:i' rendering
     * (this plugin's original, pre-localization format for all three call
     * sites) rather than a different neutral format, so a missing `intl`
     * extension never produces output no prior release ever showed there.
     */
    public static function dateTime(int $timestamp, string $languageCode): string
    {
        $date = (new \DateTimeImmutable())->setTimestamp($timestamp);

        if (extension_loaded('intl')) {
            $locale = self::ICU_LOCALES[$languageCode] ?? self::ICU_LOCALES['en'];
            $formatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT
            );
            $formatted = $formatter->format($date);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        return $date->format('Y-m-d H:i');
    }
}
