<?php

declare(strict_types=1);

namespace Grav\Plugin\PageInsights\Geolocation;

/**
 * Result of a Geolocation::locate() call.
 *
 * Historically carried countryName/region/city as well (populated from the
 * committed IP2Location LITE BIN file). Neither the RIR delegated-stats data
 * this plugin now builds its own country index from, nor - it turns out -
 * any part of this plugin's actual code, ever used anything beyond
 * countryCode(): region/city were written to the stats DB but never
 * queried/displayed, and countryName() was never called at all (see the
 * 2026-08-15 session notes). Those three accessors are kept, returning
 * 'unknown', purely so Stats.php's existing column bindings don't need a
 * schema migration alongside this change.
 */
class GeolocationData
{
    private const UNKNOWN = 'unknown';

    public function __construct(private string $countryCode)
    {
    }

    public function countryCode(): string
    {
        return $this->countryCode;
    }

    public function countryName(): string
    {
        return self::UNKNOWN;
    }

    public function city(): string
    {
        return self::UNKNOWN;
    }

    public function region(): string
    {
        return self::UNKNOWN;
    }
}
