<?php

declare(strict_types=1);

namespace Dragonmantank\Sched\Command;

use Dragonmantank\Sched\Exception\NoMessageInQueueException;
use Dragonmantank\Sched\Queue\QueueService;
use Pheanstalk\Exception\ServerException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueuePeek extends Command
{
    protected static string $defaultName = 'queue:peek';

    public function __construct(
        protected QueueService $queueService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Returns the next payload in a queue')
            ->addArgument('queueName', InputArgument::REQUIRED, 'Queue to check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queueName = $input->getArgument('queueName');
        $message = null;

        try {
            $message = $this->queueService->peekReady($queueName);
        } catch (NoMessageInQueueException) {
            $output->writeln('No messages to peek');
        } catch (ServerException $e) {
            $output->writeln('Unable to get stats, tube does not exist');
            return Command::FAILURE;
        }

        if ($message) {
            $payload = json_encode(
                json_decode((string)$message->payload, true), 
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            );
            $output->writeln($payload);
        }
        return Command::SUCCESS;
    }
}
