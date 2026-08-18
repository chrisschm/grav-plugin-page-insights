<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\PageInsights\Stats;

/**
 * bin/plugin page-insights events:prune-orphans
 *
 * Löscht "events"-Zeilen, deren "session_id" auf keine vorhandene
 * "data"-Zeile mehr zeigt - unabhängig von jedem Alters-Stichtag. `prune`
 * ruft dieselbe Methode ohnehin nach jedem Lauf automatisch mit auf; dieser
 * eigenständige Befehl ist für eine einmalige Bereinigung schon bestehender
 * Verwaisung gedacht (der Fremdschlüssel "events.session_id REFERENCES
 * data (id)" wird von dieser Verbindung nie durchgesetzt, siehe
 * Stats::collectEvent() - solche Reste können also unabhängig von diesem
 * Plugin-Feature schon vorher entstanden sein).
 */
class EventsPruneOrphansCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('events:prune-orphans')
            ->setDescription('Löscht verwaiste Events (ohne zugehörige Statistik-Zeile), unabhängig vom Alter');
    }

    protected function serve(): int
    {
        $config = Grav::instance()['config']->get('plugins.page-insights');
        $stats = new Stats((string) $config['db'], $config);

        $deleted = $stats->pruneOrphanedEvents();
        $this->output->writeln("<green>{$deleted} verwaiste(s) Event(s) gelöscht.</green>");

        return 0;
    }
}
