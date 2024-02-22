<?php

declare(strict_types=1);

namespace Dragonmantank\Sched\Command;

use DI\Annotation\Inject;
use Dragonmantank\Sched\LoggingTrait;
use Dragonmantank\Sched\Queue\QueueService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessQueue extends Command
{
    use LoggingTrait;

    protected static string $defaultName = 'queue:process';

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
        protected QueueService $queueService,
        protected ContainerInterface $container,
        protected ?LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Reads a queue and attempts to process it')
            ->addOption('number', 'x', InputOption::VALUE_REQUIRED, 'Number of jobs to process', 5)
            ->addArgument('queueName', InputArgument::REQUIRED, 'Queue to process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string */
        $queueName = $input->getArgument('queueName');
        $numberOfJobs = (int) $input->getOption('number');

        for ($i = 0; $i <= $numberOfJobs; $i++) {
            $this->log($output, LogLevel::DEBUG, 'Waiting for Job ' . $i . ' in ' . $queueName);
            $stats = $this->queueService->getStats($queueName);
            if ($stats[$queueName]['current-jobs-ready'] < 1) {
                exit(0);
            }

            $message = $this->queueService->receiveMessage($queueName);
            try {
                $this->log(
                    $output, 
                    LogLevel::DEBUG, 
                    'Received job ' . $message->id . ' in ' . $queueName
                );
                $payload = json_decode((string)$message->payload, true);
                $worker = $this->config['queues'][$queueName]['worker'];

                if (is_string($worker)) {
                    /** @var callable */
                    $worker = $this->container->get($worker);
                }

                if (!is_callable($worker)) {
                    throw new \InvalidArgumentException("Worker for {$queueName} is not callable");
                }

                $exitCode = $worker($payload);

                if ($exitCode === 0) {
                    $this->log(
                        $output, 
                        LogLevel::DEBUG, 
                        "Finished, deleting job {$message->id} from {$queueName}"
                    );
                    $this->queueService->deleteMessage($queueName, $message);
                } else {
                    $this->log(
                        $output, 
                        LogLevel::ERROR, 
                        "Worker for {$queueName} returned {$exitCode}, rescheduling job"
                    );
                    $stats = $this->queueService->getMessageStats($queueName, $message);
                    $this->log(
                        $output, 
                        LogLevel::DEBUG, 
                        "Job {$message->id} reserved {$stats['reserves']} times"
                    );

                    if ($stats['reserves'] > 3) {
                        $this->log(
                            $output, 
                            LogLevel::DEBUG, 
                            "Job {$message->id} buried due to bad worker results"
                        );
                        $this->queueService->buryMessage($queueName, $message);
                    } else {
                        $this->queueService->releaseMessage(
                            queueName: $queueName, 
                            message: $message, 
                            delay: 60
                        );
                    }
                }
            } catch (\Exception $e) {
                $this->log(
                    $output, 
                    LogLevel::DEBUG, 
                    "Received error, releasing job {$message->id} from {$queueName}"
                );
                $this->log($output, LogLevel::ERROR, $e->getMessage());
                $stats = $this->queueService->getMessageStats($queueName, $message);
                $this->log(
                    $output, 
                    LogLevel::DEBUG, 
                    "Job {$message->id} reserved {$stats['reserves']} times"
                );

                if ($stats['reserves'] > 3) {
                    $this->log($output, LogLevel::DEBUG, "Job {$message->id} buried");
                    $this->queueService->buryMessage($queueName, $message);
                }

                $this->queueService->releaseMessage($queueName, $message, delay: 60);
            }
        }

        return Command::SUCCESS;
    }
}
