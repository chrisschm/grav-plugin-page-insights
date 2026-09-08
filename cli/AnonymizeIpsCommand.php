<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\PageInsights\RelativeDate;
use Grav\Plugin\PageInsights\Stats;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * bin/plugin page-insights anonymize-ips --older-than=<wert> [--yes]
 *
 * Maskiert "data.ip" (siehe Stats::maskIp()) fuer Zeilen aelter als der
 * angegebene Stichtag - unabhaengig davon, ob "anonymize_ips" (sofortige
 * Anonymisierung) an ist (Re-Maskierung einer bereits maskierten IP ist
 * idempotent, siehe maskIp()s Docblock). Manuelles Aequivalent zum
 * optionalen, per Config gesteuerten automatischen Job
 * (PageInsightsPlugin::registerIpAnonymizeJob()) - beide rufen dieselbe
 * Methode auf.
 *
 * Anders als `prune` gibt es hier kein `--vacuum`: eine Anonymisierung ist
 * ein UPDATE, kein DELETE, und aendert die Dateigroesse nicht.
 */
class AnonymizeIpsCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('anonymize-ips')
            ->setDescription('Maskiert IP-Adressen aelter als ein Stichdatum/Zeitraum')
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_REQUIRED,
                'Relativ ("7d", "14d", "30d") oder absolut ("2025-01-01")'
            )
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Sicherheitsabfrage ueberspringen')
            ->setHelp(
                'Beispiele:' . PHP_EOL .
                '  bin/plugin page-insights anonymize-ips --older-than=30d --yes' . PHP_EOL .
                '  bin/plugin page-insights anonymize-ips --older-than=2025-01-01'
            );
    }

    protected function serve(): int
    {
        $olderThan = $this->input->getOption('older-than');
        if (!is_string($olderThan) || $olderThan === '') {
            $this->output->writeln('<red>--older-than ist erforderlich, z. B. --older-than=30d oder --older-than=2025-01-01</red>');
            return 1;
        }

        $cutoff = RelativeDate::resolve($olderThan);
        if ($cutoff === null) {
            $this->output->writeln("<red>Ungueltiger Wert fuer --older-than: '{$olderThan}' (erwartet z. B. '7d', '30d' oder '2025-01-01')</red>");
            return 1;
        }

        if (!$this->input->getOption('yes')) {
            $question = new ConfirmationQuestion(
                sprintf(
                    'Alle IP-Adressen vor dem %s werden maskiert (nicht umkehrbar). Fortfahren? [y/N] ',
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

        $masked = $stats->anonymizeAgedIps($cutoff);
        $this->output->writeln(sprintf(
            '<green>%d Eintrag/Eintraege maskiert</green> (aelter als %s).',
            $masked,
            $cutoff->format('Y-m-d H:i')
        ));

        return 0;
    }
}
