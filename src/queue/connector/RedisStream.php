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

use Exception;
use RedisException;
use think\helper\Str;
use think\queue\connector\Redis as RedisQueue;
use think\queue\job\RedisStream as RedisStreamJob;

/**
 * Redis Stream 队列驱动
 *
 * 使用 Redis Stream + 消费者组实现，消息投递语义由 PEL（待处理列表）保证，
 * 重试次数通过消费者组统计的消息投递次数（deliveries）记录。
 *
 * 延迟任务仍然复用有序集合（{queues:xxx}:delayed），到期后迁移到 Stream。
 */
class RedisStream extends RedisQueue
{
    /**
     * 消费者组名称
     * @var string
     */
    protected $group;

    /**
     * 消费者名称
     * @var string
     */
    protected $consumer;

    /**
     * 已确认创建过消费者组的 Stream
     * @var array
     */
    protected $groups = [];

    public function __construct($redis, $default = 'default', $retryAfter = 60, $blockFor = null, $group = 'think-queue', $consumer = null)
    {
        parent::__construct($redis, $default, $retryAfter, $blockFor);

        $this->group    = $group;
        $this->consumer = $consumer ?: (gethostname() ?: 'worker') . '-' . getmypid();
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
            $config['group'] ?? 'think-queue',
            $config['consumer'] ?? ''
        );
    }

    public function size($queue = null)
    {
        $queue  = $this->getQueue($queue);
        $stream = $queue . ':stream';

        $this->ensureGroup($stream);

        $pending = $this->redis->xPending($stream, $this->group);

        return $this->redis->xLen($stream)
            + $this->redis->zCard($queue . ':delayed')
            + ($pending['count'] ?? $pending[0] ?? 0);
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $stream = $this->getQueue($queue) . ':stream';

        if ($this->redis->xAdd($stream, '*', ['job' => $payload])) {
            return json_decode($payload, true)['id'] ?? null;
        }
    }

    public function pop($queue = null)
    {
        $queue  = $this->getQueue($queue);
        $stream = $queue . ':stream';

        $this->ensureGroup($stream);

        $this->migrateExpiredJobs($queue . ':delayed', $stream);

        // 优先认领超过 retry_after 仍未确认的消息
        if (!empty($job = $this->claimPending($stream, $queue))) {
            return $job;
        }

        return $this->readNewJob($stream, $queue);
    }

    /**
     * 迁移到期任务到 Stream
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
            $this->transaction(function () use ($from, $to, $jobs) {

                $this->redis->zRemRangeByRank($from, 0, count($jobs) - 1);

                foreach ($jobs as $job) {
                    $this->redis->xAdd($to, '*', ['job' => $job]);
                }
            });
        }

        $this->redis->unwatch();
    }

    /**
     * 认领投递超时（idle >= retry_after）的待处理消息
     *
     * @param string $stream
     * @param string $queue
     * @return RedisStreamJob|null
     */
    protected function claimPending($stream, $queue)
    {
        if (is_null($this->retryAfter)) {
            return null;
        }

        $minIdle = $this->retryAfter * 1000;

        $entries = $this->redis->xPending($stream, $this->group, '-', '+', 100);

        foreach ($entries as $entry) {
            // 兼容 phpredis 不同版本的返回格式
            $id         = is_array($entry) ? ($entry['id'] ?? $entry[0] ?? null) : null;
            $idle       = $entry['idle'] ?? $entry[2] ?? 0;
            $deliveries = $entry['deliveries'] ?? $entry[3] ?? 1;

            if (empty($id) || $idle < $minIdle) {
                continue;
            }

            $claimed = $this->redis->xClaim($stream, $this->group, $this->consumer, $minIdle, [$id]);

            foreach ($claimed as $entryId => $fields) {
                return $this->newJob($fields['job'], $entryId, $deliveries + 1, $queue);
            }
        }

        return null;
    }

    /**
     * 读取新消息
     *
     * @param string $stream
     * @param string $queue
     * @return RedisStreamJob|null
     */
    protected function readNewJob($stream, $queue)
    {
        $block = $this->blockFor ? $this->blockFor * 1000 : -1;

        $result = $this->redis->xReadGroup($this->group, $this->consumer, [$stream => '>'], 1, $block);

        if (!empty($result)) {
            foreach (reset($result) as $entryId => $fields) {
                return $this->newJob($fields['job'], $entryId, 1, $queue);
            }
        }

        return null;
    }

    protected function newJob($payload, $entryId, $attempts, $queue)
    {
        return new RedisStreamJob($this->app, $this, $payload, $entryId, $this->connection, $queue, $attempts);
    }

    /**
     * 删除任务（确认并移除消息）
     *
     * @param string $queue
     * @param RedisStreamJob $job
     * @return void
     */
    public function deleteReserved($queue, $job)
    {
        $stream = $this->getQueue($queue) . ':stream';

        $entryId = $job->getReservedJob();

        $this->redis->xAck($stream, $this->group, [$entryId]);
        $this->redis->xDel($stream, [$entryId]);
    }

    /**
     * 删除保留任务并重新发布（放入延迟集合）
     *
     * @param string $queue
     * @param RedisStreamJob $job
     * @param int $delay
     * @return void
     */
    public function deleteAndRelease($queue, $job, $delay)
    {
        $this->deleteReserved($queue, $job);

        $this->redis->zAdd(
            $this->getQueue($queue) . ':delayed',
            $this->availableAt($delay),
            $job->getRawBody()
        );
    }

    /**
     * 确保消费者组存在
     *
     * @param string $stream
     * @return void
     */
    protected function ensureGroup($stream)
    {
        if (isset($this->groups[$stream])) {
            return;
        }

        try {
            $this->redis->xGroup('CREATE', $stream, $this->group, '0-0', true);
        } catch (RedisException $e) {
            if (!Str::contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }

        $this->groups[$stream] = true;
    }
}
