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

namespace think\queue\connector;

use Closure;
use Exception;
use RedisException;
use think\helper\Str;
use think\queue\Connector;
use think\queue\InteractsWithTime;
use think\queue\job\Redis as RedisJob;

class Redis extends Connector
{
    use InteractsWithTime;

    /** @var  \Redis */
    protected $redis;

    /**
     * The name of the default queue.
     *
     * @var string
     */
    protected $default;

    /**
     * The expiration time of a job.
     *
     * @var int|null
     */
    protected $retryAfter = 60;

    /**
     * The maximum number of seconds to block for a job.
     *
     * @var int|null
     */
    protected $blockFor = null;

    /**
     * 是否启用任务幂等锁
     *
     * @var bool
     */
    protected $lock = true;

    /**
     * 幂等锁 TTL（秒），应大于最长任务执行时长；
     * worker 崩溃后任务等待该时长才能被重新认领
     *
     * @var int
     */
    protected $lockTtl = 3600;

    /**
     * 当前进程持有锁的任务（用于安全释放，避免误删其他 worker 的锁）
     *
     * @var array
     */
    protected $lockedJobs = [];

    /**
     * 创建连接时使用的配置（供需要独立连接的组件复用，如调度器的订阅连接）
     *
     * @var array
     */
    protected $clientConfig = [];

    public function __construct($redis, $default = 'default', $retryAfter = 60, $blockFor = null, $lock = true, $lockTtl = 3600, $clientConfig = [])
    {
        $this->redis        = $redis;
        $this->default      = $default;
        $this->retryAfter   = $retryAfter;
        $this->blockFor     = $blockFor;
        $this->lock         = $lock;
        $this->lockTtl      = $lockTtl;
        $this->clientConfig = $clientConfig ?: [];
    }

    public static function __make($config)
    {
        if (!extension_loaded('redis')) {
            throw new Exception('redis扩展未安装');
        }

        $redis = static::createRedisClient($config);

        return new self(
            $redis,
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['block_for'] ?? null,
            $config['lock'] ?? true,
            $config['lock_ttl'] ?? 3600,
            $config
        );
    }

    protected static function createRedisClient($config)
    {
        return new class($config) {
            protected $config;
            protected $client;

            public function __construct($config)
            {
                $this->config = $config;
                $this->client = $this->createClient();
            }

            protected function createClient()
            {
                $config = $this->config;
                $func   = $config['persistent'] ? 'pconnect' : 'connect';

                $client = new \Redis;
                $client->$func($config['host'], $config['port'], $config['timeout']);

                if ('' != $config['password']) {
                    $client->auth($config['password']);
                }

                if (0 != $config['select']) {
                    $client->select($config['select']);
                }
                return $client;
            }

            public function __call($name, $arguments)
            {
                try {
                    return call_user_func_array([$this->client, $name], $arguments);
                } catch (RedisException $e) {
                    if (Str::contains($e->getMessage(), 'went away')) {
                        $this->client = $this->createClient();
                    }

                    throw $e;
                }
            }
        };
    }

    public function size($queue = null)
    {
        $queue = $this->getQueue($queue);

        return $this->redis->lLen($queue) + $this->redis->zCard("{$queue}:delayed") + $this->redis->zCard("{$queue}:reserved");
    }

    /* ---------------------------------------------------------------
     * 只读监控指标（供 queue:monitor 使用，不产生任何写入）
     * --------------------------------------------------------------- */

    /**
     * 待消费任务数量
     *
     * @param string|null $queue
     * @return int
     */
    public function readyCount($queue = null)
    {
        return (int) $this->redis->lLen($this->getQueue($queue));
    }

    /**
     * 已投递未确认（reserved）任务数量
     *
     * @param string|null $queue
     * @return int
     */
    public function reservedCount($queue = null)
    {
        return (int) $this->redis->zCard($this->getQueue($queue) . ':reserved');
    }

