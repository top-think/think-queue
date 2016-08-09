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

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\queue\Job;
use think\queue\Worker;

class Work extends Command
{


    /**
     * The queue worker instance.
     * @var \think\queue\Worker
     */
    protected $worker;


    protected function initialize(Input $input, Output $output)
    {
        $this->worker = new Worker();
    }

    protected function configure()
    {
        $this->setName('queue:work')
            ->addOption('queue', null, Option::VALUE_OPTIONAL, 'The queue to listen on')
            ->addOption('daemon', null, Option::VALUE_NONE, 'Run the worker in daemon mode')
            ->addOption('delay', null, Option::VALUE_OPTIONAL, 'Amount of time to delay failed jobs', 0)
            ->addOption('force', null, Option::VALUE_NONE, 'Force the worker to run even in maintenance mode')
            ->addOption('memory', null, Option::VALUE_OPTIONAL, 'The memory limit in megabytes', 128)
            ->addOption('sleep', null, Option::VALUE_OPTIONAL, 'Number of seconds to sleep when no job is available', 3)
            ->addOption('tries', null, Option::VALUE_OPTIONAL, 'Number of times to attempt a job before logging it failed', 0)
            ->setDescription('Process the next job on a queue');
    }

    /**
     * Execute the console command.
     * @param Input  $input
     * @param Output $output
     * @return int|null|void
     */
    public function execute(Input $input, Output $output)
    {
        $queue = $input->getOption('queue');

        $delay = $input->getOption('delay');

        $memory = $input->getOption('memory');

        if ($input->getOption('daemon')) {
            $response = $this->worker->daemon(
                $queue, $delay, $memory,
                $input->getOption('sleep'), $input->getOption('tries')
            );
        } else {
            $response = $this->worker->pop($queue, $delay, $input->getOption('sleep'), $input->getOption('tries'));
        }
        if (!is_null($response['job'])) {
            /** @var Job $job */
            $job = $response['job'];
            if ($response['failed']) {
                $output->writeln('<error>Failed:</error> ' . $job->getName());
            } else {
                $output->writeln('<info>Processed:</info> ' . $job->getName());
            }
        }
    }

}