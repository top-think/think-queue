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

namespace think\queue\job;

use think\App;
use think\queue\connector\RedisStream as RedisStreamQueue;

class RedisStream extends Redis
{

    /**
     * The redis stream queue instance.
     * @var RedisStreamQueue
     */
    protected $redis;

    /**
     * The number of times the current Stream entry has been delivered.
     * @var int
     */
    protected $deliveryAttempts;

    public function __construct(App $app, RedisStreamQueue $redis, $job, $entryId, $connection, $queue, $deliveryAttempts = 1)
    {
        parent::__construct($app, $redis, $job, $entryId, $connection, $queue);

        $this->deliveryAttempts = $deliveryAttempts;
    }

    /**
     * Get the number of times the job has been attempted.
     *
     * payload 中的 attempts 是此前 release 时固化的尝试基数，
     * deliveryAttempts 是当前 Stream 条目的投递次数（PEL 统计）。
     *
     * @return int
     */
    public function attempts()
    {
        return $this->payload('attempts') + $this->deliveryAttempts;
    }
}
