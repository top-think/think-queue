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

namespace think\queue;

use think\Config;

class Queue
{
    protected static $instance = [];

    public static function push($job, $data = '', $queue = null)
    {
        self::handle()->push($job, $data, $queue);

    }

    public static function later($delay, $job, $data = '', $queue = null)
    {
        self::handle()->later($delay, $job, $data, $queue);
    }

    public static function pop($queue = null)
    {
        return self::handle()->pop($queue);
    }

    public static function marshal()
    {
        if (!method_exists(self::handle(), 'marshal'))
            throw new \RuntimeException('push queues not support for this type');

        self::handle()->marshal();
    }

    private static function handle()
    {
        $options = Config::get('queue');
        $type    = !empty($options['type']) ? $options['type'] : 'Sync';

        if (!isset(self::$instance[$type])) {
            $class = (!empty($options['namespace']) ? $options['namespace'] : '\\think\\queue\\driver\\') . ucwords($type);

            self::$instance[$type] = new $class($options);
        }
        return self::$instance[$type];
    }

}