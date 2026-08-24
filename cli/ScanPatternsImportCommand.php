<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\PageInsights\Stats;
use Symfony\Component\Console\Input\InputOption;

/**
 * bin/plugin page-insights scan-patterns:import [--file=<pfad>] [--source=<name>]
 *
 * Füllt die "scan_patterns"-Tabelle (siehe docs/DATABASES.md) per Diff:
 * jedes Muster aus der Quelldatei, das noch nicht in der Tabelle steht, wird
 * eingefügt (Stats::importScanPatterns()); bereits vorhandene Zeilen - auch
 * vom Admin manuell hinzugefügte oder deaktivierte - bleiben unangetastet.
 * Ohne --file wird die im Plugin mitgelieferte Standardliste verwendet
 * (data/scan-patterns-webexploits.txt, siehe deren Kopfkommentar für
 * Quelle/Lizenz). Beliebig oft wiederholbar, z. B. nach einer Aktualisierung
 * dieser Datei in einer neueren Plugin-Version.
 */
class ScanPatternsImportCommand extends ConsoleCommand
{
    private const DEFAULT_FILE = __DIR__ . '/../data/scan-patterns-webexploits.txt';
    private const DEFAULT_SOURCE = 'webexploits';

    protected function configure(): void
    {
        $this
            ->setName('scan-patterns:import')
            ->setDescription('Importiert Scan-Erkennungsmuster (Diff, bestehende Einträge bleiben unangetastet)')
            ->addOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED,
                'Pfad zu einer eigenen Musterliste (eine Zeile pro Muster, "#"-Kommentare erlaubt) statt der mitgelieferten Standardliste'
            )
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'Wert für die "source"-Spalte neu eingefügter Zeilen',
                self::DEFAULT_SOURCE
            )
            ->setHelp(
                'Beispiele:' . PHP_EOL .
                '  bin/plugin page-insights scan-patterns:import' . PHP_EOL .
                '  bin/plugin page-insights scan-patterns:import --file=/pfad/zu/eigener-liste.txt --source=eigene-liste'
            );
    }

    protected function serve(): int
    {
        $file = (string) ($this->input->getOption('file') ?: self::DEFAULT_FILE);
        $source = (string) $this->input->getOption('source');

        if (!is_readable($file)) {
            $this->output->writeln("<red>Datei nicht lesbar: {$file}</red>");
            return 1;
        }

        $patterns = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $patterns[] = $line;
        }

        if (!$patterns) {
            $this->output->writeln('<yellow>Keine Muster in der Datei gefunden - nichts zu tun.</yellow>');
            return 0;
        }

        $config = Grav::instance()['config']->get('plugins.page-insights');
        $stats = new Stats((string) $config['db'], $config);

        $inserted = $stats->importScanPatterns($patterns, $source);
        $skipped = count($patterns) - $inserted;

        $this->output->writeln(sprintf(
            '<green>%d neue/s Muster eingefügt</green> (%d bereits vorhanden, unverändert gelassen; %d insgesamt in der Datei).',
            $inserted,
            $skipped,
            count($patterns)
        ));

        return 0;
    }
}
