<?php

declare(strict_types=1);

namespace Dragonmantank\Sched\Command;

use DI\Annotation\Inject;
use Dragonmantank\Sched\LoggingTrait;
use Pheanstalk\Pheanstalk;
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

    protected static $defaultName = 'queue:process';

    /**
     * @Inject({"config": "config"})
     *
     * @param array{
     *      'pheanstalk': array<string, mixed>,
     *      'cron': array<
     *          int,
     *          array{'name': string, 'expression': string, 'worker': string|callable}
     *      >,
     *      'queues': array<string, array{'worker': string|callable}>,
     *      'config': array{'path': string}
     * } $config
     */
    public function __construct(
        protected array $config,
        protected Pheanstalk $pheanstalk,
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var string */
        $queueName = $input->getArgument('queueName');
        $numberOfJobs = (int) $input->getOption('number');

        $this->pheanstalk->watch($queueName);
        for ($i = 0; $i <= $numberOfJobs; $i++) {
            $this->log($output, LogLevel::DEBUG, 'Waiting for Job ' . $i . ' in ' . $queueName);
            $stats = $this->pheanstalk->statsTube($queueName);
            if ($stats['current-jobs-ready'] < 1) {
                exit(0);
            }

            $job = $this->pheanstalk->reserve();
            try {
                $this->log($output, LogLevel::DEBUG, 'Received job ' . $job->getId() . ' in ' . $queueName);
                $payload = json_decode($job->getData(), true);
                $worker = $this->config['queues'][$queueName]['worker'];

                if (is_string($worker)) {
                    /** @var callable */
                    $worker = $this->container->get($worker);
                }

                if (!is_callable($worker)) {
                    throw new \InvalidArgumentException('Worker is not callable');
                }

                $exitCode = $worker($payload);

                if ($exitCode === 0) {
                    $this->log($output, LogLevel::DEBUG, 'Finished, deleting job ' . $job->getId() . ' from ' . $queueName);
                    $this->pheanstalk->delete($job);
                } else {
                    $this->log($output, LogLevel::ERROR, 'Worker returned ' . $exitCode . ', rescheduling job for 60 seconds');
                    $this->pheanstalk->release(job: $job, delay: 60);
                }
            } catch (\Exception $e) {
                $this->log($output, LogLevel::DEBUG, 'Received error, releasing job ' . $job->getId() . ' from ' . $queueName);
                $this->log($output, LogLevel::ERROR, $e->getMessage());
                $stats = $this->pheanstalk->statsJob($job);
                $this->log($output, LogLevel::DEBUG, 'Job ' . $job->getId() . ' reserved ' . $stats['reserves'] . ' times');

                if ($stats['reserves'] > 3) {
                    $this->log($output, LogLevel::DEBUG, 'Job ' . $job->getId() . ' buried');
                    $this->pheanstalk->bury($job);
                }

                $this->pheanstalk->release(job: $job, delay: 60);
            }
        }

        return Command::SUCCESS;
    }
}
