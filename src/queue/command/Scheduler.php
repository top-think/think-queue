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
namespace think\queue\command;

use RedisException;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\queue\connector\RedisStream;

/**
 * 延迟任务调度器（Redis Stream 模式专用）
 *
 * 动态调度机制：
 * 1. 查询延迟集合（ZSET）中最早到期任务的毫秒时间戳；
 * 2. 已到期则立即通过 Lua 脚本批量迁移到 Stream；
 * 3. 未到期则订阅唤醒频道，等待时长取「距最早任务到期时间」与「兜底间隔」的较小值；
 * 4. 收到唤醒通知（生产者新增更早任务）立即重新计算；等待超时则兜底检查一次；
 * 5. 延迟集合为空时按兜底间隔定期检查，保证 Pub/Sub 丢消息或断线期间任务不滞留。
 *
 * 调度器不执行任何业务任务，只负责把到期任务转移给现有 Stream 消费者；
 * 任务迁移使用 Lua 原子脚本，多实例并发运行不会重复迁移同一条任务。
 */
class Scheduler extends Command
{
    protected function configure()
    {
        $this->setName('queue:scheduler')
            ->addArgument('connection', Argument::OPTIONAL, 'The name of the queue connection to schedule', null)
            ->addOption('queue', null, Option::VALUE_OPTIONAL, 'The queue to schedule')
            ->addOption('batch', null, Option::VALUE_OPTIONAL, 'Maximum number of due jobs to migrate per tick')
            ->addOption('interval', null, Option::VALUE_OPTIONAL, 'Fallback check interval in seconds when no wake notification arrives (default from config)', null)
            ->setDescription('Migrate due delayed jobs onto the Redis Stream queue (stream mode only)');
    }

    /**
     * Execute the console command.
     * @param Input  $input
     * @param Output $output
     * @return int|null|void
     */
    public function execute(Input $input, Output $output)
    {
        $connection = $input->getArgument('connection') ?: $this->app->config->get('queue.default');

        $config   = $this->app->config->get("queue.connections.{$connection}", []);
        $queue    = $input->getOption('queue') ?: ($config['queue'] ?? 'default');

        // 兜底检查间隔：命令行优先，否则取连接配置 scheduler.interval
        $interval = (int) $input->getOption('interval');
        if ($interval < 1) {
            $interval = max(1, (int) ($config['scheduler']['interval'] ?? 10));
        }

        $connector = $this->app['queue']->connection($connection);

        if (!$connector instanceof RedisStream) {
            $output->writeln('<error>queue:scheduler 仅支持 redis 连接的 stream 模式</error>');

            return;
        }

        $batch = $input->getOption('batch');
        if (null !== $batch) {
            // --batch 命令行覆盖配置文件的单次迁移数量
            $connector->setSchedulerBatch((int) $batch);
        }

        $output->writeln(sprintf(
            '<info>%s 延迟任务调度器已启动：queue=%s connection=%s interval=%ss</info>',
            date('Y-m-d H:i:s'),
            $queue,
            $connection,
            $interval
        ));

        while (true) {
            try {
                $this->scheduleOnce($connector, $queue, $interval, $output);
            } catch (RedisException $e) {
                // Redis 断线（含订阅连接异常）：休眠后重试，连接由底层自动重连恢复
                $output->writeln(sprintf(
                    '<error>%s Redis 连接异常，1 秒后重试：%s</error>',
                    date('Y-m-d H:i:s'),
                    $e->getMessage()
                ));
                sleep(1);
            }
        }
    }

    /**
     * 执行一轮调度：迁移到期任务，然后等待下一轮
     *
     * @param RedisStream $connector
     * @param string $queue
     * @param int $interval 兜底检查间隔（秒）
     * @param Output $output
     * @return void
     */
    protected function scheduleOnce(RedisStream $connector, $queue, $interval, Output $output)
    {
        // 1. 迁移当前已到期的任务（Lua 原子脚本，多实例安全）
        $moved = $connector->migrateExpiredJobs($connector->delayedKey($queue), $connector->streamKey($queue));

        if ($moved > 0) {
            $output->writeln(sprintf('<info>%s 已迁移 %d 个到期任务到 Stream</info>', date('Y-m-d H:i:s'), $moved));
        }

        // 2. 计算下次等待时长：最早任务到期前主动唤醒，但不超过兜底间隔
        $earliest = $connector->earliestDelayedJob($queue);

        if (null === $earliest) {
            // 延迟集合为空：按兜底间隔定期检查（覆盖唤醒通知丢失的情况）
            $connector->waitForWake($queue, $interval);

            return;
        }

        [$jobId, $dueAtMs] = $earliest;
        $remainingMs = $dueAtMs - $connector->currentTimeMs();

        if ($remainingMs <= 0) {
            // 已有任务到期（可能刚好在上次迁移后到期），立即进入下一轮迁移
            return;
        }

        // 3. 等待唤醒通知或到期：订阅唤醒频道，读超时即为兜底定时器
        $connector->waitForWake($queue, min($remainingMs / 1000, $interval));
    }
}
