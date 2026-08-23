<?php

declare(strict_types=1);

namespace Grav\Plugin\PageInsights\Geolocation;

/**
 * Single place that decides *how* to (re)build the geo country index,
 * shared by both admin surfaces that trigger it
 * (PageInsightsPlugin::handleGeoDbRebuildPost() for Classic Admin,
 * PageInsightsApiController::rebuildGeoDb() for Admin2) so the
 * prebuilt-vs-raw branching only exists once.
 *
 * Two modes, selected via the `geo_db_source_mode` config field:
 *  - "prebuilt" (default): download an already-built index from a
 *    companion repository's CI job (CountryIndexBuilder::fetchPrebuilt())
 *    - no parsing/sorting on this site's own process, no elevated
 *    memory_limit needed here at all.
 *  - "raw": download the full RIR delegated-stats snapshot and build the
 *    index locally (CountryIndexBuilder::build()) - the original,
 *    self-contained path, kept as an explicit opt-out for anyone who'd
 *    rather not trust the companion repo/CI pipeline as a middleman and is
 *    fine with the larger download and temporarily raised memory_limit
 *    that entails. See docs/GEOLOCATION.md for the full
 *    reasoning behind offering both.
 *
 * Any other/unrecognized value for geo_db_source_mode falls back to
 * "prebuilt" (the safer, lighter-weight default) rather than failing -
 * consistent with the rest of this plugin's config handling, which treats
 * blueprint fields as advisory, not as the sole gate on valid input.
 */
final class GeoDbUpdater
{
    public function __construct(private CountryIndexBuilder $builder = new CountryIndexBuilder())
    {
    }

    /**
     * @return array{
     *     builtAt: ?int,
     *     sourceDate: ?string,
     *     sourceUrl: string,
     *     recordsParsed: ?int,
     *     ipv4Entries: int,
     *     ipv6Entries: int,
     *     fileSize: int,
     * }
     *
     * @throws \RuntimeException on download/build failure (from either
     *   underlying CountryIndexBuilder method) - never caught here, both
     *   call sites already wrap this in their own try/catch for
     *   user-facing error reporting.
     */
    public function update(
        string $outputPath,
        string $mode,
        ?string $prebuiltUrl,
        ?string $rawSourceUrl
    ): array {
        if ($mode === 'raw') {
            return $this->builder->build($outputPath, $rawSourceUrl ?: null);
        }

        return $this->builder->fetchPrebuilt($outputPath, $prebuiltUrl ?: null);
    }
}
