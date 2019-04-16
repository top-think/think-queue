<?php

namespace think\queue\event;

use think\queue\Job;

class JobFailed
{
    /** @var string */
    public $connector;

    /** @var Job */
    public $job;

    /** @var \Exception */
    public $exception;

    public function __construct($connector, $job, $exception)
    {
        $this->connector = $connector;
        $this->job       = $job;
        $this->exception = $exception;
    }
}
