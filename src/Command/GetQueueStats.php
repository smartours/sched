<?php

declare(strict_types=1);

namespace Dragonmantank\Sched\Command;

use DI\Annotation\Inject;
use Dragonmantank\Sched\Queue\QueueService;
use Pheanstalk\Exception\ServerException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GetQueueStats extends Command
{
    protected static string $defaultName = 'queue:stats';

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
        protected QueueService $queueService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Returns information about the queue')
            ->addArgument('queueName', InputArgument::OPTIONAL, 'Queue to check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string */
        $queueName = $input->getArgument('queueName');
        $rows = [];
        $headers = [];

        if ($queueName) {
            $headers = ['Variable', 'Value'];
            try {
                $stats = $this->queueService->getStats($queueName);
            } catch (ServerException $e) {
                $output->writeln('Unable to get stats, tube does not exist');
                return Command::FAILURE;
            }

            $rows = [];
            foreach ($stats[$queueName] as $key => $value) {
                $rows[] = [$key, $value];
            }
        } else {
            $headers = ['Queue', 'Stats'];
            foreach ($this->config['queues'] as $queueName => $_) {
                try {
                    $stats = $this->queueService->getStats($queueName);
                } catch (ServerException $e) {
                    $rows[] = [$queueName, 'Tube is empty'];
                    continue;
                }
                $rows[] = [$queueName, "current-jobs-ready: " . $stats[$queueName]['current-jobs-ready']];
                $rows[] = ['', "current-jobs-reserved: " . $stats[$queueName]['current-jobs-reserved']];
                $rows[] = ['', "current-watching: " . $stats[$queueName]['current-watching']];
            }
        }

        $table = new Table($output);
        $table
            ->setHeaders($headers)
            ->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }
}
