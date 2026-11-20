<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

use think\Env;

// 所有配置项均支持环境变量覆盖，对应关系 Env::get('a.b.c') => .env 中 [A] B_C 或系统环境变量 PHP_A_B_C
return [
    'default'     => Env::get('queue.default', 'sync'),
    'connections' => [
        'sync'     => [
            'type' => 'sync',
        ],
        'database' => [
            'type'       => 'database',
            'queue'      => Env::get('queue.database.queue', 'default'),
            'table'      => Env::get('queue.database.table', 'jobs'),
            'connection' => Env::get('queue.database.connection'),
        ],
        'redis'    => [
            'type'       => 'redis',
            // 队列实现方式：list(默认，列表实现) 或 stream(Stream 消费者组实现，需要 Redis 5.0+)
            'mode'       => Env::get('queue.redis.mode', 'list'),
            'queue'      => Env::get('queue.redis.queue', 'default'),
            'host'       => Env::get('queue.redis.host', '127.0.0.1'),
            'port'       => (int) Env::get('queue.redis.port', 6379),
            'password'   => Env::get('queue.redis.password', ''),
            'select'     => (int) Env::get('queue.redis.select', 0),
            'timeout'    => (int) Env::get('queue.redis.timeout', 0),
            'persistent' => Env::get('queue.redis.persistent', false),
            // 任务执行超过该秒数未被确认，将被重新投递
            'retry_after' => (int) Env::get('queue.redis.retry_after', 60),
            // pop 时最长阻塞秒数，为空表示不阻塞
            'block_for'  => Env::get('queue.redis.block_for'),
            // stream 模式专用：消费者组名称
            'group'      => Env::get('queue.redis.group', 'think-queue'),
            // stream 模式专用：消费者名称，为空时自动按主机名+进程号生成
            'consumer'   => Env::get('queue.redis.consumer', ''),
        ],
    ],
    'failed'      => [
        'type'  => Env::get('queue.failed.type', 'none'),
        'table' => Env::get('queue.failed.table', 'failed_jobs'),
    ],
];
