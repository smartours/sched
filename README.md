# Sched
#### A simple queue-based job scheduler and runner

Sched is a simple job runner and scheduler to schedule and run jobs. Using beanstalkd as a backend, it can arbitrarily invoke code based on jobs that come into tubes as well as schedule jobs using a cron syntax.

Sched is a great drop-in replacement

## Installation

Sched is designed to be installed with your application and use your vendor and autoload settings. It is not meant to be run on it's own as a standalone application, though it does have it's own daemon.

```console
composer require dragonmantank/sched
```

If you are wanting to use the cron aspect of Sched, you will want to set up your system's cron to have Sched check every minute if a job is due:

```
* * * * /path/to/php /path/to/your/app/vendor/bin/sched-manager -c /path/to/sched-manager.config.php cron:process
```

### Requirements
- PHP 8.0 or higher
- [beanstalkd](https://beanstalkd.github.io/)

## Usage

Running schedule requires starting the manager along with a configuration file. To run the the manager:

```console
vendor/bin/sched-manager [-c /path/to/sched-manager.config.php] [-v] manager:run 
```

The manager will loop through all of the configured queues and process them in lots of 5. For example, if there are 10 messages in the queue, Sched will start up 2 workers, each handling 5 jobs. It will loop through the queues and constantly check the number of jobs against the number of workers.

## Configuration

Sched requires a configuration file to know how to process your queues, and will ignore any queues that are not configured. You can also schedule jobs through this same configuration file.

```php
return [
    'cron' => [
        [
            'name' => 'Name of cron job, for logging',
            'expression' => '* * * * *',
            'worker' => // Invokable that needs to run at this time
        ]
    ]
    'queues' => [
        'queueName' => [
            'worker' => // Invokable that processes the queue
        ],
    ],
];
```

### Queue Management

The `queues` section of the config file lets you define which queues you want to watch, and what code to pass the payload to (called a Worker). It is assumed that each payload is a JSON object.

For example, if you want to watch the `download-payroll-report` queue and have it processed by `Me\MyApp\ReportDownloader\Payroll`, you can figure it as such:

```php
use Me\MyApp\ReportDownloader\Payroll;

return [
    'queues' => [
        'download-payroll-report' => [
            'worker' => Payroll::class
        ],
    ],
];
```

`Me\MyApp\ReportDownloader\Payroll` just needs to be an invokable class and implement the `__invoke(array $payload): int` signature. 

```php
namespace Me\MyApp\ReportDownloader;

class Payroll
{
    // Assuming a payload originally of {"url": "https://payrollcorp.net/api/report/2021-01-01?apiKey=S3CR3T"}
    public function __invoke(array $payload): int
    {
        $ch = curl_init($payload['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $report = curl_exec($ch);
        curl_close($ch);

        file_put_contents(
            (\new DateTime())->format('Y-m-d') . '.csv',
            $report
        );

        return 0;
    }
}
```

### Scheduling Jobs

You can also schedule jobs to run at specific times. Much like a queue worker, you can designate a worker to run at a specific time. Let's say we want our report downloader to fire at 4:00am every Saturday, to give our payroll system long enough to process and generate the report. We can specify a cron expression and the worker to do that:

```php
use Me\MyApp\Cron\GeneratePayrollDownload;

return [
    'cron' => [
        [
            'name' => 'Generate Payroll Download',
            'expression' => '0 4 * * SAT',
            'worker' => GeneratePayrollDownload::class
        ]
    ]
    'queues' => [ ... ]
];
```

Just like with the queue workers, the cron workers just need to be an invokable class that implements `public function __invoke(): int` (notice it does not take a `$payload`):

```php
namespace Me\MyApp\Cron;

class GeneratePayrollDownload
{
    public function __construct(protected Pheanstalk $pheanstalk)
    {
    }

    public function __invoke(): int
    {
        $date = new \DateTimeImmutable();
        $url = 'https://payrollcorp.net/api/report/' . $date->format('Y-m-d') . '/?apiKey=S3C3R3T';
        $this->pheanstalk->useTube('download-payroll-report')
            ->put(json_encode([
                'url' => $url
            ]));
    }
}
```

While the above example adds a job for the queue system to pick up and process, you can also run workers that process whatever is needed at the time without using the queues. 