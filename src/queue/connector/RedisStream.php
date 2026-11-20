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
 * 延迟任务采用 ZSET + HASH 组合存储：
 * - {queues:xxx}:delayed       ZSET，member 为任务 job_id，score 为计划执行的 Unix 毫秒时间戳
 * - {queues:xxx}:delayed:data  HASH，field 为任务 job_id，value 为任务 payload JSON
 * - {queues:xxx}:delayed:wake  Pub/Sub 频道，生产者写入延迟任务后发布唤醒通知（仅用于唤醒，不作为可靠性依据）
 *
 * 到期任务由调度器（queue:scheduler）或 worker pop() 通过 Lua 脚本原子迁移到 Stream，
 * 之后完全交由现有 Stream 消费者组机制处理。
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
     * 单次迁移到期任务的数量上限
     * @var int
     */
    protected $schedulerBatch = 100;

    /**
     * 已确认创建过消费者组的 Stream
     * @var array
     */
    protected $groups = [];

    public function __construct($redis, $default = 'default', $retryAfter = 60, $blockFor = null, $lock = true, $lockTtl = 3600, $group = 'think-queue', $consumer = null, $schedulerBatch = 100, $clientConfig = [])
    {
        parent::__construct($redis, $default, $retryAfter, $blockFor, $lock, $lockTtl, $clientConfig);

        $this->group          = $group;
        $this->consumer       = $consumer ?: (gethostname() ?: 'worker') . '-' . getmypid();
        $this->schedulerBatch = max(1, (int) $schedulerBatch);
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
            $config['group'] ?? 'think-queue',
            $config['consumer'] ?? '',
            $config['scheduler']['batch'] ?? 100,
            $config
        );
    }

    public function size($queue = null)
    {
        $queue  = $this->getQueue($queue);
        $stream = $queue . ':stream';

        // Stream 内的条目包含已投递但未确认（pending）的消息，因此无需再单独累加 pending 数量
        return $this->redis->xLen($stream) + $this->redis->zCard($queue . ':delayed');
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
        $prefixed = $this->getQueue($queue);
        $stream   = $prefixed . ':stream';

        $this->ensureGroup($stream);

        $this->migrateExpiredJobs($prefixed . ':delayed', $stream);

        // 优先认领超过 retry_after 仍未确认的消息
        // 注意传给 Job 的必须是原始队列名，Job 内部会自行再加前缀
        if (!empty($job = $this->claimPending($stream, $queue))) {
            return $this->withLock($prefixed, $job);
        }

        return $this->withLock($prefixed, $this->readNewJob($stream, $queue));
    }

    /**
     * 尝试获取任务幂等锁后再返回 Job
     *
     * 若锁被其他 worker 持有（同一任务因执行时长超过 retry_after 被重复投递），
     * 跳过本次投递避免重复执行；消息仍保留在 PEL 中，由持有者完成或锁过期后正常流转。
     *
     * @param string $prefixed
     * @param RedisStreamJob|null $job
     * @return RedisStreamJob|null
     */
    protected function withLock($prefixed, $job)
    {
        if (empty($job)) {
            return null;
        }

        if (!$this->acquireJobLock($prefixed, $job->getJobId())) {
            return null;
        }

        return $job;
    }

    /**
     * 迁移到期任务到 Stream（Lua 原子操作）
     *
     * 在一个 Lua 脚本内完成「查询到期任务 → 写入 Stream → 清理 ZSET/HASH」，
     * 多个调度器/worker 并发调用时由 Redis 单线程执行保证同一条任务只会被迁移一次。
     *
     * 任务先写入 Stream 再移除时间索引，脚本整体原子执行，不存在迁移中途丢失的窗口；
     * 若 HASH 中 payload 缺失（异常数据），同样移除 ZSET 索引避免任务永久滞留。
     *
     * @param string $from 延迟任务 ZSET 键名（{queues:xxx}:delayed）
     * @param string $to   目标 Stream 键名
     * @param bool $attempt 兼容保留参数（list 驱动签名一致）
     * @return int 本次迁移的任务数量
     */
    public function migrateExpiredJobs($from, $to, $attempt = true)
    {
        $script = <<<'LUA'
local ids = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, tonumber(ARGV[2]))
for _, id in ipairs(ids) do
    local payload = redis.call('HGET', KEYS[2], id)
    if payload then
        redis.call('XADD', KEYS[3], '*', 'job', payload)
        redis.call('HDEL', KEYS[2], id)
    end
    redis.call('ZREM', KEYS[1], id)
