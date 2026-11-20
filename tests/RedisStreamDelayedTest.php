<?php

namespace think\test\queue;

use DateTimeImmutable;
use Redis;
use RedisException;
use think\queue\command\Monitor;
use think\queue\connector\RedisStream;

/**
 * Redis Stream 延迟任务集成测试
 *
 * 需要真实 Redis 服务（默认 127.0.0.1:6379，可通过 REDIS_HOST / REDIS_PORT 环境变量覆盖），
 * 所有断言均检查 Redis 实际存储数据（ZSET / HASH / Stream / PEL），而非仅检查函数返回值。
 */
class RedisStreamDelayedTest extends TestCase
{
    /** @var Redis */
    protected $redis;

    /** @var string */
    protected $host;

    /** @var int */
    protected $port;

    /** @var string 测试专用队列名，避免污染其他数据 */
    protected $queue;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('redis')) {
            $this->markTestSkipped('redis扩展未安装');
        }

        $this->host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $this->port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            $this->redis = new Redis();
            $this->redis->connect($this->host, $this->port, 2);
        } catch (RedisException $e) {
            $this->markTestSkipped('无法连接测试 Redis：' . $e->getMessage());
        }

        $this->queue = 'delayed_test_' . bin2hex(random_bytes(4));
    }

    public function tearDown(): void
    {
        if (isset($this->redis)) {
            // 仅清理本测试队列的键
            $keys = $this->redis->keys('{queues:' . $this->queue . '}:*');
            if (!empty($keys)) {
                $this->redis->del(...$keys);
            }
            $this->redis->close();
        }

        parent::tearDown();
    }

    protected function makeConfig(array $overrides = []): array
    {
        return array_merge([
            'type'        => 'redis',
            'mode'        => 'stream',
            'queue'       => $this->queue,
            'host'        => $this->host,
            'port'        => $this->port,
            'password'    => '',
            'select'      => 0,
            'timeout'     => 2,
            'persistent'  => false,
            'retry_after' => 60,
            'block_for'   => null,
            'lock'        => true,
            'lock_ttl'    => 3600,
            'group'       => 'test-group',
            'consumer'    => '',
            'scheduler'   => ['batch' => 5, 'interval' => 10],
        ], $overrides);
    }

    protected function makeConnector(array $overrides = []): RedisStream
    {
        return RedisStream::__make($this->makeConfig($overrides))->setApp($this->app)->setConnection('redis');
    }

    protected function delayedKey(): string
    {
        return '{queues:' . $this->queue . '}:delayed';
    }

    protected function delayedDataKey(): string
    {
        return '{queues:' . $this->queue . '}:delayed:data';
    }

    protected function streamKey(): string
    {
        return '{queues:' . $this->queue . '}:stream';
    }

    /**
     * 场景 1：延迟 5 秒的任务，到期后进入 Stream
     */
    public function testDelayedJobMigratesToStreamWhenDue()
    {
        $connector = $this->makeConnector();
        $jobId     = $connector->later(5, 'test_job', ['name' => 'five-seconds'], $this->queue);

        // 检查 ZSET 时间索引：member 为 job_id，score 为约 5 秒后的毫秒时间戳
        $this->assertSame(1, $this->redis->exists($this->delayedKey()));
        $this->assertSame(1, $this->redis->zCard($this->delayedKey()));
        $score = $this->redis->zScore($this->delayedKey(), $jobId);
        $this->assertNotFalse($score);
        $this->assertEqualsWithDelta($this->nowMs() + 5000, $score, 500);

        // 检查 HASH 任务数据：field 为 job_id，value 为完整 payload
        $this->assertSame(1, $this->redis->hLen($this->delayedDataKey()));
        $payload = json_decode($this->redis->hGet($this->delayedDataKey(), $jobId), true);
        $this->assertSame('test_job', $payload['job']);
        $this->assertSame($jobId, $payload['id']);
        $this->assertSame('five-seconds', $payload['data']['name']);

        // 任务尚未到期，Stream 内无消息
        $this->assertSame(0, $this->redis->xLen($this->streamKey()));

        // 将任务标记为到期后迁移（真实 5 秒等待在 E2E 脚本中验证）
        $this->redis->zAdd($this->delayedKey(), $this->nowMs() - 1, $jobId);
        $connector->migrateExpiredJobs($this->delayedKey(), $this->streamKey());

        // 到期任务已进入 Stream，且原始 job_id 保留
        $this->assertSame(1, $this->redis->xLen($this->streamKey()));
        $this->assertSame(0, $this->redis->zCard($this->delayedKey()));
        $this->assertSame(0, $this->redis->hLen($this->delayedDataKey()));

        $entries = $this->redis->xRange($this->streamKey(), '-', '+', 10);
        $this->assertCount(1, $entries);
        $entryPayload = json_decode(reset($entries)['job'], true);
        $this->assertSame($jobId, $entryPayload['id']);
        $this->assertSame('five-seconds', $entryPayload['data']['name']);
    }

    /**
     * 场景 2：毫秒级时间戳调度（延迟 100ms）
     */
    public function testMillisecondPrecisionScheduling()
    {
        $connector = $this->makeConnector();
        $dueAt     = $this->nowMs() + 100;
        $jobId     = $connector->laterAtMs($dueAt, 'test_job', ['name' => 'millisecond'], $this->queue);

        // score 精确保存为传入的毫秒时间戳
        $this->assertSame($dueAt, (int) $this->redis->zScore($this->delayedKey(), $jobId));

        usleep(250000); // 250ms 后任务已到期

        $connector->migrateExpiredJobs($this->delayedKey(), $this->streamKey());

        $entries = $this->redis->xRange($this->streamKey(), '-', '+', 10);
        $this->assertCount(1, $entries);

        // Stream 条目 ID 的毫秒部分即写入时间：应不早于计划时间，且偏差在容忍范围内
        $entryIdMs = (int) explode('-', array_key_first($entries))[0];
        $this->assertGreaterThanOrEqual($dueAt, $entryIdMs);
        $this->assertLessThan($dueAt + 500, $entryIdMs);

        $payload = json_decode(reset($entries)['job'], true);
        $this->assertSame($jobId, $payload['id']);
    }

    /**
     * 场景 3：多条不同执行时间的任务按计划时间顺序执行
     */
    public function testExecutionOrderFollowsDueTime()
    {
        $connector = $this->makeConnector();

        $ids['late']   = $connector->later(0.3, 'test_job', ['name' => 'late'], $this->queue);
        $ids['first']  = $connector->later(0.1, 'test_job', ['name' => 'first'], $this->queue);
        $ids['middle'] = $connector->later(0.2, 'test_job', ['name' => 'middle'], $this->queue);

        usleep(400000);

        while ($connector->migrateExpiredJobs($this->delayedKey(), $this->streamKey()) > 0) {
            // 迁移完所有到期任务
        }

        $entries = $this->redis->xRange($this->streamKey(), '-', '+', 10);
        $this->assertCount(3, $entries);

        $order = array_map(function ($fields) {
            return json_decode($fields['job'], true)['data']['name'];
        }, $entries);

        $this->assertSame(['first', 'middle', 'late'], array_values($order));
        $this->assertSame(0, $this->redis->zCard($this->delayedKey()));
    }

    /**
     * 场景 4：调度器等待期间收到唤醒通知能够被及时打断
     */
    public function testWakeDuringWait()
    {
        $connector = $this->makeConnector();
        $channel   = $connector->wakeChannel($this->queue);

        // 后台进程等待 300ms（确保订阅已建立）后发布唤醒通知
        $code = sprintf(
            'usleep(300000); $r = new Redis; $r->connect(%s, %d); $r->publish(%s, "1");',
            var_export($this->host, true),
            $this->port,
            var_export($channel, true)
        );
        shell_exec(sprintf('%s -r %s > /dev/null 2>&1 &', escapeshellarg(PHP_BINARY), escapeshellarg($code)));

        $start = microtime(true);
        // 调度器最长等待 5 秒，但应被唤醒通知在约 300ms 时打断
        $woke = $connector->waitForWake($this->queue, 5);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($woke);
        $this->assertLessThan(3, $elapsed, '唤醒通知应在等待期内打断调度器');

        // 无通知时读超时返回 false（兜底路径）
        $start = microtime(true);
        $this->assertFalse($connector->waitForWake($this->queue, 0.2));
        $this->assertGreaterThanOrEqual(0.15, microtime(true) - $start);
    }

    /**
     * 场景 5：多个调度器并发运行，同一条任务不会被重复迁移
     */
    public function testConcurrentSchedulersDoNotDuplicateMigration()
    {
        $connector = $this->makeConnector(['scheduler' => ['batch' => 3, 'interval' => 10]]);

        // 写入 20 条已到期任务
        $jobIds = [];
        for ($i = 0; $i < 20; $i++) {
            $jobIds[] = $connector->later(0, 'test_job', ['name' => 'job-' . $i], $this->queue);
        }
        $this->assertSame(20, $this->redis->zCard($this->delayedKey()));

        if (function_exists('pcntl_fork')) {
            // 真实并发：两个子进程模拟两个调度器实例（子进程使用全新连接，不继承测试上下文）
            $failed = false;
            for ($i = 0; $i < 2; $i++) {
                $pid = pcntl_fork();
                if (0 === $pid) {
                    $code = 0;
                    try {
                        $worker = RedisStream::__make($this->makeConfig());
                        for ($n = 0; $n < 100; $n++) {
                            $worker->migrateExpiredJobs($worker->delayedKey($this->queue), $worker->streamKey($this->queue));
                            if (null === $worker->earliestDelayedJob($this->queue)) {
                                break;
                            }
                            usleep(10000);
                        }
                    } catch (\Throwable $e) {
                        $code = 1;
                    }
                    exit($code);
                } elseif (-1 === $pid) {
                    $this->fail('pcntl_fork 失败');
                }
            }
            while (pcntl_waitpid(-1, $status) > 0) {
                if (0 !== pcntl_wexitstatus($status)) {
                    $failed = true;
                }
            }
            $this->assertFalse($failed, '调度器子进程执行出错');
        } else {
            // pcntl 不可用时退化为双实例交替执行（Redis 单线程语义与并发一致）
            $other = $this->makeConnector();
            for ($n = 0; $n < 100 && $this->redis->zCard($this->delayedKey()) > 0; $n++) {
                $connector->migrateExpiredJobs($this->delayedKey(), $this->streamKey());
                $other->migrateExpiredJobs($this->delayedKey(), $this->streamKey());
            }
        }

        // 每条任务恰好进入 Stream 一次，无重复迁移、无遗漏
        $this->assertSame(20, $this->redis->xLen($this->streamKey()));
        $this->assertSame(0, $this->redis->zCard($this->delayedKey()));
        $this->assertSame(0, $this->redis->hLen($this->delayedDataKey()));

        $entries = $this->redis->xRange($this->streamKey(), '-', '+', 100);
        $migrated = array_map(function ($fields) {
            return json_decode($fields['job'], true)['id'];
        }, $entries);
        $this->assertCount(20, array_unique($migrated));
        $this->assertSameValues($jobIds, array_values($migrated));
    }

    /**
     * 场景 6：调度器异常退出后重启，任务仍能被恢复执行
     */
    public function testSchedulerRestartRecovery()
    {
        // 第一代调度器写入任务后「崩溃」（不迁移、不持有任何状态）
        $connector = $this->makeConnector();
        $jobId     = $connector->later(0.1, 'test_job', ['name' => 'survive-restart'], $this->queue);

        usleep(150000);

        // 任务在「调度器缺失」期间持久保留在 ZSET/HASH 中，不丢失
        $this->assertSame(1, $this->redis->zCard($this->delayedKey()));
        $this->assertSame(1, $this->redis->hLen($this->delayedDataKey()));
        $this->assertSame(0, $this->redis->xLen($this->streamKey()));

        // 新一代调度器启动后从 ZSET 恢复调度，任务被迁移执行
        $restarted = $this->makeConnector();
        $restarted->migrateExpiredJobs($this->delayedKey(), $this->streamKey());

        $this->assertSame(1, $this->redis->xLen($this->streamKey()));
        $this->assertSame(0, $this->redis->zCard($this->delayedKey()));
        $entries = $this->redis->xRange($this->streamKey(), '-', '+', 10);
        $this->assertSame($jobId, json_decode(reset($entries)['job'], true)['id']);
    }

    /**
     * 场景 8：重复调度同一 job_id 的任务具有幂等性
     */
    public function testDuplicateScheduleIsIdempotent()
    {
        $connector = $this->makeConnector();

        // 两次调度产生相同 job_id（模拟重复提交）
        $payload = json_encode([
            'job'      => 'test_job',
            'maxTries' => null,
            'timeout'  => null,
            'data'     => ['name' => 'dup'],
            'id'       => 'fixed-job-id-0001',
            'attempts' => 0,
        ]);

        $connector->laterRaw(10, $payload, $this->queue);
        $connector->laterRaw(10, $payload, $this->queue);

        // ZSET 与 HASH 均只保留一条（同 job_id 覆盖写）
        $this->assertSame(1, $this->redis->zCard($this->delayedKey()));
        $this->assertSame(1, $this->redis->hLen($this->delayedDataKey()));
        $this->assertEqualsWithDelta($this->nowMs() + 10000, $this->redis->zScore($this->delayedKey(), 'fixed-job-id-0001'), 500);

        // 标记到期后连续迁移两次，Stream 中也只应出现一条
        $this->redis->zAdd($this->delayedKey(), $this->nowMs() - 1, 'fixed-job-id-0001');
        $connector->migrateExpiredJobs($this->delayedKey(), $this->streamKey());
        $connector->migrateExpiredJobs($this->delayedKey(), $this->streamKey());

        $this->assertSame(1, $this->redis->xLen($this->streamKey()));
        $this->assertSame(0, $this->redis->hLen($this->delayedDataKey()));
    }

    /**
     * 场景 9：大量延迟任务按批次迁移，不会一次性加载全部任务
     */
    public function testBatchMigrationDoesNotLoadAllJobs()
    {
        // 单次迁移上限 5
        $connector = $this->makeConnector(['scheduler' => ['batch' => 5, 'interval' => 10]]);

        // 30 条已到期 + 20 条未到期
        for ($i = 0; $i < 30; $i++) {
            $connector->later(0, 'test_job', ['name' => 'due-' . $i], $this->queue);
        }
        for ($i = 0; $i < 20; $i++) {
            $connector->later(60, 'test_job', ['name' => 'future-' . $i], $this->queue);
        }

        $this->assertSame(50, $this->redis->zCard($this->delayedKey()));

        // 每轮迁移最多 5 条，未到期任务始终保留
        $calls = 0;
        $total = 0;
        while (($moved = $connector->migrateExpiredJobs($this->delayedKey(), $this->streamKey())) > 0) {
            $this->assertLessThanOrEqual(5, $moved, '单次迁移不得超过配置的批量上限');
            $total += $moved;
            $calls++;
        }

        $this->assertSame(30, $total);
        $this->assertSame(20, $this->redis->zCard($this->delayedKey()));
        $this->assertSame(30, $this->redis->xLen($this->streamKey()));
        $this->assertSame(20, $this->redis->hLen($this->delayedDataKey()));

        // HASH 中仅保留未到期任务的 payload
        $remaining = $this->redis->hKeys($this->delayedDataKey());
        $this->assertCount(20, $remaining);
    }

    /**
     * 场景 10：立即执行任务原有功能不受影响
     */
    public function testImmediatePushStillWorks()
    {
        $connector = $this->makeConnector();

        $jobId = $connector->pushRaw(json_encode([
            'job'      => 'test_job',
            'maxTries' => null,
            'timeout'  => null,
            'data'     => ['name' => 'immediate'],
            'id'       => 'immediate-job-id',
            'attempts' => 0,
        ]), $this->queue);

        // Stream 内直接有消息，延迟集合为空
        $this->assertSame(1, $this->redis->xLen($this->streamKey()));
        $this->assertSame(0, $this->redis->zCard($this->delayedKey()));

        $job = $connector->pop($this->queue);
        $this->assertNotNull($job);
        $this->assertSame('immediate-job-id', $job->getJobId());
        $this->assertSame(1, $job->attempts());

        // 确认后消息删除、PEL 清空、幂等锁释放
        $connector->deleteReserved($this->queue, $job);
        $this->assertSame(0, $this->redis->xLen($this->streamKey()));
        $this->assertSame(0, $this->redis->xPending($this->streamKey(), 'test-group')[0]);
        $this->assertSame(0, $this->redis->exists('{queues:' . $this->queue . '}:lock:immediate-job-id'));
    }

    /**
     * 场景 11：现有 Stream ACK、重试（PEL/XCLAIM）、release 机制未被破坏
     */
    public function testStreamAckAndRetryPreserved()
    {
        // retry_after=1 秒以便快速触发重新投递；关闭幂等锁以便观察重复投递计数
        $connector = $this->makeConnector(['retry_after' => 1, 'lock' => false]);

        $jobId = $connector->pushRaw(json_encode([
            'job'      => 'test_job',
            'maxTries' => null,
            'timeout'  => null,
            'data'     => ['name' => 'retry'],
            'id'       => 'retry-job-id',
            'attempts' => 0,
        ]), $this->queue);

        // 第一次投递进入 PEL
        $job = $connector->pop($this->queue);
        $this->assertNotNull($job);
        $this->assertSame(1, $job->attempts());
        $this->assertSame(1, $this->redis->xPending($this->streamKey(), 'test-group')[0]);

        // 超过 retry_after 后重新投递，投递次数累加
        sleep(2);
        $job = $connector->pop($this->queue);
        $this->assertNotNull($job, '超时未确认的消息应被重新投递');
        $this->assertSame(2, $job->attempts());

        // 失败后 release（deleteAndRelease）：以新延迟格式重新入队，attempts 基数固化进 payload
        $connector->deleteAndRelease($this->queue, $job, 0);

        $this->assertSame(0, $this->redis->xPending($this->streamKey(), 'test-group')[0]);
        $this->assertSame(1, $this->redis->zCard($this->delayedKey()));
        $this->assertSame('retry-job-id', $this->redis->zRange($this->delayedKey(), 0, -1)[0]);
        $released = json_decode($this->redis->hGet($this->delayedDataKey(), 'retry-job-id'), true);
        $this->assertSame(2, $released['attempts']);

        // 重新迁移后任务再次投递，attempts 基数被保留
        $connector->migrateExpiredJobs($this->delayedKey(), $this->streamKey());
        $job = $connector->pop($this->queue);
        $this->assertNotNull($job);
        $this->assertSame(3, $job->attempts());

        // 最终 ACK 清理
        $connector->deleteReserved($this->queue, $job);
        $this->assertSame(0, $this->redis->xPending($this->streamKey(), 'test-group')[0]);
        $this->assertSame(0, $this->redis->xLen($this->streamKey()));
    }

    /**
     * later 支持传入 DateTimeInterface 指定执行时间
     */
    public function testLaterAcceptsDateTimeInterface()
    {
        $connector = $this->makeConnector();
        $dueAt     = new DateTimeImmutable('+10 seconds');

        $connector->later($dueAt, 'test_job', ['name' => 'datetime'], $this->queue);

        [$jobId, $score] = $connector->earliestDelayedJob($this->queue);

        $this->assertNotNull($jobId);
        $this->assertEqualsWithDelta($dueAt->getTimestamp() * 1000, $score, 1000);
    }

    /**
     * 监控只读指标：stream/PEL/延迟/锁/消费者
     */
    public function testMonitorStats()
    {
        $connector = $this->makeConnector(['retry_after' => 1]);

        $makePayload = function (string $id, int $attempts = 0): string {
            return json_encode([
                'job'      => 'test_job',
                'maxTries' => null,
                'timeout'  => null,
                'data'     => ['name' => $id],
                'id'       => $id,
                'attempts' => $attempts,
            ]);
        };

        // 2 条立即任务 + 1 条新延迟任务 + 1 条重回延迟任务（attempts 基数 > 0）
        $connector->pushRaw($makePayload('mon-a'), $this->queue);
        $connector->pushRaw($makePayload('mon-b'), $this->queue);
        $connector->laterRaw(60, $makePayload('mon-c'), $this->queue);
        $connector->laterRaw(60, $makePayload('mon-d', 2), $this->queue);

        // pop 一条进入 PEL（持有幂等锁）
        $job = $connector->pop($this->queue);
        $this->assertNotNull($job);
        $connector->deleteReserved($this->queue, $job);

        $stream = $connector->streamStats($this->queue);
        $this->assertSame(1, $stream['length']);
        $this->assertGreaterThanOrEqual(2, $stream['entriesAdded']);

        $pending = $connector->pendingStats($this->queue);
        $this->assertSame(0, $pending['count']);
        $this->assertSame(0, $pending['timeout']);

        $delayed = $connector->delayedStats($this->queue);
        $this->assertSame(2, $delayed['count']);
        $this->assertSame(1, $delayed['reReleased']);
        $this->assertCount(2, $delayed['next']);
        $this->assertSame('test_job', $delayed['next'][0]['job']);
        $this->assertGreaterThan($this->nowMs(), $delayed['next'][0]['dueMs']);

        $consumers = $connector->consumersStats($this->queue);
        $this->assertNotEmpty($consumers);

        // 重新投递一条进入 PEL 后锁被占用，确认删除后释放
        $job = $connector->pop($this->queue);
        $this->assertNotNull($job);
        $this->assertSame(1, $connector->lockCount($this->queue));
        $connector->deleteReserved($this->queue, $job);
        $this->assertSame(0, $connector->lockCount($this->queue));
    }

    /**
     * 监控快照与渲染（与 queue:monitor 命令共用逻辑）
     */
    public function testMonitorSnapshot()
    {
        $monitor   = new Monitor();
        $connector = $this->makeConnector(['retry_after' => 1]);

        $connector->pushRaw(json_encode([
            'job'      => 'test_job',
            'maxTries' => null,
            'timeout'  => null,
            'data'     => ['name' => 'snap'],
            'id'       => 'snap-a',
            'attempts' => 0,
        ]), $this->queue);

        $snapshot = $monitor->snapshot($connector, $this->queue);

        $this->assertSame('stream', $snapshot['mode']);
        $this->assertSame($this->queue, $snapshot['queue']);
        $this->assertSame(1, $snapshot['stream']['waiting']);
        $this->assertSame(0, $snapshot['stream']['pending']);
        $this->assertSame(1, $snapshot['throughput']['produced']);
        $this->assertTrue($snapshot['throughput']['estimated']);
        $this->assertSame(0, $snapshot['delayed']['count']);
        $this->assertIsArray($snapshot['consumers']);

        // 渲染输出包含关键信息
        $prev = null;
        $text = $monitor->renderSnapshot($connector, $this->queue, $prev);
        $this->assertStringContainsString('待消费 1', $text);
        $this->assertStringContainsString('已生产 1', $text);
        $this->assertStringContainsString('已完成 ≈0', $text);
    }

    protected function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * 断言两个数组包含相同的元素（忽略顺序）
     */
    protected function assertSameValues(array $expected, array $actual)
    {
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }
}
