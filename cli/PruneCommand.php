<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\PageInsights\RelativeDate;
use Grav\Plugin\PageInsights\Stats;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * bin/plugin page-insights prune --older-than=<wert> [--yes] [--vacuum]
 *
 * Löscht "data"-Zeilen (Seitenaufrufe) älter als der angegebene Stichtag
 * sowie - immer, unabhängig vom Stichtag - alle "events"-Zeilen, die auf
 * keine verbleibende "data"-Zeile mehr zeigen (siehe Stats::pruneData()).
 * Manuelles Äquivalent zum optionalen, per Config gesteuerten automatischen
 * Prune-Job (PageInsightsPlugin::registerAutoPruneJob()) - beide rufen
 * dieselbe Methode auf.
 *
 * Für eine reine Bereinigung verwaister Events ohne Alters-Grenze siehe
 * `events:prune-orphans`; für ein eigenständiges VACUUM ohne vorheriges
 * Löschen siehe `vacuum`.
 */
class PruneCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('prune')
            ->setDescription('Löscht Statistik-Einträge älter als ein Stichdatum/Zeitraum')
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_REQUIRED,
                'Relativ ("90d", "12w", "6m", "1y") oder absolut ("2025-01-01")'
            )
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Sicherheitsabfrage überspringen')
            ->addOption('vacuum', null, InputOption::VALUE_NONE, 'Im Anschluss VACUUM ausführen (Datei tatsächlich verkleinern)')
            ->setHelp(
                'Beispiele:' . PHP_EOL .
                '  bin/plugin page-insights prune --older-than=180d --yes' . PHP_EOL .
                '  bin/plugin page-insights prune --older-than=2025-01-01 --vacuum'
            );
    }

    protected function serve(): int
    {
        $olderThan = $this->input->getOption('older-than');
        if (!is_string($olderThan) || $olderThan === '') {
            $this->output->writeln('<red>--older-than ist erforderlich, z. B. --older-than=180d oder --older-than=2025-01-01</red>');
            return 1;
        }

        $cutoff = RelativeDate::resolve($olderThan);
        if ($cutoff === null) {
            $this->output->writeln("<red>Ungültiger Wert für --older-than: '{$olderThan}' (erwartet z. B. '90d', '6m', '1y' oder '2025-01-01')</red>");
            return 1;
        }

        if (!$this->input->getOption('yes')) {
            $question = new ConfirmationQuestion(
                sprintf(
                    'Alle Statistik-Einträge vor dem %s werden unwiderruflich gelöscht. Fortfahren? [y/N] ',
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

        $deleted = $stats->pruneData($cutoff);
        $this->output->writeln(sprintf(
            '<green>%d Eintrag/Einträge gelöscht</green> (älter als %s; verwaiste Events wurden mitbereinigt).',
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
