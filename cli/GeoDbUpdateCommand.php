<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\PageInsights\Geolocation\GeoDbUpdater;
use Symfony\Component\Console\Input\InputOption;

/**
 * bin/plugin page-insights geo-db:update [--mode=prebuilt|raw]
 *
 * Manual/scriptable equivalent of the "Update now" button next to Top
 * countries in both admin surfaces (see PageInsightsPlugin::
 * handleGeoDbRebuildPost() and PageInsightsApiController::rebuildGeoDb())
 * and of the scheduled job PageInsightsPlugin::onSchedulerInitialized()
 * registers when geo_db_auto_update is set to weekly/monthly - all three
 * call the same GeoDbUpdater::update(), so behaviour is identical
 * regardless of what triggered it.
 */
class GeoDbUpdateCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('geo-db:update')
            ->setDescription('Aktualisiert die selbstgebaute Geo-Länder-Datenbank')
            ->addOption(
                'mode',
                null,
                InputOption::VALUE_REQUIRED,
                'Überschreibt geo_db_source_mode für diesen Lauf ("prebuilt" oder "raw")'
            )
            ->setHelp(
                'Beispiele:' . PHP_EOL .
                '  bin/plugin page-insights geo-db:update' . PHP_EOL .
                '  bin/plugin page-insights geo-db:update --mode=raw'
            );
    }

    protected function serve(): int
    {
        $config = Grav::instance()['config']->get('plugins.page-insights');

        $indexPath = (string) ($config['geo_db_index_path'] ?? 'user/data/page-insights/geo-country-index.bin');
        $mode = (string) ($this->input->getOption('mode') ?: ($config['geo_db_source_mode'] ?? 'prebuilt'));
        $prebuiltUrl = (string) ($config['geo_db_prebuilt_url'] ?? '');
        $rawSourceUrl = (string) ($config['geo_db_source_url'] ?? '');

        try {
            $result = (new GeoDbUpdater())->update($indexPath, $mode, $prebuiltUrl ?: null, $rawSourceUrl ?: null);
        } catch (\Throwable $e) {
            $this->output->writeln('<red>Aktualisierung fehlgeschlagen: ' . $e->getMessage() . '</red>');
            return 1;
        }

        $builtAt = $result['builtAt'] ?? null;

        // Deliberately reports both dates, not just sourceDate: the RIR
        // snapshot date and the (companion-repo or local) build timestamp
        // are two different things that normally differ by roughly a day
        // (a nightly build packages the previous day's already-published
        // snapshot) - see docs/ARCHITECTURE.md "CLI commands" for the full
        // explanation. Showing only sourceDate here (as before) looked like
        // a mismatch against the admin dashboards, which always show both.
        $this->output->writeln(sprintf(
            '<green>Geo-Länder-Datenbank aktualisiert</green> (%d IPv4- + %d IPv6-Einträge, Datenstand: %s, erstellt: %s)',
            $result['ipv4Entries'] ?? 0,
            $result['ipv6Entries'] ?? 0,
            $result['sourceDate'] ?? 'unbekannt',
            $builtAt !== null ? date('Y-m-d H:i', $builtAt) : 'unbekannt'
        ));

        return 0;
    }
}
