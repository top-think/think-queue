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

namespace think\queue\command;

use think\console\command\Command;
use think\console\Input;
use think\console\Output;
use think\queue\Queue;

class Subscribe extends Command
{
    public function configure()
    {
        $this->setName('queue:subscribe')->setDescription('Subscribe a URL to an push queue');
    }

    public function execute(Input $input, Output $output)
    {
       // $queue = Queue::handle();
    }
}