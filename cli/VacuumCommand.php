<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\PageInsights\Stats;

/**
 * bin/plugin page-insights vacuum
 *
 * Führt VACUUM auf der Statistik-Datenbank aus, um den Plattenplatz
 * gelöschter Zeilen tatsächlich freizugeben (SQLite gibt deren Seiten sonst
 * nur intern zur Wiederverwendung frei, die Datei selbst bleibt auf ihrer
 * bisherigen Maximalgröße). Eigenständig nutzbar, unabhängig von `prune` -
 * siehe dessen --vacuum-Option für die Kombination in einem Lauf. Benötigt
 * kurzzeitig eine exklusive Sperre auf der Datenbankdatei.
 */
class VacuumCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('vacuum')
            ->setDescription('Verkleinert die Statistik-Datenbankdatei (VACUUM)');
    }

    protected function serve(): int
    {
        $config = Grav::instance()['config']->get('plugins.page-insights');
        $stats = new Stats((string) $config['db'], $config);

        $sizes = $stats->vacuum();
        $this->output->writeln(sprintf(
            '<green>VACUUM abgeschlossen</green> (%.1f MB → %.1f MB)',
            $sizes['before'] / 1024 / 1024,
            $sizes['after'] / 1024 / 1024
        ));

        return 0;
    }
}
