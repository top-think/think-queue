<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2015 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\queue;

use Exception;
use RuntimeException;
use think\Cache;
use think\Event;
use think\exception\Handle;
use think\Queue;
use think\queue\event\JobExceptionOccurred;
use think\queue\event\JobFailed;
use think\queue\event\JobProcessed;
use think\queue\event\JobProcessing;
use think\queue\event\WorkerStopping;
use Throwable;

class Worker
{
    /** @var Event */
    protected $event;
    /** @var Handle */
    protected $handle;
    /** @var Queue */
    protected $queue;

    /** @var Cache */
    protected $cache;

    /**
     * Indicates if the worker should exit.
     *
     * @var bool
     */
    public $shouldQuit = false;

    /**
     * Indicates if the worker is paused.
     *
     * @var bool
     */
    public $paused = false;

    public function __construct(Queue $queue, Event $event, Handle $handle, Cache $cache)
    {
        $this->queue  = $queue;
        $this->event  = $event;
        $this->handle = $handle;
        $this->cache  = $cache;
    }

    /**
     * @param string $connector
     * @param string $queue
     * @param int    $delay
     * @param int    $sleep
     * @param int    $maxTries
     * @param int    $memory
     * @param int    $timeout
     */
    public function daemon($connector, $queue, $delay = 0, $sleep = 3, $maxTries = 0, $memory = 128, $timeout = 60)
    {
        if ($this->supportsAsyncSignals()) {
            $this->listenForSignals();
        }

        $lastRestart = $this->getTimestampOfLastQueueRestart();

        while (true) {

            $job = $this->getNextJob(
                $this->queue->driver($connector), $queue
            );

            if ($this->supportsAsyncSignals()) {
                $this->registerTimeoutHandler($job, $timeout);
            }

            if ($job) {
                $this->runJob($job, $connector, $maxTries, $delay);
            } else {
                $this->sleep($sleep);
            }

            if ($this->shouldQuit || $this->queueShouldRestart($lastRestart)) {
                $this->stop();
            } elseif ($this->memoryExceeded($memory)) {
                $this->stop(12);
            }
        }
    }

    /**
     * Determine if the queue worker should restart.
     *
     * @param int|null $lastRestart
     * @return bool
     */
    protected function queueShouldRestart($lastRestart)
    {
        return $this->getTimestampOfLastQueueRestart() != $lastRestart;
    }

