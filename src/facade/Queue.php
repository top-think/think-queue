<?php

namespace think\facade;

use think\Facade;

/**
 * Class Queue
 * @package think\facade
 * @mixin \think\Queue
 * @method static void push($job, $data = '', $queue = null) 发布任务（立即执行）
 * @method static void later($delay, $job, $data = '', $queue = null) 发布任务（延迟执行）
 */
class Queue extends Facade
{
    protected static function getFacadeClass()
    {
        return 'queue';
    }
}
