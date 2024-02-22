<?php

declare(strict_types=1);

namespace Dragonmantank\Sched\Command;

use Dragonmantank\Sched\Exception\NoMessageInQueueException;
use Dragonmantank\Sched\Queue\QueueService;
use Pheanstalk\Exception\ServerException;
use Pheanstalk\Pheanstalk;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueueClear extends Command
{
    protected static string $defaultName = 'queue:clear';

    public function __construct(
        protected QueueService $queueService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Clears all jobs from a queue')
            ->addArgument('queueName', InputArgument::REQUIRED, 'Queue to check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queueName = $input->getArgument('queueName');

        try {
            while (true) { /** @phpstan-ignore-line — Until Exception */
                $message = $this->queueService->receiveMessage($queueName, 0);
                $this->queueService->deleteMessage($queueName, $message);
            }
        } catch (NoMessageInQueueException) {
            // This is fine, the queue is clear
        } catch (ServerException $e) {
            $output->writeln('Error reading the time');
            return Command::FAILURE;
        }

        $output->writeln('Queue has been cleared');
        return Command::SUCCESS;
    }
}
