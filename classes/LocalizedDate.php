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
     * (see docs/ARCHITECTURE.md, "Notable past bugs") when the extension
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
}
