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
use think\queue\driver\Iron as IronQueue;

class Iron extends Job
{

    /**
     * The Iron queue instance.
     *
     * @var IronQueue
     */
    protected $iron;

    /**
     * The IronMQ message instance.
     *
     * @var object
     */
    protected $job;

    /**
     * Indicates if the message was a push message.
     *
     * @var bool
     */
    protected $pushed = false;

    public function __construct(IronQueue $iron, $job, $pushed = false)
    {
        $this->job    = $job;
        $this->iron   = $iron;
        $this->pushed = $pushed;
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
        return json_decode($this->job->body, true)['attempts'];
    }

    public function delete()
    {
        parent::delete();

        if (isset($this->job->pushed)) {
            return;
        }

        $this->iron->deleteMessage($this->getQueue(), $this->job->id, $this->job->reservation_id);
    }

    public function release($delay = 0)
    {
        parent::release($delay);

        if (!$this->pushed) {
            $this->delete();
        }

        $this->recreateJob($delay);
    }

    protected function recreateJob($delay)
    {
        $payload = json_decode($this->job->body, true);

        $payload['attempts'] = $payload['attempts'] + 1;

        $this->iron->recreate(json_encode($payload), $this->getQueue(), $delay);
    }


    /**
     * Get the raw body string for the job.
     * @return string
     */
    public function getRawBody()
    {
        return $this->job->body;
    }

    public function getQueue()
    {
        return json_decode($this->job->body, true)['queue'];
    }
}