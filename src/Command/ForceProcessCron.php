<?php

declare(strict_types=1);

namespace Dragonmantank\Sched\Command;

use Cron\CronExpression;
use Dragonmantank\Sched\LoggingTrait;
use Pheanstalk\Pheanstalk;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ForceProcessCron extends Command
{
    use LoggingTrait;

    protected static string $defaultName = 'cron:force-process';

    /**
     * @Inject({"config": "sched-config"})
     *
     * @param array{
     *      'pheanstalk': array<string, mixed>,
     *      'cron': array<
     *          int,
     *          array{'name': string, 'expression': string, 'worker': string|callable, 'options': null|array<string, mixed>}
     *      >,
     *      'queues': array<string, array{'worker': string|callable}>,
     *      'sched-config': array{'path': string}
     * } $config 
     */
    public function __construct(
        protected array $config,
        protected ContainerInterface $container,
        protected ?LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Reads in the cron from config and runs anything that is due')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of cron job to run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');

        foreach ($this->config['cron'] as $cronJob) {
            if ($cronJob['name'] === $name) {
                $this->log($output, LogLevel::DEBUG, $cronJob['name'] . ' found, force running');
                $worker = $cronJob['worker'];
                if (is_string($worker)) {
                    /** @var callable */
                    $worker = $this->container->get($worker);
                }
                $cronJob['options'] = $cronJob['options'] ?? [];
                $worker(...$cronJob['options']);

                $this->log($output, LogLevel::ALERT, $cronJob['name'] . ' found, force running');
                return Command::SUCCESS;
            }
        }

        $this->log($output, LogLevel::ERROR, $name . ' not found');
        return Command::FAILURE;
    }
}
