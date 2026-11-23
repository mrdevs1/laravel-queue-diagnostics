# Laravel Queue Diagnostics

A practical troubleshooting guide for diagnosing and resolving Laravel queue problems in development and production environments.

This repository covers queue configuration, failed jobs, worker processes, retry handling, job timeouts, Redis and database connections, duplicate job execution, Supervisor configuration, and Laravel Horizon.

The goal is to help Laravel developers identify queue-related problems, understand their causes, and apply reliable fixes.

## Table of Contents

- [Introduction](#introduction)
- [How Laravel Queues Work](#how-laravel-queues-work)
- [Common Queue Problems](#common-queue-problems)
- [Queue Configuration Diagnostics](#queue-configuration-diagnostics)
- [Queue Worker Diagnostics](#queue-worker-diagnostics)
- [Failed Job Diagnostics](#failed-job-diagnostics)
- [Retry and Backoff Diagnostics](#retry-and-backoff-diagnostics)
- [Job Timeout Diagnostics](#job-timeout-diagnostics)
- [Duplicate Job Execution](#duplicate-job-execution)
- [Database Queue Diagnostics](#database-queue-diagnostics)
- [Redis Queue Diagnostics](#redis-queue-diagnostics)
- [Stuck and Delayed Jobs](#stuck-and-delayed-jobs)
- [Worker Memory and Process Management](#worker-memory-and-process-management)
- [Supervisor Configuration](#supervisor-configuration)
- [Laravel Horizon Diagnostics](#laravel-horizon-diagnostics)
- [Logging and Monitoring](#logging-and-monitoring)
- [Useful Artisan Commands](#useful-artisan-commands)
- [Code Examples](#code-examples)
- [Production Debugging Checklist](#production-debugging-checklist)
- [References](#references)

## Introduction

Laravel queues allow applications to process time-consuming tasks in the background.

Common use cases include:

- Sending emails and notifications.
- Processing uploaded files.
- Importing and exporting large datasets.
- Generating reports.
- Processing payment-related tasks.
- Calling external APIs.
- Running scheduled background operations.

Queue problems can affect application performance and business operations. A job may remain pending, fail repeatedly, execute more than once, or stop processing when a worker exits.

This repository provides a structured approach to investigating these problems.

## How Laravel Queues Work

A typical Laravel queue workflow looks like this:

1. An application dispatches a job.
2. Laravel pushes the job onto a queue connection.
3. A queue worker retrieves the job.
4. The worker executes the job's `handle()` method.
5. The job is deleted after successful execution.
6. If execution fails, Laravel applies the configured retry and failure rules.

### Queue Processing Flow

```text
Application
    |
    v
Dispatch Job
    |
    v
Queue Connection
    |
    v
Queue Worker
    |
    v
Execute Job
    |
    +------ Success ------> Delete Job
    |
    +------ Failure ------> Retry / Failed Jobs
```

## Common Queue Problems

| Problem | Possible Cause |
|---|---|
| Jobs are not processing | Worker is not running |
| Jobs remain pending | Incorrect queue name or connection |
| Jobs repeatedly fail | Application exception or invalid data |
| Jobs execute twice | Incorrect timeout and retry configuration |
| Jobs are delayed | Backoff settings or worker availability |
| Redis connection fails | Incorrect Redis configuration |
| Database queue is not working | Missing table or database connection |
| Worker stops unexpectedly | Memory limit or process termination |
| Failed jobs are not recorded | Failed-job storage is not configured |
| Horizon shows no workers | Redis or Horizon configuration issue |

## Queue Configuration Diagnostics

Laravel queue connections are configured in `config/queue.php`.

The default queue connection is controlled by the `QUEUE_CONNECTION` environment variable.

Example:

```env
QUEUE_CONNECTION=database
```

For Redis:

```env
QUEUE_CONNECTION=redis
```

For synchronous execution:

```env
QUEUE_CONNECTION=sync
```

The `sync` driver executes jobs immediately in the current process. It does not use a background queue worker.

### Diagnostic Steps

1. Check the `QUEUE_CONNECTION` value.
2. Verify that the selected driver is supported.
3. Check database or Redis connectivity.
4. Review the selected queue name.
5. Confirm that a worker is running for the correct connection.
6. Clear cached configuration after changing environment settings.

Useful commands:

```bash
php artisan config:clear
php artisan config:show queue
php artisan about
```

Note: Do not expose environment variables, credentials, or connection secrets in public logs.

## Queue Worker Diagnostics

A queue worker must be running to process jobs asynchronously.

Start a worker:

```bash
php artisan queue:work
```

Specify a connection:

```bash
php artisan queue:work database
```

Specify a queue:

```bash
php artisan queue:work redis --queue=default
```

Set worker limits:

```bash
php artisan queue:work redis --tries=3 --timeout=60
```

### Common Worker Problems

- Worker is not running.
- Worker is listening to the wrong queue.
- Worker uses outdated application code.
- Worker has exceeded its memory limit.
- Worker process has been terminated.
- Worker cannot connect to the queue backend.

### Important: Restart Long-Running Workers

Laravel queue workers are long-running processes. They do not automatically load every code change.

After deploying new code, restart workers gracefully:

```bash
php artisan queue:restart
```

A process manager such as Supervisor should restart workers when they exit.

## Failed Job Diagnostics

Laravel stores failed asynchronous jobs when failed-job storage is configured.

### Check Failed Jobs

```bash
php artisan queue:failed
```

### Inspect a Specific Failed Job

Use the job ID from the failed jobs list:

```bash
php artisan queue:failed
```

Then inspect the related application logs and exception details.

### Retry a Failed Job

```bash
php artisan queue:retry <job-id>
```

Retry all failed jobs:

```bash
php artisan queue:retry all
```

### Delete a Failed Job

```bash
php artisan queue:forget <job-id>
```

### Remove Failed Job Records

```bash
php artisan queue:flush
```

Warning: `queue:flush` removes all failed job records. Do not run it before checking whether those records are needed for investigation.

### Create Failed Job Storage

If the application does not already have the required migration:

```bash
php artisan make:queue-failed-table
php artisan migrate
```

## Retry and Backoff Diagnostics

Laravel can retry jobs when execution fails.

The maximum number of attempts can be configured on the worker or the job itself.

Example worker command:

```bash
php artisan queue:work --tries=3
```

A job can also define retry behavior:

```php
public $tries = 3;

public $backoff = [10, 30, 60];
```

This example allows three attempts and uses increasing delays between retries.

### Common Retry Problems

- Jobs retry too frequently.
- Jobs fail before the retry limit.
- Jobs are marked failed unexpectedly.
- External services remain unavailable.
- A job repeatedly processes invalid data.

### Recommended Investigation

1. Check the exception message.
2. Review the job's `tries` configuration.
3. Inspect the `backoff` settings.
4. Check whether the failure is temporary or permanent.
5. Avoid retrying jobs that cannot succeed without correcting their data or configuration.

## Job Timeout Diagnostics

A job timeout determines how long a worker can spend processing a job.

Example:

```bash
php artisan queue:work --timeout=60
```

The queue connection's `retry_after` setting determines when a reserved job can become available for processing again.

These settings must work together.

### Important Configuration Rule

The worker timeout should be several seconds shorter than the queue connection's `retry_after` value.

For example:

```text
Worker timeout: 60 seconds
Retry after:    90 seconds
```

If the worker timeout is longer than `retry_after`, a job may become available to another worker while the original worker is still processing it. This can cause duplicate execution.

### Diagnostic Checklist

- Check the worker timeout.
- Check the connection's `retry_after` value.
- Inspect long-running job operations.
- Check external API response times.
- Review PHP process timeout settings.
- Confirm that the process manager allows sufficient execution time.

## Duplicate Job Execution

Duplicate execution can happen when:

- A worker processes a job longer than the configured `retry_after` period.
- A job is dispatched more than once.
- A worker terminates after performing an operation but before acknowledging completion.
- External services retry requests.
- Multiple application requests dispatch the same task.

### Recommended Solutions

**1. Use idempotent job logic**

A job should avoid producing duplicate side effects when it is executed more than once.

**2. Use unique job constraints when appropriate**

Laravel provides unique job features for preventing duplicate dispatches.

**3. Use job middleware**

Middleware such as `WithoutOverlapping` can help prevent simultaneous processing of jobs that share a resource.

**4. Verify timeout configuration**

Keep worker timeout shorter than `retry_after`.

**5. Protect external operations**

Use provider-supported idempotency keys for payment and other sensitive API operations.

Never assume that a queued job will execute exactly once.

## Database Queue Diagnostics

When using the database queue driver, Laravel stores pending jobs in a database table.

### Common Problems

- Queue table does not exist.
- Database connection is unavailable.
- Queue table migrations are incomplete.
- Worker is listening to the wrong queue.
- Database permissions prevent job insertion or deletion.

### Diagnostic Steps

Check the configured database connection:

```bash
php artisan config:show database
```

Check the queue configuration:

```bash
php artisan config:show queue
```

Check whether the jobs table exists in the configured database.

If the jobs table is missing, create its migration using the command supported by your Laravel version and run:

```bash
php artisan migrate
```

### Database Queue Checklist

- [ ] Verify database connectivity.
- [ ] Confirm the jobs table exists.
- [ ] Check the configured queue connection.
- [ ] Verify the queue name.
- [ ] Check database user permissions.
- [ ] Inspect pending and reserved jobs.
- [ ] Review failed job storage.

## Redis Queue Diagnostics

Redis is commonly used for high-performance queue processing.

### Common Redis Problems

- Redis server is unavailable.
- Incorrect host or port.
- Authentication failure.
- Incorrect Redis database selection.
- Network connectivity problems.
- Queue workers are connected to a different Redis instance.

### Diagnostic Steps

Check Redis environment configuration:

```env
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
QUEUE_CONNECTION=redis
```

Test Redis connectivity from the server:

```bash
redis-cli ping
```

Expected response:

```text
PONG
```

Check Laravel queue configuration:

```bash
php artisan config:show queue
```

Check the application logs for Redis connection exceptions.

### Redis Troubleshooting Checklist

- [ ] Verify Redis is running.
- [ ] Check host and port.
- [ ] Verify authentication settings.
- [ ] Confirm the selected Redis database.
- [ ] Check network access.
- [ ] Restart workers after configuration changes.
- [ ] Verify that workers use the intended Redis connection.

## Stuck and Delayed Jobs

Jobs may appear stuck when workers are unavailable or processing takes longer than expected.

### Possible Causes

- No active workers.
- Incorrect queue selection.
- Long-running job operations.
- External API delays.
- Worker process crashes.
- Excessive retry delays.
- Redis or database connection problems.

### Investigation Steps

1. Check whether a worker is running.
2. Confirm that it listens to the correct queue.
3. Inspect pending and reserved jobs.
4. Review application logs.
5. Check external service availability.
6. Verify timeout and retry configuration.

A pending job is not necessarily a failed job. Check its status before attempting to retry or delete it.

## Worker Memory and Process Management

Queue workers are long-running processes and may consume increasing amounts of memory.

Laravel provides worker options to control resource usage.

Example:

```bash
php artisan queue:work redis \
    --memory=128 \
    --max-jobs=500 \
    --max-time=3600
```

These options help workers exit after reaching configured resource or processing limits.

### Common Memory Problems

- Large file processing.
- Loading too many database records.
- Memory leaks in application code.
- Unreleased resources.
- Long-running loops.
- Large job payloads.

### Recommended Practices

- Process large datasets in chunks.
- Avoid loading unnecessary relationships.
- Release resources after processing.
- Keep job payloads small.
- Configure worker recycling.
- Monitor memory usage in production.

## Supervisor Configuration

In production, queue workers should run under a process manager.

Supervisor can keep Laravel workers running and restart them after unexpected exits.

A sample configuration is available in:

```text
supervisor/laravel-worker.conf
```

### Important Settings

- `command`: The queue worker command.
- `autostart`: Starts the worker when Supervisor starts.
- `autorestart`: Restarts the worker after it exits.
- `numprocs`: Number of worker processes.
- `user`: Linux user running the process.
- `stdout_logfile`: Worker output log location.

After updating Supervisor configuration, reload it:

```bash
sudo supervisorctl reread
sudo supervisorctl update
```

Check worker status:

```bash
sudo supervisorctl status
```

Restart a worker group:

```bash
sudo supervisorctl restart laravel-worker:*
```

Use the actual group name defined in your Supervisor configuration.

## Laravel Horizon Diagnostics

Laravel Horizon provides monitoring and management for Redis-based queues.

It can help diagnose:

- Queue throughput.
- Job processing time.
- Failed jobs.
- Worker status.
- Queue workload.
- Supervisor configuration.

### Useful Commands

Start Horizon:

```bash
php artisan horizon
```

Check Horizon status:

```bash
php artisan horizon:status
```

Terminate Horizon gracefully:

```bash
php artisan horizon:terminate
```

Horizon requires Redis and manages Redis queues. It does not replace ordinary workers for database queue connections.

### Common Horizon Problems

- Redis connection failure.
- Incorrect Horizon environment configuration.
- Workers not starting.
- Supervisor process configuration problems.
- Incorrect queue names.
- Worker timeout conflicts.

## Logging and Monitoring

Logs are important when diagnosing queue failures.

Check the Laravel application log:

```text
storage/logs/laravel.log
```

Monitor logs in real time:

```bash
tail -f storage/logs/laravel.log
```

### What to Look For

- Job exception messages.
- Redis connection errors.
- Database connection failures.
- Timeout exceptions.
- External API errors.
- Memory exhaustion.
- Repeated retry attempts.

Avoid logging passwords, access tokens, payment details, or other sensitive information.

## Useful Artisan Commands

| Command | Purpose |
|---|---|
| `php artisan queue:work` | Start a queue worker |
| `php artisan queue:listen` | Start a queue listener |
| `php artisan queue:restart` | Gracefully restart workers |
| `php artisan queue:failed` | List failed jobs |
| `php artisan queue:retry all` | Retry all failed jobs |
| `php artisan queue:forget <id>` | Delete a failed job record |
| `php artisan queue:flush` | Remove failed job records |
| `php artisan queue:prune-failed` | Remove old failed job records |
| `php artisan horizon` | Start Horizon |
| `php artisan horizon:status` | Check Horizon status |
| `php artisan horizon:terminate` | Gracefully terminate Horizon |

Command availability may depend on the Laravel version and installed packages.

## Code Examples

The repository includes separate code files for practical implementation.

| File | Purpose |
|---|---|
| `examples/ProcessWebhook.php` | Example queued job with retry, timeout, and failure handling |
| `examples/dispatch-job.php` | Example of dispatching a queued job |
| `supervisor/laravel-worker.conf` | Supervisor process configuration |

These files are examples, not complete application modules. Adjust namespaces, URLs, authentication, and business logic before using them in a real application.

### ProcessWebhook.php

A sample job that sends data to an external webhook endpoint.

See `examples/ProcessWebhook.php`.

### dispatch-job.php

A minimal example showing how to dispatch the job.

See `examples/dispatch-job.php`.

### laravel-worker.conf

A sample Supervisor configuration for keeping queue workers alive.

See `supervisor/laravel-worker.conf`.

## Production Debugging Checklist

Use this checklist when a Laravel queue stops working in production.

### Configuration

- [ ] Verify `QUEUE_CONNECTION`.
- [ ] Check queue connection configuration.
- [ ] Verify database or Redis connectivity.
- [ ] Confirm environment configuration is current.
- [ ] Clear cached configuration when required.

### Workers

- [ ] Confirm workers are running.
- [ ] Verify the correct connection.
- [ ] Check the queue name.
- [ ] Review worker logs.
- [ ] Check memory and timeout limits.
- [ ] Restart workers after deployment.

### Failed Jobs

- [ ] Inspect failed job records.
- [ ] Review exception messages.
- [ ] Check retry limits.
- [ ] Verify backoff configuration.
- [ ] Retry only after identifying the cause.

### Production Infrastructure

- [ ] Check Supervisor status.
- [ ] Verify Redis or database availability.
- [ ] Review server resource usage.
- [ ] Check log permissions.
- [ ] Verify Horizon status if installed.
- [ ] Confirm timeout settings are consistent.

## References

- [Laravel Queue Documentation](https://laravel.com/docs/queues)
- [Laravel Queue Jobs](https://laravel.com/docs/queues#creating-jobs)
- [Laravel Failed Jobs](https://laravel.com/docs/queues#dealing-with-failed-jobs)
- [Laravel Queue Workers](https://laravel.com/docs/queues#running-the-queue-worker)
- [Laravel Horizon Documentation](https://laravel.com/docs/horizon)
- [Supervisor Documentation](http://supervisord.org/)

## License

This project is licensed under the MIT License.
