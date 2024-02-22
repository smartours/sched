<?php

declare(strict_types=1);

namespace Dragonmantank\Sched\Command;

use Dragonmantank\Sched\Queue\Message;
use Dragonmantank\Sched\Queue\QueueService;
use Pheanstalk\Contract\PheanstalkInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueueAddJob extends Command
{
    protected static string $defaultName = 'queue:add-job';

    public function __construct(
        protected QueueService $queueService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Returns the next payload in a queue')
            ->addOption('ttr', 't', InputOption::VALUE_REQUIRED, 'TTR for the job', Message::DEFAULT_TTR)
            ->addArgument('queueName', InputArgument::REQUIRED, 'Queue to add job to')
            ->addArgument('payload', InputArgument::REQUIRED, 'String payload to add to queue');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $message = $this->queueService->sendMessage(
            queueName: $input->getArgument('queueName'),
            payload: $input->getArgument('payload'),
            ttr: (int) $input->getOption('ttr')
        );

        $output->writeln("Job " . $message->id . " added to queue " . $input->getArgument('queueName'));

        return Command::SUCCESS;
    }
}
