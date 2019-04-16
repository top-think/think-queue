<?php

namespace think\queue\event;

use think\queue\Job;

class JobProcessing
{
    /** @var string */
    public $connector;

    /** @var Job */
    public $job;

    public function __construct($connector, $job)
    {
        $this->connector = $connector;
        $this->job       = $job;
    }
}