    /**
     * Determine if the memory limit has been exceeded.
     *
     * @param int $memoryLimit
     * @return bool
     */
    public function memoryExceeded($memoryLimit)
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $memoryLimit;
    }

    /**
     * 获取队列重启时间
     * @return mixed
     */
    protected function getTimestampOfLastQueueRestart()
    {
        if ($this->cache) {
            return $this->cache->get('think:queue:restart');
        }
    }

    /**
     * Register the worker timeout handler.
     *
     * @param Job|null $job
     * @param int      $timeout
     * @return void
     */
    protected function registerTimeoutHandler($job, $timeout)
    {
        pcntl_signal(SIGALRM, function () {
            $this->kill(1);
        });

        pcntl_alarm(
            max($this->timeoutForJob($job, $timeout), 0)
        );
    }

    /**
     * Stop listening and bail out of the script.
     *
     * @param int $status
     * @return void
     */
    public function stop($status = 0)
    {
        $this->event->trigger(new WorkerStopping($status));

        exit($status);
    }

    /**
     * Kill the process.
     *
     * @param int $status
     * @return void
     */
    public function kill($status = 0)
    {
        $this->event->trigger(new WorkerStopping($status));

        if (extension_loaded('posix')) {
            posix_kill(getmypid(), SIGKILL);
        }

        exit($status);
    }

    /**
     * Get the appropriate timeout for the given job.
     *
     * @param Job|null $job
     * @param int      $timeout
     * @return int
     */
    protected function timeoutForJob($job, $timeout)
    {
        return $job && !is_null($job->timeout()) ? $job->timeout() : $timeout;
    }

    /**
     * Determine if "async" signals are supported.
     *
     * @return bool
     */
    protected function supportsAsyncSignals()
    {
        return extension_loaded('pcntl');
    }

    /**
     * Enable async signals for the process.
     *
     * @return void
     */
    protected function listenForSignals()
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function () {
            $this->shouldQuit = true;
        });

        pcntl_signal(SIGUSR2, function () {
            $this->paused = true;
        });

        pcntl_signal(SIGCONT, function () {
            $this->paused = false;
        });
    }

    /**
     * 执行下个任务
     * @param string $connectorName
     * @param string $queue
     * @param int    $delay
     * @param int    $sleep
     * @param int    $maxTries
     * @return void
     * @throws Exception
     */
    public function runNextJob($connectorName, $queue, $delay = 0, $sleep = 3, $maxTries = 0)
    {

        $job = $this->getNextJob($this->queue->driver($connectorName), $queue);

        if (!$job) {
            $this->runJob($job, $connectorName, $maxTries, $delay);
            return;
        }

        $this->sleep($sleep);
    }

    /**
     * 执行任务
     * @param Job    $job
     * @param string $connectorName
     * @param int    $maxTries
     * @param int    $delay
     * @return void
     */
    protected function runJob($job, $connectorName, $maxTries, $delay)
    {
        try {
            $this->process($connectorName, $job, $maxTries, $delay);
        } catch (Exception | Throwable $e) {
            $this->handle->report($e);
        }
    }

    /**
     * 获取下个任务
     * @param Connector $connector
     * @param string    $queue
     * @return Job
     */
    protected function getNextJob($connector, $queue)
    {
        try {
            foreach (explode(',', $queue) as $queue) {
                if (!is_null($job = $connector->pop($queue))) {
                    return $job;
                }
            }
        } catch (Exception | Throwable $e) {
            $this->handle->report($e);
            $this->sleep(1);
        }
    }

    /**
     * Process a given job from the queue.
     * @param string $connector
     * @param Job    $job
     * @param int    $maxTries
     * @param int    $delay
     * @return void
     * @throws Exception
     */
    public function process($connector, Job $job, $maxTries = 0, $delay = 0)
    {
        try {
            $this->event->trigger(new JobProcessing($connector, $job));

            $this->markJobAsFailedIfAlreadyExceedsMaxAttempts(
                $connector, $job, (int) $maxTries
            );

            $job->fire();

            $this->event->trigger(new JobProcessed($connector, $job));
        } catch (Exception | Throwable $e) {
            try {
                if (!$job->hasFailed()) {
                    $this->markJobAsFailedIfWillExceedMaxAttempts($connector, $job, (int) $maxTries, $e);
                }

                $this->event->trigger(new JobExceptionOccurred($connector, $job, $e));
            } finally {
                if (!$job->isDeleted() && !$job->isReleased() && !$job->hasFailed()) {
                    $job->release($delay);
                }
            }

            throw $e;
        }
    }

    /**
     * @param string $connector
     * @param Job    $job
     * @param int    $maxTries
     */
    protected function markJobAsFailedIfAlreadyExceedsMaxAttempts($connector, $job, $maxTries)
    {
        $maxTries = !is_null($job->maxTries()) ? $job->maxTries() : $maxTries;

        $timeoutAt = $job->timeoutAt();

        if ($timeoutAt && time() <= $timeoutAt) {
            return;
        }

        if (!$timeoutAt && ($maxTries === 0 || $job->attempts() <= $maxTries)) {
            return;
        }

        $this->failJob($connector, $job, $e = new RuntimeException(
            $job->getName() . ' has been attempted too many times or run too long. The job may have previously timed out.'
        ));

        throw $e;
    }

    /**
     * @param string    $connector
     * @param Job       $job
     * @param int       $maxTries
     * @param Exception $e
     */
    protected function markJobAsFailedIfWillExceedMaxAttempts($connector, $job, $maxTries, $e)
    {
        $maxTries = !is_null($job->maxTries()) ? $job->maxTries() : $maxTries;

        if ($job->timeoutAt() && $job->timeoutAt() <= time()) {
            $this->failJob($connector, $job, $e);
        }

        if ($maxTries > 0 && $job->attempts() >= $maxTries) {
            $this->failJob($connector, $job, $e);
        }
    }

    /**
     * @param string    $connector
     * @param Job       $job
     * @param Exception $e
     */
    protected function failJob($connector, $job, $e)
    {
        $job->markAsFailed();

        if ($job->isDeleted()) {
            return;
        }

        try {
            $job->delete();

            $job->failed($e);
        } finally {
            $this->event->trigger(new JobFailed(
                $connector, $job, $e ?: new RuntimeException('ManuallyFailed')
            ));
        }
    }

    /**
     * Sleep the script for a given number of seconds.
     * @param int $seconds
     * @return void
     */
    public function sleep($seconds)
    {
        if ($seconds < 1) {
            usleep($seconds * 1000000);
        } else {
            sleep($seconds);
        }
    }

}
