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

use think\queue\connector\Database as DatabaseQueue;
use think\queue\Job;

class Database extends Job {
    /**
     * The database queue instance.
     *
     * @var DatabaseQueue
     */
    protected $database;

    /**
     * The database job payload.
     *
     * @var Object
     */
    protected $job;

    public function __construct(DatabaseQueue $database, $job, $queue) {
        $this->job = $job;
        $this->queue = $queue;
        $this->database = $database;
        $this->job->attempts = $this->job->attempts + 1;
    }

    /**
     * 执行任务
     *
     * @return void
     */
    public function fire() {
        $this->resolveAndFire(json_decode($this->job->payload, true));
    }

    /**
     * 删除任务
     *
     * @return void
     */
    public function delete() {
        parent::delete();
        $this->database->deleteReserved($this->job->id);
    }

    /**
     * 重新发布任务
     *
     * @param  int $delay
     * @return void
     */
    public function release($delay = 0) {
        parent::release($delay);

        $this->delete();

        $this->database->release($this->queue, $this->job, $delay);
    }

    /**
     * 获取当前任务尝试次数
     *
     * @return int
     */
    public function attempts() {
        return (int) $this->job->attempts;
    }

    /**
     * Get the raw body string for the job.
     *
     * @return string
     */
    public function getRawBody() {
        return $this->job->payload;
    }
}
