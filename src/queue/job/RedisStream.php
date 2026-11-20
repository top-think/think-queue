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
use think\queue\Job;

class RedisStream extends Redis
{

    /**
     * The redis stream queue instance.
     * @var RedisStreamQueue
     */
    protected $redis;

    /**
     * The number of times the job has been delivered.
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
     * @return int
     */
    public function attempts()
    {
        return $this->deliveryAttempts;
    }
}
