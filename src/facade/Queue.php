<?php

namespace think\facade;

use think\Facade;

/**
 * Class Queue
 * @package think\facade
 * @mixin \think\queue
 */
class Queue extends Facade
{
    protected static function getFacadeClass()
    {
        return 'think\queue';
    }
}
