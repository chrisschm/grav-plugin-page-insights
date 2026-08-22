<?php

namespace Grav\Plugin\Console;

use DateTimeImmutable;
use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\PageInsights\RelativeDate;
use Grav\Plugin\PageInsights\Stats;
use Symfony\Component\Console\Input\InputOption;

/**
 * bin/plugin page-insights rollup:build [--date=YYYY-MM-DD] [--from=<Wert> [--to=<Wert>]]
 *
 * (Re-)berechnet rollup_daily/rollup_route (Stats::rollupDay(), siehe
 * docs/DATABASES.md "Rollups") für einen oder mehrere abgeschlossene Tage.
 * Manuelles Äquivalent zum optionalen, per Config gesteuerten automatischen
 * Rollup-Job (PageInsightsPlugin::registerRollupBuildJob()) - beide rufen
 * dieselbe Methode auf.
 *
 * Ohne jede Option: holt nur nach, was seit dem letzten Lauf fehlt (bis
 * einschließlich gestern) - bei einer frischen Installation ohne
 * bisherigen Rollup-Stand ist das bewusst nur "gestern", nicht die
 * komplette Historie. Für eine einmalige Rückwirkend-Befüllung bestehender
 * Installationen explizit --from angeben (z. B. --from=365d für das letzte
 * Jahr) - eine potenziell lang laufende, ressourcenintensive Operation soll
 * nicht durch einen parameterlosen ersten Aufruf überraschend ausgelöst
 * werden.
 */
class RollupBuildCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('rollup:build')
            ->setDescription('Berechnet die Dashboard-Rollup-Tabellen für einen oder mehrere Tage neu')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Genau ein Tag, z. B. --date=2026-08-01')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Beginn eines Zeitraums, relativ ("365d") oder absolut ("2025-01-01")')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Ende eines Zeitraums (Default: gestern), gleiches Format wie --from')
            ->setHelp(
                'Beispiele:' . PHP_EOL .
                '  bin/plugin page-insights rollup:build' . PHP_EOL .
                '  bin/plugin page-insights rollup:build --date=2026-08-01' . PHP_EOL .
                '  bin/plugin page-insights rollup:build --from=365d   (einmalige Rückwirkend-Befüllung)'
            );
    }

    protected function serve(): int
    {
        $config = Grav::instance()['config']->get('plugins.page-insights');
        $stats = new Stats((string) $config['db'], $config);

        $today = new DateTimeImmutable('today');
        $yesterday = $today->modify('-1 day');

        [$from, $to, $error] = $this->resolveRange($stats, $today, $yesterday);
        if ($error !== null) {
            $this->output->writeln("<red>{$error}</red>");
            return 1;
        }

        if ($from > $to) {
            $this->output->writeln('<comment>Nichts zu tun (bereits auf dem aktuellen Stand).</comment>');
            return 0;
        }

        $days = 0;
        $day = $from;
        while ($day <= $to) {
            if ($day >= $today) {
                $this->output->writeln(sprintf(
                    '<comment>Hinweis: %s ist heute oder in der Zukunft, also noch nicht abgeschlossen - das Ergebnis wird beim nächsten Lauf überschrieben.</comment>',
                    $day->format('Y-m-d')
                ));
            }

            $result = $stats->rollupDay($day);
            $this->output->writeln(sprintf(
                '<green>%s</green>: %d Bot/Nicht-Bot-Zeilen, %d Seiten-Zeilen',
                $result['day'],
                $result['daily_rows'],
                $result['route_rows']
            ));

            $days++;
            $day = $day->modify('+1 day');
        }

        $this->output->writeln(sprintf('<green>Fertig</green> (%d Tag/Tage verarbeitet).', $days));

        return 0;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: string|null}
     */
    private function resolveRange(Stats $stats, DateTimeImmutable $today, DateTimeImmutable $yesterday): array
    {
        $dateOpt = $this->input->getOption('date');
        $fromOpt = $this->input->getOption('from');
        $toOpt = $this->input->getOption('to');

        if ($dateOpt !== null) {
            if ($fromOpt !== null || $toOpt !== null) {
                return [$today, $today, '--date lässt sich nicht mit --from/--to kombinieren.'];
            }
            $date = RelativeDate::resolve((string) $dateOpt);
            if ($date === null) {
                return [$today, $today, "Ungültiger Wert für --date: '{$dateOpt}' (erwartet z. B. '2026-08-01')."];
            }
            return [$date, $date, null];
        }

        if ($fromOpt !== null) {
            $from = RelativeDate::resolve((string) $fromOpt);
            if ($from === null) {
                return [$today, $today, "Ungültiger Wert für --from: '{$fromOpt}'."];
            }
            $to = $yesterday;
            if ($toOpt !== null) {
                $to = RelativeDate::resolve((string) $toOpt);
                if ($to === null) {
                    return [$today, $today, "Ungültiger Wert für --to: '{$toOpt}'."];
                }
            }
            return [$from, $to, null];
        }

        if ($toOpt !== null) {
            return [$today, $today, '--to ohne --from ergibt keinen Sinn.'];
        }

        // Kein Argument: nur nachholen, was seit dem letzten Lauf fehlt.
        $lastDone = $stats->rollupStatus();
        $from = $lastDone !== null ? (new DateTimeImmutable($lastDone))->modify('+1 day') : $yesterday;

        return [$from, $yesterday, null];
    }
}
