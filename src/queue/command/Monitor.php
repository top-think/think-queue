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

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\queue\connector\Redis as RedisQueue;
use think\queue\connector\RedisStream;

/**
 * 队列实时监控（只读）
 *
 * 全程仅使用 Redis 只读命令（XINFO / XPENDING / ZCARD / SCAN），
 * 不向业务队列写入任何数据：
 * - Stream：待消费数、执行中（PEL）数、超时将重投数
 * - 吞吐：累计生产（entries-added）与累计完成推导值、实时速率
 * - 延迟任务：总数 + 最近到期时间轴
 * - 消费者：存活 worker 及各自 pending（可判断掉线）
 * - 幂等锁占用数、失败任务数
 */
class Monitor extends Command
{
    protected function configure()
    {
        $this->setName('queue:monitor')
            ->addArgument('connection', Argument::OPTIONAL, 'The name of the queue connection to monitor', null)
            ->addOption('queue', null, Option::VALUE_OPTIONAL, 'The queue to monitor')
            ->addOption('interval', null, Option::VALUE_OPTIONAL, 'Refresh interval in seconds', 2)
            ->addOption('once', null, Option::VALUE_NONE, 'Print a single snapshot and exit (useful for cron)')
            ->addOption('json', null, Option::VALUE_NONE, 'Print a single snapshot as JSON and exit')
            ->setDescription('Realtime read-only monitor of the queue status (redis mode)');
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

        $config = $this->app->config->get("queue.connections.{$connection}", []);
        $type   = $config['type'] ?? 'sync';

        if (!in_array($type, ['redis'], true)) {
            $output->writeln("<error>queue:monitor 仅支持 redis 连接（当前 {$type}）</error>");

            return;
        }

        $queue = $input->getOption('queue') ?: ($config['queue'] ?? 'default');
        $connector = $this->app['queue']->connection($connection);

        // JSON 快照：单次输出，便于程序采集
        if ($input->getOption('json')) {
            $snapshot = $this->snapshot($connector, $queue);
            $snapshot['failed'] = $this->failedCount();
            $output->writeln(json_encode($snapshot, JSON_UNESCAPED_UNICODE));

            return;
        }

        // 单次快照
        if ($input->getOption('once')) {
            $output->write($this->renderSnapshot($connector, $queue));

            return;
        }

        // 实时刷新模式
        $interval = max(1, (int) $input->getOption('interval'));
        $prev     = null;

        while (true) {
            $output->write("\033[H\033[2J");
            $output->write($this->renderSnapshot($connector, $queue, $prev));
            sleep($interval);
        }
    }

    /* ---------------------------------------------------------------
     * 快照构建（不依赖 $this->app，便于独立调用与测试）
     * --------------------------------------------------------------- */

    /**
     * 构建队列状态快照
     *
     * @param RedisQueue $connector
     * @param string $queue
     * @return array
     */
    public function snapshot(RedisQueue $connector, $queue)
    {
        if ($connector instanceof RedisStream) {
            return $this->streamSnapshot($connector, $queue);
        }

        // list 模式降级：仅显示列表队列基础数量
        return [
            'mode'    => 'list',
            'queue'   => $queue,
            'time'    => date('Y-m-d H:i:s'),
            'ready'    => $connector->readyCount($queue),
            'reserved' => $connector->reservedCount($queue),
            'delayed'  => $connector->delayedCount($queue),
        ];
    }

    /**
     * 构建 stream 模式快照
     *
     * @param RedisStream $connector
     * @param string $queue
     * @return array
     */
    public function streamSnapshot(RedisStream $connector, $queue)
    {
        $stream    = $connector->streamStats($queue);
        $pending   = $connector->pendingStats($queue);
        $delayed   = $connector->delayedStats($queue);
        $consumers = $connector->consumersStats($queue);

        // 累计完成推导：生产总数 - Stream 在途 - 重回延迟的已投递任务
        $completed = max(0, $stream['entriesAdded'] - $stream['length'] - $delayed['reReleased']);

        return [
            'mode'       => 'stream',
            'queue'      => $queue,
            'time'       => date('Y-m-d H:i:s'),
            'stream'     => [
                'waiting' => $stream['length'],
                'pending' => $pending['count'],
                'timeout' => $pending['timeout'],
            ],
            'throughput' => [
                'produced'  => $stream['entriesAdded'],
                'completed' => $completed,
                'estimated' => true,
                'sampled'   => $delayed['sampled'],
                'rate'      => null,
            ],
            'delayed'    => [
                'count' => $delayed['count'],
                'next'  => $delayed['next'],
            ],
            'consumers'  => $consumers,
            'locks'      => $connector->lockCount($queue),
        ];
    }

