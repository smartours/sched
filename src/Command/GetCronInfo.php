<?php

declare(strict_types=1);

namespace Dragonmantank\Sched\Command;

use DI\Annotation\Inject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GetCronInfo extends Command
{
    protected static string $defaultName = 'cron:info';

    /**
     * @Inject({"config": "sched-config"})
     *
     * @param array{
     *      'pheanstalk': array<string, mixed>,
     *      'cron': array<
     *          int,
     *          array{'name': string, 'expression': string, 'worker': string|callable}
     *      >,
     *      'queues': array<string, array{'worker': string|callable}>,
     *      'sched-config': array{'path': string}
     * } $config
     */
    public function __construct(
        protected array $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Returns information about the queue');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        $headers = [];

        $headers = ['Job Name', 'Expression', 'Worker'];
        foreach ($this->config['cron'] as $data) {
            $rows[] = [$data['name'], $data['expression'], $data['worker']];
        }

        $table = new Table($output);
        $table
            ->setHeaders($headers)
            ->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }
}
