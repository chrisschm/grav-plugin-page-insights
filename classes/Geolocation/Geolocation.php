<?php

declare(strict_types=1);

namespace Grav\Plugin\PageInsights\Geolocation;

class Geolocation
{
    public function __construct(private CountryLookup $lookup)
    {
    }

    /**
     * Returns GeoLocation data for the passed ip. Never throws - a missing
     * or not-yet-built country index, or an IP that falls in an
     * unallocated/reserved range, simply resolves to 'unknown', same as
     * before. This runs on every page hit (see
     * PageInsightsPlugin::collectPageData()), so it must stay side-effect
     * free and never attempt to build/download anything itself - building
     * the index is a separate, explicit admin action (see
     * CountryIndexBuilder and PageInsightsApiController::rebuildGeoDb()).
     *
     * @param string $ip
     * @return GeolocationData
     */
    public function locate($ip): GeolocationData
    {
        $countryCode = null;
        try {
            $countryCode = $this->lookup->lookup($ip);
        } catch (\Throwable $e) {
            error_log('could not locate ip ' . $ip . ' because of ' . $e->getMessage());
        }

        return new GeolocationData($countryCode ?? 'unknown');
    }
}
