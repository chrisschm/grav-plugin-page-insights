<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\PageInsights\Stats;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * bin/plugin page-insights prune:notfound [--yes] [--vacuum]
 *
 * Löscht alle "data"-Zeilen mit http_code = 404, unabhängig von ihrem Alter -
 * sowie im Anschluss, wie bei `prune`, alle dadurch verwaisten "events"-
 * Zeilen (Stats::pruneNotFoundHits() ruft intern dieselbe
 * pruneOrphanedEvents()-Logik auf). CLI-Äquivalent zur "404-Treffer
 * löschen"-Option im Admin2-Dialog "Datenbank bereinigen" (POST
 * /page-insights/db/maintain, action=prune_notfound - siehe
 * PageInsightsApiController::maintainDb()); anders als dort gibt es hier
 * keine Klassik-Admin-Entsprechung, ebenso wie bei den übrigen
 * Wartungsaktionen.
 *
 * Getrennt von `prune` gehalten, weil 404-Zeilen nicht über ein Alters-
 * Stichdatum, sondern über den HTTP-Statuscode selbst definiert sind - ein
 * eigener Befehl vermeidet eine verwirrende Vermischung zweier unabhängiger
 * Lösch-Kriterien in einer einzigen --older-than-Option.
 */
class PruneNotFoundCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('prune:notfound')
            ->setDescription('Löscht alle Statistik-Einträge mit HTTP-Status 404')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Sicherheitsabfrage überspringen')
            ->addOption('vacuum', null, InputOption::VALUE_NONE, 'Im Anschluss VACUUM ausführen (Datei tatsächlich verkleinern)')
            ->setHelp(
                'Beispiele:' . PHP_EOL .
                '  bin/plugin page-insights prune:notfound --yes' . PHP_EOL .
                '  bin/plugin page-insights prune:notfound --vacuum'
            );
    }

    protected function serve(): int
    {
        if (!$this->input->getOption('yes')) {
            $question = new ConfirmationQuestion(
                'Alle Statistik-Einträge mit HTTP-Status 404 werden unwiderruflich gelöscht. Fortfahren? [y/N] ',
                false
            );
            if (!$this->getHelper('question')->ask($this->input, $this->output, $question)) {
                $this->output->writeln('Abgebrochen.');
                return 0;
            }
        }

        $config = Grav::instance()['config']->get('plugins.page-insights');
        $stats = new Stats((string) $config['db'], $config);

        $deleted = $stats->pruneNotFoundHits();
        $this->output->writeln(sprintf(
            '<green>%d Eintrag/Einträge gelöscht</green> (HTTP 404; verwaiste Events wurden mitbereinigt).',
            $deleted
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
