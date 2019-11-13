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

use think\queue\Job;
use think\queue\connector\Amqp as AmqpQueue;

class Amqp extends Job
{

    /**
     * The redis queue instance.
     * @var RedisQueue
     */
    protected $amqp;

    /**
     * The database job payload.
     * @var Object
     */
    protected $job;

    public function __construct(AmqpQueue $amqp, $job, $queue)
    {   
        $this->job   = $job;
        $this->queue = $queue;
        $this->amqp = $amqp;
    }

    /**
     * Fire the job.
     * @return void
     */
    public function fire()
    {
        $this->resolveAndFire(json_decode($this->getRawBody(), true));
    }

    /**
     * Get the number of times the job has been attempted.
     * @return int
     */
    public function attempts()
    {
        return json_decode($this->job, true)['attempts'];
    }

    /**
     * Get the raw body string for the job.
     * @return string
     */
    public function getRawBody()
    {
        return $this->job;
    }

    /**
     * 删除任务
     *
     * @return void
     */
    public function delete()
    {
        parent::delete();

        $this->amqp->deleteReserved($this->queue, $this->job);
    }

    /**
     * 重新发布任务
     *
     * @param  int $delay
     * @return void
     */
    public function release($delay = 0)
    {
        parent::release($delay);

        $this->delete();

        $this->amqp->release($this->queue, $this->job, $delay, $this->attempts() + 1);
    }
}
