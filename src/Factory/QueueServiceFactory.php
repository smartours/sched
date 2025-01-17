<?php

declare(strict_types=1);

namespace Dragonmantank\Sched\Factory;

use Dragonmantank\Sched\Queue\MessageBroker\Beanstalkd;
use Dragonmantank\Sched\Queue\QueueService;
use Pheanstalk\Pheanstalk;
use Psr\Container\ContainerInterface;

class QueueServiceFactory
{
    public function __invoke(ContainerInterface $c): QueueService
    {
        return new QueueService($c->get('sched-config'), new Beanstalkd($c->get(Pheanstalk::class)));
    }
}
