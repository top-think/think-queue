<?php
// +----------------------------------------------------------------------
// | 队列设置
// +----------------------------------------------------------------------

return [
    // 默认队列驱动，可通过 .env 的 QUEUE_DRIVER 切换（sync / database / redis）
    'default'     => env('QUEUE_DRIVER', 'sync'),

    'connections' => [
        'sync'     => [
            'type' => 'sync',
        ],
        'database' => [
            'type'       => 'database',
            // 默认队列名
            'queue'      => env('QUEUE_NAME', 'default'),
            // 任务表名
            'table'      => env('QUEUE_TABLE', 'jobs'),
            // 使用的数据库连接，null 表示使用默认连接
            'connection' => env('QUEUE_CONNECTION'),
        ],
        'redis'    => [
            'type' => 'redis',
            // 队列实现方式：list(默认，列表实现) 或 stream(Stream 消费者组实现，需要 Redis 5.0+)
            'mode' => env('QUEUE_REDIS_MODE', 'list'),
            // 默认队列名
            'queue' => env('QUEUE_NAME', 'default'),

            // Redis 连接信息与 cache.php 共用同一组 REDIS_* 环境变量，一处配置两边生效
            // 服务器地址
            'host'       => env('REDIS_HOST', '127.0.0.1'),
            // 端口
            'port'       => (int) env('REDIS_PORT', 6379),
            // 密码（留空表示无密码）
            'password'   => env('REDIS_PASSWORD', ''),
            // 库序号（建议队列使用独立库，避免与缓存键冲突）
            'select'     => (int) env('REDIS_SELECT', 0),
            // 连接超时（秒）
            'timeout'    => (int) env('REDIS_TIMEOUT', 0),
            // 是否使用长连接
            'persistent' => env('REDIS_PERSISTENT', false),

            // 任务执行超过该秒数未被确认，将被重新投递
            'retry_after' => (int) env('QUEUE_REDIS_RETRY_AFTER', 60),
            // pop 时最长阻塞秒数，为空表示不阻塞
            'block_for' => env('QUEUE_REDIS_BLOCK_FOR'),
            // 任务幂等锁：开启后，执行时长超过 retry_after 被重复投递的任务不会被多个 worker 同时执行
            'lock'      => (bool) env('QUEUE_REDIS_LOCK', true),
            // 幂等锁 TTL（秒），应大于最长任务执行时长；worker 崩溃后任务等待该时长才能被重新认领
            'lock_ttl'  => (int) env('QUEUE_REDIS_LOCK_TTL', 3600),
            // stream 模式专用：消费者组名称
            'group'     => env('QUEUE_REDIS_GROUP', 'think-queue'),
            // stream 模式专用：消费者名称，为空时自动按 主机名-进程号 生成
            'consumer'  => env('QUEUE_REDIS_CONSUMER', ''),
        ],
    ],
    'failed'      => [
        'type'  => env('QUEUE_FAILED_DRIVER', 'none'),
        'table' => env('QUEUE_FAILED_TABLE', 'failed_jobs'),
    ],
];