end
return #ids
LUA;

        return (int) $this->redis->eval($script, [
            $from,
            $from . ':data',
            $to,
            $this->currentTimeMs(),
            $this->schedulerBatch,
        ], 3);
    }

    /**
     * 认领投递超时（idle >= retry_after）的待处理消息
     *
     * 分页扫描待处理列表（按 ID 升序，最旧优先），避免 pending 堆积时漏认领。
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

        $minIdle  = $this->retryAfter * 1000;
        $pageSize = 100;
        $lastId   = null;

        while (true) {
            $entries = $this->redis->xPending($stream, $this->group, null === $lastId ? '-' : $lastId, '+', $pageSize);

            if (empty($entries)) {
                return null;
            }

            $count = count($entries);
            // XPENDING 范围起始为包含语义，跳过上一页末尾已扫描过的消息
            $skip = null !== $lastId;

            foreach ($entries as $entry) {
                // 兼容 phpredis 不同版本的返回格式
                $id         = is_array($entry) ? ($entry['id'] ?? $entry[0] ?? null) : null;
                $idle       = $entry['idle'] ?? $entry[2] ?? 0;
                $deliveries = $entry['deliveries'] ?? $entry[3] ?? 1;

                if ($skip) {
                    $skip = false;
                    continue;
                }

                if (empty($id)) {
                    continue;
                }

                $lastId = $id;

                if ($idle < $minIdle) {
                    continue;
                }

                $claimed = $this->redis->xClaim($stream, $this->group, $this->consumer, $minIdle, [$id]);

                foreach ($claimed as $entryId => $fields) {
                    // XCLAIM 后投递次数 +1，本次为第 deliveries+1 次投递
                    return $this->newJob($fields['job'], $entryId, $deliveries + 1, $queue);
                }
            }

            if ($count < $pageSize) {
                return null;
            }
        }
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
        if (!empty($this->blockFor)) {
            // BLOCK 为毫秒，phpredis 不允许负数，因此不阻塞时不能传 -1
            $result = $this->redis->xReadGroup($this->group, $this->consumer, [$stream => '>'], 1, $this->blockFor * 1000);
        } else {
            $result = $this->redis->xReadGroup($this->group, $this->consumer, [$stream => '>'], 1);
        }

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

        $this->releaseJobLock($this->getQueue($queue), $job->getJobId());
    }

    /**
     * 删除保留任务并重新发布（放入延迟集合）
     *
     * release 会把消息移出 PEL，消费者组的投递次数随之清零，
     * 因此必须把当前已尝试次数写入 payload 作为基数，避免 maxTries 失效导致无限重试。
     *
     * @param string $queue
     * @param RedisStreamJob $job
     * @param int $delay 延迟秒数
     * @return void
     */
    public function deleteAndRelease($queue, $job, $delay)
    {
        $prefixed = $this->getQueue($queue);

        $this->deleteReserved($queue, $job);

        // 重新入队前把尝试次数固化进 payload
        $payload = json_decode($job->getRawBody(), true);
        $payload['attempts'] = $job->attempts();
        $jobId    = $payload['id'] ?? null;
        $payload  = json_encode($payload);

        if (empty($jobId)) {
            return;
        }

        $this->transaction(function () use ($prefixed, $jobId, $delay, $payload) {
            $this->redis->zAdd($prefixed . ':delayed', $this->availableAtMs($delay), $jobId);
            $this->redis->hSet($prefixed . ':delayed:data', $jobId, $payload);
        });

        // 注意传入原始队列名，publishWake 内部会自行加前缀
        $this->publishWake($queue);
    }

    /**
     * 延迟到指定的毫秒时间戳执行
     *
     * @param int $timestampMs 计划执行的 Unix 毫秒时间戳
     * @param mixed $job
     * @param mixed $data
     * @param string|null $queue
     * @return string|null 任务 job_id
     */
    public function laterAtMs($timestampMs, $job, $data = '', $queue = null)
    {
        return $this->laterRawAtMs((int) $timestampMs, $this->createPayload($job, $data), $queue);
    }

    public function laterRaw($delay, $payload, $queue = null, array $options = [])
    {
        return $this->laterRawAtMs($this->availableAtMs($delay), $payload, $queue);
    }

    /**
     * 按毫秒时间戳写入延迟任务
     *
     * ZSET 仅保存时间索引（member = job_id，score = 毫秒时间戳），
     * 任务 payload 保存在独立 HASH 中；写入后发布唤醒通知，
     * 保存相同 job_id 的任务会覆盖原计划时间，天然支持重复提交去重。
     *
     * @param int $timestampMs
     * @param string $payload
     * @param string|null $queue
     * @return string|null 任务 job_id
     */
    protected function laterRawAtMs($timestampMs, $payload, $queue = null)
    {
        $prefixed = $this->getQueue($queue);
        $jobId    = $this->getPayloadJobId($payload);

        if (empty($jobId)) {
            return null;
        }

        // ZSET 时间索引与 HASH 任务数据在事务内写入，保证两者一致
        $this->transaction(function () use ($prefixed, $jobId, $timestampMs, $payload) {
            $this->redis->zAdd($prefixed . ':delayed', $timestampMs, $jobId);
            $this->redis->hSet($prefixed . ':delayed:data', $jobId, $payload);
        });

        // 注意传入原始队列名，publishWake 内部会自行加前缀
        $this->publishWake($queue);

        return $jobId;
    }

    /**
     * 设置单次迁移到期任务的数量上限
     *
     * @param int $batch
     * @return void
     */
    public function setSchedulerBatch($batch)
    {
        $this->schedulerBatch = max(1, (int) $batch);
    }

    /**
     * 延迟任务时间索引 ZSET 键名
     *
     * @param string|null $queue
     * @return string
     */
    public function delayedKey($queue = null)
    {
        return $this->getQueue($queue) . ':delayed';
    }

    /**
     * Stream 队列键名
     *
     * @param string|null $queue
     * @return string
     */
    public function streamKey($queue = null)
    {
        return $this->getQueue($queue) . ':stream';
    }

    /**
     * 获取延迟集合中最早到期（或唯一）的任务
     *
     * @param string|null $queue
     * @return array|null [jobId, 计划执行的毫秒时间戳]，集合为空时返回 null
     */
    public function earliestDelayedJob($queue = null)
    {
        $result = $this->redis->zRangeByScore(
            $this->getQueue($queue) . ':delayed',
            '-inf',
            '+inf',
            ['withscores' => true, 'limit' => [0, 1]]
        );

        if (empty($result)) {
            return null;
        }

        $jobId = array_key_first($result);

        return [$jobId, (int) $result[$jobId]];
    }

    /**
     * 等待延迟任务的唤醒通知
     *
     * 使用独立的订阅连接订阅唤醒频道，读超时即为兜底定时器：
     * - 等待期内收到唤醒通知：立即返回 true，由调用方重新查询最早任务并调整等待
     * - 等待期内无通知（读超时）：返回 false，作为 Pub/Sub 丢消息、断线重连期间的定期兜底检查
     *
     * @param string|null $queue
     * @param float $timeoutSeconds 最长等待秒数
     * @return bool 是否收到了唤醒通知
     */
    public function waitForWake($queue, $timeoutSeconds)
    {
        $timeoutSeconds = max(0.05, (float) $timeoutSeconds);

        // 手工构造（未传入连接配置）时无法建立订阅连接，退化为纯定时等待
        if (empty($this->clientConfig)) {
            usleep((int) ($timeoutSeconds * 1000000));

            return false;
        }

        $client = new \Redis;
        $this->connectSubscriber($client, $this->clientConfig);
        $client->setOption(\Redis::OPT_READ_TIMEOUT, $timeoutSeconds);

        try {
            $client->subscribe([$this->wakeChannel($queue)], function ($client) use ($queue) {
                // 收到唤醒通知后立即取消订阅，打断阻塞等待返回调度循环
                $client->unsubscribe([$this->wakeChannel($queue)]);
            });

            return true;
        } catch (RedisException $e) {
            // 读超时属于正常兜底路径
            return false;
        } finally {
            try {
                $client->close();
            } catch (\Throwable $e) {
                // 忽略关闭时的连接异常
            }
        }
    }

    /**
     * 建立订阅用的 Redis 连接
     *
     * @param \Redis $client
     * @param array $config
     * @return void
     */
    protected function connectSubscriber($client, $config)
    {
        $client->connect($config['host'], $config['port'], (int) $config['timeout']);

        if ('' != $config['password']) {
            $client->auth($config['password']);
        }

        if (0 != $config['select']) {
            $client->select($config['select']);
        }
    }

    /**
     * 发布延迟任务唤醒通知
     *
     * @param string|null $queue
     * @return void
     */
    public function publishWake($queue = null)
    {
        try {
            $this->redis->publish($this->wakeChannel($queue), '1');
        } catch (RedisException $e) {
            // 唤醒通知发布失败不影响任务写入，由调度器的兜底检查覆盖
        }
    }

    /**
     * 延迟任务唤醒频道名
     *
     * @param string|null $queue
     * @return string
     */
    public function wakeChannel($queue = null)
    {
        return $this->getQueue($queue) . ':delayed:wake';
    }

    /* ---------------------------------------------------------------
     * 只读监控指标（供 queue:monitor 使用，不产生任何写入）
     * --------------------------------------------------------------- */

    /**
     * Stream 概况
     *
     * entries-added 为自 Stream 创建以来累计 XADD 的条目数（单调递增），
     * 可用于推导累计完成数：已完成 ≈ entries-added - length - 重回延迟数
     *
     * @param string|null $queue
     * @return array [length, entriesAdded]
     */
    public function streamStats($queue = null)
    {
        $info = $this->redis->xInfo('STREAM', $this->streamKey($queue));

        return [
            'length'       => (int) ($info['length'] ?? 0),
            'entriesAdded' => (int) ($info['entries-added'] ?? 0),
        ];
    }

    /**
     * 待处理（PEL）概况
     *
     * timeout 为已投递但 idle 超过 retry_after、将被重新投递的数量，
     * 最多统计 500 条（超出时返回 500 作为下限）
     *
     * @param string|null $queue
     * @return array [count, timeout]
     */
    public function pendingStats($queue = null)
    {
        $streamKey = $this->streamKey($queue);

        $summary = $this->redis->xPending($streamKey, $this->group);
        $count   = is_array($summary) ? (int) $summary[0] : 0;

        $timeout = 0;
        if ($count > 0 && null !== $this->retryAfter) {
            $entries = $this->redis->xPending(
                $streamKey,
                $this->group,
                '-',
                '+',
                500,
                null,
                $this->retryAfter * 1000
            );
            if (is_array($entries)) {
                $timeout = count($entries);
            }
        }

        return ['count' => $count, 'timeout' => $timeout];
    }

    /**
     * 消费者概况（可据此判断 worker 是否存活）
     *
     * @param string|null $queue
     * @return array 每项 [name, pending, idle, inactive]
     */
    public function consumersStats($queue = null)
    {
        $consumers = $this->redis->xInfo('CONSUMERS', $this->streamKey($queue), $this->group);

        $out = [];
        foreach ((array) $consumers as $consumer) {
            $out[] = [
                'name'     => $consumer['name'] ?? '?',
                'pending'  => (int) ($consumer['pending'] ?? 0),
                'idle'     => (int) ($consumer['idle'] ?? 0),
                'inactive' => (int) ($consumer['inactive'] ?? -1),
            ];
        }

        return $out;
    }

    /**
     * 延迟任务概况
     *
     * reReleased 为「已投递过（payload attempts 基数 > 0）后又重回延迟集合」的任务数，
     * 用于完成数推导；延迟任务超过 $sample 条时只统计最早（最先到期）的 $sample 条并标记 sampled
     *
     * @param string|null $queue
     * @param int $limit 最近到期任务的展示条数
     * @param int $sample attempts 采样的任务数上限
     * @return array [count, next, reReleased, sampled]
     */
    public function delayedStats($queue = null, $limit = 5, $sample = 300)
    {
        $key     = $this->delayedKey($queue);
        $dataKey = $key . ':data';
        $count   = (int) $this->redis->zCard($key);

        // 最近将到期的任务（简略信息）
        $next = [];
        if ($limit > 0 && $count > 0) {
            $entries = $this->redis->zRange($key, 0, $limit - 1, true);
            foreach ($entries as $jobId => $dueMs) {
                $payload = json_decode((string) $this->redis->hGet($dataKey, $jobId), true);
                $next[] = [
                    'jobId' => (string) $jobId,
                    'dueMs' => (int) $dueMs,
                    'job'   => $payload['job'] ?? '?',
                ];
            }
        }

        // 统计重回延迟的任务（attempts 基数 > 0）
        $reReleased = 0;
        $sampled    = false;
        if ($count > 0) {
            $ids     = $this->redis->zRange($key, 0, $sample - 1);
            $sampled = $count > $sample;
            if (!empty($ids)) {
                $payloads = $this->redis->hMGet($dataKey, $ids);
                foreach ((array) $payloads as $payload) {
                    $decoded = is_string($payload) ? json_decode($payload, true) : null;
                    if (($decoded['attempts'] ?? 0) > 0) {
                        $reReleased++;
                    }
                }
            }
        }

        return ['count' => $count, 'next' => $next, 'reReleased' => $reReleased, 'sampled' => $sampled];
    }

    /**
     * 幂等锁占用数量（SCAN 有界扫描，不阻塞 Redis）
     *
     * @param string|null $queue
     * @param int $maxScan 扫描上限，达到后停止
     * @return int
     */
    public function lockCount($queue = null, $maxScan = 2000)
    {
        $iterator = null;
        $count    = 0;

        while (false !== ($keys = $this->redis->scan($iterator, $this->getQueue($queue) . ':lock:*', 500))) {
            $count += count($keys);
            if (0 === $iterator || $count >= $maxScan) {
                break;
            }
        }

        return $count;
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
