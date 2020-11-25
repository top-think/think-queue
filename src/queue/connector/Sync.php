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

namespace shirakun\queue\connector;

use Exception;
use shirakun\queue\Connector;
use shirakun\queue\event\JobFailed;
use shirakun\queue\event\JobProcessed;
use shirakun\queue\event\JobProcessing;
use shirakun\queue\job\Sync as SyncJob;
use Throwable;

class Sync extends Connector
{

    public function size($queue = null)
    {
        return 0;
    }

    public function push($job, $data = '', $queue = null)
    {
        $queueJob = $this->resolveJob($this->createPayload($job, $data), $queue);

        try {
            $this->triggerEvent(new JobProcessing($this->connection, $job));

            $queueJob->fire();

            $this->triggerEvent(new JobProcessed($this->connection, $job));
        } catch (Exception | Throwable $e) {

            $this->triggerEvent(new JobFailed($this->connection, $job, $e));

            throw $e;
        }

        return 0;
    }

    protected function triggerEvent($event)
    {
        $this->app->event->trigger($event);
    }

    public function pop($queue = null)
    {

    }

    protected function resolveJob($payload, $queue)
    {
        return new SyncJob($this->app, $payload, $this->connection, $queue);
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {

    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->push($job, $data, $queue);
    }
}