    /**
     * 延迟任务数量
     *
     * @param string|null $queue
     * @return int
     */
    public function delayedCount($queue = null)
    {
        return (int) $this->redis->zCard($this->getQueue($queue) . ':delayed');
    }

    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue);
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        if ($this->redis->rPush($this->getQueue($queue), $payload)) {
            return json_decode($payload, true)['id'] ?? null;
        }
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->laterRaw($delay, $this->createPayload($job, $data), $queue);
    }

    protected function laterRaw($delay, $payload, $queue = null)
    {
        if ($this->redis->zadd(
            $this->getQueue($queue) . ':delayed',
            $this->availableAt($delay),
            $payload
        )) {
            return json_decode($payload, true)['id'] ?? null;
        }
    }

    public function pop($queue = null)
    {
        $this->migrate($prefixed = $this->getQueue($queue));

        if (empty($nextJob = $this->retrieveNextJob($prefixed))) {
            return;
        }

        [$job, $reserved] = $nextJob;

        if ($reserved) {
            return new RedisJob($this->app, $this, $job, $reserved, $this->connection, $queue);
        }
    }

    /**
     * Migrate any delayed or expired jobs onto the primary queue.
     *
     * @param string $queue
     * @return void
     */
    protected function migrate($queue)
    {
        $this->migrateExpiredJobs($queue . ':delayed', $queue);

        if (!is_null($this->retryAfter)) {
            $this->migrateExpiredJobs($queue . ':reserved', $queue);
        }
    }

    /**
     * 移动延迟任务
     *
     * @param string $from
     * @param string $to
     * @param bool $attempt
     */
    public function migrateExpiredJobs($from, $to, $attempt = true)
    {
        $this->redis->watch($from);

        $jobs = $this->redis->zRangeByScore($from, '-inf', $this->currentTime());

        if (!empty($jobs)) {
            $this->transaction(function () use ($from, $to, $jobs, $attempt) {

                $this->redis->zRemRangeByRank($from, 0, count($jobs) - 1);

                for ($i = 0; $i < count($jobs); $i += 100) {

                    $values = array_slice($jobs, $i, 100);

                    $this->redis->rPush($to, ...$values);
                }
            });
        }

        $this->redis->unwatch();
    }

    /**
     * Retrieve the next job from the queue.
     *
     * @param string $queue
     * @return array
     */
    protected function retrieveNextJob($queue)
    {
        if (!is_null($this->blockFor)) {
            return $this->blockingPop($queue);
        }

        $job      = $this->redis->lpop($queue);
        $reserved = false;

        if ($job) {
            $reserved = json_decode($job, true);
            $reserved['attempts']++;
            $reserved = json_encode($reserved);
            $this->redis->zAdd($queue . ':reserved', $this->availableAt($this->retryAfter), $reserved);

            // 同一任务已被其他 worker 锁定（执行时长超过 retry_after 被重复投递），丢弃本次投递
            if (!$this->acquireJobLock($queue, $this->getPayloadJobId($job))) {
                $this->redis->zRem($queue . ':reserved', $reserved);
                return [null, null];
            }
        }

        return [$job, $reserved];
    }

    /**
     * Retrieve the next job by blocking-pop.
     *
     * @param string $queue
     * @return array
     */
    protected function blockingPop($queue)
    {
        $rawBody = $this->redis->blpop($queue, $this->blockFor);

        if (!empty($rawBody)) {
            $payload = json_decode($rawBody[1], true);

            $payload['attempts']++;

            $reserved = json_encode($payload);

            $this->redis->zadd($queue . ':reserved', $this->availableAt($this->retryAfter), $reserved);

            // 同一任务已被其他 worker 锁定，丢弃本次重复投递
            if (!$this->acquireJobLock($queue, $this->getPayloadJobId($rawBody[1]))) {
                $this->redis->zRem($queue . ':reserved', $reserved);
                return [null, null];
            }

            return [$rawBody[1], $reserved];
        }

        return [null, null];
    }

    /**
     * 删除任务
     *
     * @param string $queue
     * @param RedisJob $job
     * @return void
     */
    public function deleteReserved($queue, $job)
    {
        $this->redis->zRem($this->getQueue($queue) . ':reserved', $job->getReservedJob());

        $this->releaseJobLock($this->getQueue($queue), $job->getJobId());
    }

    /**
     * Delete a reserved job from the reserved queue and release it.
     *
     * @param string $queue
     * @param RedisJob $job
     * @param int $delay
     * @return void
     */
    public function deleteAndRelease($queue, $job, $delay)
    {
        $queue = $this->getQueue($queue);

        $reserved = $job->getReservedJob();

        $this->redis->zRem($queue . ':reserved', $reserved);

        $this->redis->zAdd($queue . ':delayed', $this->availableAt($delay), $reserved);

        // 重新入队后由下次投递重新获取锁
        $this->releaseJobLock($queue, $job->getJobId());
    }

    /**
     * 尝试获取任务幂等锁
     *
     * 防止执行时长超过 retry_after 的任务被重新投递后，被多个 worker 同时执行。
     * 仅允许当前进程释放自己持有的锁，避免误删其他 worker 的锁。
     *
     * @param string $queueKey 已加前缀的队列键名（getQueue 的返回值）
     * @param string $jobId
     * @return bool
     */
    protected function acquireJobLock($queueKey, $jobId)
    {
        if (!$this->lock || empty($jobId)) {
            return true;
        }

        $key = $queueKey . ':lock:' . $jobId;

        if (!$this->redis->set($key, '1', ['nx', 'ex' => $this->lockTtl])) {
            return false;
        }

        $this->lockedJobs[$key] = true;

        return true;
    }

    /**
     * 释放任务幂等锁
     *
     * @param string $queueKey 已加前缀的队列键名（getQueue 的返回值）
     * @param string $jobId
     * @return void
     */
    protected function releaseJobLock($queueKey, $jobId)
    {
        if (empty($jobId)) {
            return;
        }

        $key = $queueKey . ':lock:' . $jobId;

        if (isset($this->lockedJobs[$key])) {
            $this->redis->del($key);
            unset($this->lockedJobs[$key]);
        }
    }

    /**
     * 从 payload 中取任务 id
     *
     * @param string $payload
     * @return string|null
     */
    protected function getPayloadJobId($payload)
    {
        return json_decode($payload, true)['id'] ?? null;
    }

    /**
     * redis事务
     * @param Closure $closure
     */
    protected function transaction(Closure $closure)
    {
        $this->redis->multi();
        try {
            call_user_func($closure);
            if (!$this->redis->exec()) {
                $this->redis->discard();
            }
        } catch (Exception $e) {
            $this->redis->discard();
        }
    }

    protected function createPayloadArray($job, $data = '')
    {
        return array_merge(parent::createPayloadArray($job, $data), [
            'id'       => $this->getRandomId(),
            'attempts' => 0,
        ]);
    }

    /**
     * 随机id
     *
     * @return string
     */
    protected function getRandomId()
    {
        return Str::random(32);
    }

    /**
     * 获取队列名
     *
     * @param string|null $queue
     * @return string
     */
    protected function getQueue($queue)
    {
        $queue = $queue ?: $this->default;
        return "{queues:{$queue}}";
    }
}
