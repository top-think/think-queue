<?php

namespace shirakun\facade;

use think\Facade;

/**
 * Class Queue
 * @package think\facade
 * @mixin \shirakun\queue
 */
class Queue extends Facade
{
    protected static function getFacadeClass()
    {
        return 'shirakun\queue';
    }
}