    /**
     * 渲染快照为文本
     *
     * @param RedisQueue $connector
     * @param string $queue
     * @param array|null $prev 上一轮快照（用于计算实时速率），引用传回
     * @return string
     */
    public function renderSnapshot(RedisQueue $connector, $queue, &$prev = null)
    {
        $snapshot = $this->snapshot($connector, $queue);

        // 用上一轮快照的完成数差分计算实时速率
        if ('stream' === $snapshot['mode']
            && is_array($prev)
            && isset($prev['throughput']['completed'], $prev['time'])
        ) {
            $dt = strtotime($snapshot['time']) - strtotime($prev['time']);
            if ($dt > 0) {
                $snapshot['throughput']['rate'] = round(
                    ($snapshot['throughput']['completed'] - $prev['throughput']['completed']) / $dt,
                    1
                );
            }
        }

        $prev = $snapshot;

        if ('list' === $snapshot['mode']) {
            return $this->renderListSnapshot($snapshot);
        }

        return $this->renderStreamSnapshot($snapshot);
    }

    protected function renderStreamSnapshot(array $s): string
    {
        $lines = [];
        $lines[] = sprintf(
            '┌─ think-queue monitor ─ %s ─ queue=%s ─ %s ─',
            $s['mode'],
            $s['queue'],
            $s['time']
        );

        // Stream 行
        $lines[] = sprintf(
            'Stream:  待消费 %d │ 执行中(PEL) %d │ 其中超时 %d',
            $s['stream']['waiting'],
            $s['stream']['pending'],
            $s['stream']['timeout']
        );

        // 吞吐行
        $t = $s['throughput'];
        $mark = $t['sampled'] ? '≈≈' : '≈';
        $rate = null !== $t['rate'] ? sprintf(' │ 速率 %.1f/秒', $t['rate']) : '';
        $lines[] = sprintf(
            '吞吐:    已生产 %d │ 已完成 %s%d%s │ 失败 %s',
            $t['produced'],
            $mark,
            $t['completed'],
            $rate,
            $this->failedCount() ?? '-'
        );

        // 延迟任务行
        $nextDesc = [];
        foreach ($s['delayed']['next'] as $job) {
            $nextDesc[] = sprintf('[%s…] %s @%s', substr($job['jobId'], 0, 8), $job['job'], date('H:i:s', (int) ($job['dueMs'] / 1000)));
        }
        $lines[] = sprintf(
            'Delayed: %d 个%s%s',
            $s['delayed']['count'],
            empty($nextDesc) ? '' : ' │ 最近: ' . implode(' ', array_slice($nextDesc, 0, 3)),
            empty($nextDesc) ? '' : ''
        );

        // 消费者行
        if (empty($s['consumers'])) {
            $lines[] = 'Workers: (无消费者在线)';
        } else {
            $desc = [];
            foreach ($s['consumers'] as $c) {
                $desc[] = sprintf('%s pending=%d idle=%.1fs', $c['name'], $c['pending'], $c['idle'] / 1000);
            }
            $lines[] = 'Workers: ' . implode(' │ ', $desc);
        }

        $lines[] = sprintf('锁占用:  %d', $s['locks']);
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    protected function renderListSnapshot(array $s): string
    {
        return implode("\n", [
            sprintf('┌─ think-queue monitor ─ %s ─ queue=%s ─ %s ─', $s['mode'], $s['queue'], $s['time']),
            sprintf('Ready: %d │ Reserved: %d │ Delayed: %d', $s['ready'], $s['reserved'], $s['delayed']),
            '',
        ]) . "\n";
    }

    /**
     * 失败任务数量（仅 database 失败驱动可统计，其余显示 -）
     *
     * @return int|null
     */
    protected function failedCount()
    {
        try {
            $config = $this->app->config->get('queue.failed', []);

            if (($config['type'] ?? 'none') !== 'database') {
                return null;
            }

            $connection = $config['connection'] ?? null;
            $query = null === $connection
                ? $this->app->db->name($config['table'] ?? 'failed_jobs')
                : $this->app->db->connect($connection)->name($config['table'] ?? 'failed_jobs');

            return (int) $query->count();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
