<?php

declare(strict_types=1);

namespace Dragonmantank\Sched\Command;

use Dragonmantank\Sched\LoggingTrait;
use Dragonmantank\Sched\Queue\QueueService;
use Pheanstalk\Exception\ServerException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class RunManager extends Command
{
    use LoggingTrait;

    protected static string $defaultName = 'manager:run';

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
     *      'sched-config': array{'path': string},
     *      'manager': array{max_workers:int, max_workers_per_tube:int}
     * } $config
     */
    public function __construct(
        protected array $config,
        protected QueueService $queueService,
        protected ?LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp('Starts up the manager process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobs = [];

        /** @phpstan-ignore-next-line */
        while (true) {
            $total = 0;
            foreach ($jobs as $queueName => $procs) {
                $total += count($jobs[$queueName]);
            }

            if ($total >= $this->config['manager']['max_workers']) {
                $this->log($output, LogLevel::NOTICE, "[Manager] Reached the total maximum of workers");
                goto checkProcesses;
            }

            foreach ($this->config['queues'] as $queueName => $data) {
                if (!isset($jobs[$queueName])) {
                    $jobs[$queueName] = [];
                }

                try {
                    $stats = $this->queueService->getStats($queueName)[$queueName];
                } catch (ServerException $e) {
                    $this->log($output, LogLevel::NOTICE, "[Manager] [" . $queueName . "] Empty or does not exist, skipping");
                    continue;
                }
                $this->log($output, LogLevel::DEBUG, "[Manager] [" . $queueName . "] Checking to see if we need any workers");

                $neededWorkers = ceil((int) $stats['current-jobs-ready'] / 5);
                if (count($jobs[$queueName]) < $neededWorkers) {
                    $this->log($output, LogLevel::NOTICE, "[Manager] [" . $queueName . "] Need " . $neededWorkers . " workers");

                    if (count($jobs[$queueName]) >= $this->config['manager']['max_workers_per_tube']) {
                        $this->log($output, LogLevel::NOTICE, "[Manager] [" . $queueName . "] Manager reached max workers");
                        break;
                    }

                    if ($neededWorkers > $this->config['manager']['max_workers_per_tube']) {
                        $this->log($output, LogLevel::DEBUG, "[Manager] [" . $queueName . "] Capping needed workers at " . $this->config['manager']['max_workers_per_tube']);
                        $neededWorkers = $this->config['manager']['max_workers_per_tube'];
                    }

                    for ($i = 0; $i <= $neededWorkers - count($jobs[$queueName]); $i++) {
                        $this->log($output, LogLevel::NOTICE, "[Manager] [" . $queueName . "] Spawning worker");
                        $command = [
                            'php',
                            'sched-manager',
                            'queue:process',
                            '--config',
                            $this->config['sched-config']['path'],
                            '--',
                            $queueName
                        ];

                        /** @phpstan-ignore-next-line */
                        $proc = new Process($command, realpath(__DIR__ . '/../../bin'));
                        $proc->start();
                        $jobs[$queueName][] = ['proc' => $proc];
                    }
                }
            }

            checkProcesses:
            foreach ($this->config['queues'] as $queueName => $data) {
                $this->log($output, LogLevel::DEBUG, "[Manager] [" . $queueName . "] Checking processes");
                foreach ($jobs[$queueName] as $id => $procInfo) {
                    if (!$procInfo['proc']->isRunning()) {
                        unset($jobs[$queueName][$id]);
                        $this->log($output, LogLevel::DEBUG, "[Manager] [" . $queueName . "] Closed job " . $id . ' from ' . $queueName);
                    }
                }
            }

            $this->log($output, LogLevel::DEBUG, "[Manager] Sleeping for 5 seconds");
            sleep(5);
        }
    }
}
