<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\PageInsights\RelativeDate;
use Grav\Plugin\PageInsights\Stats;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * bin/plugin page-insights prune:scan-alerts --older-than=<wert> [--yes] [--vacuum]
 *
 * Löscht "scan_alerts"-Zeilen (Scan-Detection-Alarme, siehe docs/DATABASES.md)
 * deren letzte Aktivität ("last_seen") vor dem angegebenen Stichtag liegt
 * (siehe Stats::pruneScanAlerts()). Manuelles Äquivalent zum optionalen,
 * per Config gesteuerten automatischen Job
 * (PageInsightsPlugin::registerScanAlertsPruneJob(), Config
 * "scan_alerts_auto_prune_older_than") - beide rufen dieselbe Methode auf.
 *
 * Eigenständig von `prune` (dem Äquivalent für die "data"-Tabelle) - beide
 * Tabellen haben unabhängig konfigurierbare Aufbewahrungsfristen, siehe
 * docs/ARCHITECTURE.md "Scan detection" für die DSGVO-Begründung, warum
 * "scan_alerts" bewusst eine eigene, tendenziell längere Frist als "data"
 * hat (Erwägungsgrund 49 DSGVO).
 */
class PruneScanAlertsCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('prune:scan-alerts')
            ->setDescription('Löscht Scan-Detection-Alarme älter als ein Stichdatum/Zeitraum')
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_REQUIRED,
                'Relativ ("30d", "90d", "1y") oder absolut ("2025-01-01")'
            )
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Sicherheitsabfrage überspringen')
            ->addOption('vacuum', null, InputOption::VALUE_NONE, 'Im Anschluss VACUUM ausführen (Datei tatsächlich verkleinern)')
            ->setHelp(
                'Beispiele:' . PHP_EOL .
                '  bin/plugin page-insights prune:scan-alerts --older-than=90d --yes' . PHP_EOL .
                '  bin/plugin page-insights prune:scan-alerts --older-than=2025-01-01 --vacuum'
            );
    }

    protected function serve(): int
    {
        $olderThan = $this->input->getOption('older-than');
        if (!is_string($olderThan) || $olderThan === '') {
            $this->output->writeln('<red>--older-than ist erforderlich, z. B. --older-than=90d oder --older-than=2025-01-01</red>');
            return 1;
        }

        $cutoff = RelativeDate::resolve($olderThan);
        if ($cutoff === null) {
            $this->output->writeln("<red>Ungültiger Wert für --older-than: '{$olderThan}' (erwartet z. B. '30d', '90d', '1y' oder '2025-01-01')</red>");
            return 1;
        }

        if (!$this->input->getOption('yes')) {
            $question = new ConfirmationQuestion(
                sprintf(
                    'Alle Scan-Alarme mit letzter Aktivität vor dem %s werden unwiderruflich gelöscht. Fortfahren? [y/N] ',
                    $cutoff->format('Y-m-d H:i')
                ),
                false
            );
            if (!$this->getHelper('question')->ask($this->input, $this->output, $question)) {
                $this->output->writeln('Abgebrochen.');
                return 0;
            }
        }

        $config = Grav::instance()['config']->get('plugins.page-insights');
        $stats = new Stats((string) $config['db'], $config);

        $deleted = $stats->pruneScanAlerts($cutoff);
        $this->output->writeln(sprintf(
            '<green>%d Eintrag/Einträge gelöscht</green> (letzte Aktivität vor %s).',
            $deleted,
            $cutoff->format('Y-m-d H:i')
        ));

        if ($this->input->getOption('vacuum')) {
            $this->output->writeln('VACUUM läuft ...');
            $sizes = $stats->vacuum();
            $this->output->writeln(sprintf(
                '<green>VACUUM abgeschlossen</green> (%.1f MB → %.1f MB)',
                $sizes['before'] / 1024 / 1024,
                $sizes['after'] / 1024 / 1024
            ));
        }

        return 0;
    }
}
