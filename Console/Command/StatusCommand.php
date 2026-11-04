<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Console\Command;

use Furan\InfraHealth\Model\ProbeRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the same probes as the admin dashboard; useful per node or from monitoring scripts.
 */
class StatusCommand extends Command
{
    // Command::SUCCESS needs Symfony 5.1+.
    private const EXIT_OK = 0;
    private const EXIT_DOWN = 1;
    private const STATUS_STYLE = ['ok' => 'info', 'tip' => 'fg=cyan', 'warn' => 'comment', 'down' => 'error', 'off' => 'fg=gray'];

    public function __construct(private ProbeRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('infrahealth:status')
            ->setDescription('Show infrastructure health (Redis, search, RabbitMQ, MySQL, this node)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the raw JSON snapshot');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $snapshot = $this->runner->run();
        if ($input->getOption('json')) {
            $output->writeln((string)json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::EXIT_OK;
        }

        $worst = self::EXIT_OK;
        foreach ($snapshot['services'] as $service) {
            $style = self::STATUS_STYLE[$service['status']] ?? 'info';
            $output->writeln(sprintf(
                '<%1$s>%2$-5s</%1$s> %3$-14s %4$-22s %5$9s  %6$s',
                $style,
                strtoupper($service['status']),
                $service['label'],
                $service['instance'],
                $service['latency_ms'] === null ? '' : $service['latency_ms'] . ' ms',
                $service['error'] ?? $service['summary']
            ));
            foreach ($service['tips'] ?? [] as $tip) {
                $output->writeln('      <fg=cyan>tip:</> ' . $tip);
            }
            if ($service['status'] === 'down') {
                $worst = self::EXIT_DOWN;
            }
        }
        $output->writeln(sprintf('<fg=gray>node %s · %s ms</>', $snapshot['node']['hostname'], $snapshot['duration_ms']));
        return $worst;
    }
}
