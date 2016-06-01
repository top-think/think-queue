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

namespace think\queue\driver;


use Exception;
use think\queue\job\Redis as RedisJob;

class Redis
{
    /** @var  \Redis */
    protected $redis;

    protected $options = [
        'expire'     => 60,
        'default'    => 'default',
        'host'       => '127.0.0.1',
        'port'       => 6379,
        'password'   => '',
        'timeout'    => 0,
        'persistent' => false
    ];

    public function __construct($options)
    {
        if (!extension_loaded('redis')) {
            throw new Exception('redis扩展未安装');
        }
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }

        $func        = $this->options['persistent'] ? 'pconnect' : 'connect';
        $this->redis = new \Redis;
        $this->redis->$func($this->options['host'], $this->options['port'], $this->options['timeout']);

        if ('' != $this->options['password']) {
            $this->redis->auth($this->options['password']);
        }
    }

    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue);
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $payload = $this->createPayload($job, $data);

        $this->redis->zAdd($this->getQueue($queue) . ':delayed', time() + $delay, $payload);
    }

    public function pop($queue = null)
    {
        $original = $queue ?: $this->options['default'];

        $queue = $this->getQueue($queue);

        if (!is_null($this->options['expire'])) {
            $this->migrateAllExpiredJobs($queue);
        }

        $job = $this->redis->lPop($queue);

        if (!is_null($job)) {
            $this->redis->zAdd($queue . ':reserved', time() + $this->options['expire'], $job);

            return new RedisJob($this, $job, $original);
        }
    }

    /**
     * 重新发布任务
     *
     * @param  string $queue
     * @param  string $payload
     * @param  int    $delay
     * @param  int    $attempts
     * @return void
     */
    public function release($queue, $payload, $delay, $attempts)
    {
        $payload = $this->setMeta($payload, 'attempts', $attempts);

        $this->redis->zAdd($this->getQueue($queue) . ':delayed', time() + $delay, $payload);
    }

    public function pushRaw($payload, $queue = null)
    {
        $this->redis->rPush($this->getQueue($queue), $payload);

        return json_decode($payload, true)['id'];
    }


    protected function createPayload($job, $data)
    {
        $payload = json_encode(['job' => $job, 'data' => $data]);

        $payload = $this->setMeta($payload, 'id', $this->getRandomId());

        return $this->setMeta($payload, 'attempts', 1);
    }

    /**
     * 删除任务
     *
     * @param  string $queue
     * @param  string $job
     * @return void
     */
    public function deleteReserved($queue, $job)
    {
        $this->redis->zRem($this->getQueue($queue) . ':reserved', $job);
    }

    /**
     * 移动所有任务
     *
     * @param  string $queue
     * @return void
     */
    protected function migrateAllExpiredJobs($queue)
    {
        $this->migrateExpiredJobs($queue . ':delayed', $queue);

        $this->migrateExpiredJobs($queue . ':reserved', $queue);
    }

    /**
     * 移动延迟任务
     *
     * @param  string $from
     * @param  string $to
     * @return void
     */
    public function migrateExpiredJobs($from, $to)
    {
        $options = ['watch' => $from];

        $this->transaction($options, function ($transaction) use ($from, $to) {
            $jobs = $this->getExpiredJobs(
                $transaction, $from, $time = time()
            );

            if (count($jobs) > 0) {
                $this->removeExpiredJobs($transaction, $from, $time);

                $this->pushExpiredJobsOntoNewQueue($transaction, $to, $jobs);
            }
        });
    }

    /**
     * redis事务
     * @param array    $options
     * @param \Closure $closure
     */
    protected function transaction($options = [], \Closure $closure)
    {
        if (!empty($options['watch'])) {
            $this->redis->watch($options['watch']);
        }
        $redis = $this->redis->multi();
        try {
            call_user_func($closure, $redis);
            $redis->exec();
        } catch (Exception $e) {
            $redis->discard();
        }
    }


    /**
     * 获取所有到期任务
     *
     * @param \Redis  $redis
     * @param  string $from
     * @param  int    $time
     * @return array
     */
    protected function getExpiredJobs(\Redis $redis, $from, $time)
    {
        return $redis->zRangeByScore($from, '-inf', $time);
    }


    /**
     * 删除过期任务
     *
     * @param  \Redis $redis
     * @param  string $from
     * @param  int    $time
     * @return void
     */
    protected function removeExpiredJobs(\Redis $redis, $from, $time)
    {
        $redis->zRemRangeByScore($from, '-inf', $time);
    }

    /**
     * 重新发布到期任务
     *
     * @param  \Redis $redis
     * @param  string $to
     * @param  array  $jobs
     * @return void
     */
    protected function pushExpiredJobsOntoNewQueue(\Redis $redis, $to, $jobs)
    {
        call_user_func_array([$redis, 'rPush'], array_merge([$to], $jobs));
    }

    /**
     * 随机id
     *
     * @return string
     */
    protected function getRandomId()
    {
        return uniqid();
    }

    protected function setMeta($payload, $key, $value)
    {
        $payload       = json_decode($payload, true);
        $payload[$key] = $value;
        return json_encode($payload);
    }

    /**
     * 获取队列名
     *
     * @param  string|null $queue
     * @return string
     */
    protected function getQueue($queue)
    {
        return 'queues:' . ($queue ?: $this->options['default']);
    }
}