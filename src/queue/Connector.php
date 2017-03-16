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

namespace think\queue;

use InvalidArgumentException;

abstract class Connector
{
    protected $options = [];

    abstract public function push($job, $data = '', $queue = null);

    abstract public function later($delay, $job, $data = '', $queue = null);

    abstract public function pop($queue = null);

    public function marshal()
    {
        throw new \RuntimeException('pop queues not support for this type');
    }

    protected function createPayload($job, $data = '', $queue = null)
    {
        if (is_object($job)) {
            $payload = json_encode([
                'job'  => 'think\queue\CallQueuedHandler@call',
                'data' => [
                    'commandName' => get_class($job),
                    'command'     => serialize(clone $job),
                ],
            ]);
        } else {
            $payload = json_encode($this->createPlainPayload($job, $data));
        }

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidArgumentException('Unable to create payload: ' . json_last_error_msg());
        }

        return $payload;
    }

    protected function createPlainPayload($job, $data)
    {
        return ['job' => $job, 'data' => $data];
    }

    protected function setMeta($payload, $key, $value)
    {
        $payload       = json_decode($payload, true);
        $payload[$key] = $value;
        $payload       = json_encode($payload);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidArgumentException('Unable to create payload: ' . json_last_error_msg());
        }

        return $payload;
    }

    /**
     * 根据队列名称获取该队列的 expire 时间.<br>
     * 用户可以在配置文件的 'expire_override'段中分别设置不同队列的expire时间<br>
     * 未配置'expire_override'时，取'expire'段中配置的时间<br>
     * 配置了'expire_override'时，取'expire_override'中对应的$queue配置的时间<br>
     * 'expire_override'中配置的值也允许为null，表示不检查该队列的过期任务<br>
     * @param $queue
     */
    abstract protected function getQueueExpireTime($queue);
}
