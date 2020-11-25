<?php

namespace shirakun\queue;

use think\helper\Arr;
use think\helper\Str;
use shirakun\queue;
use shirakun\queue\command\FailedTable;
use shirakun\queue\command\FlushFailed;
use shirakun\queue\command\ForgetFailed;
use shirakun\queue\command\Listen;
use shirakun\queue\command\ListFailed;
use shirakun\queue\command\Restart;
use shirakun\queue\command\Retry;
use shirakun\queue\command\Table;
use shirakun\queue\command\Work;

class Service extends \think\Service
{
    public function register()
    {
        $this->app->bind('queue', Queue::class);
        $this->app->bind('queue.failer', function () {

            $config = $this->app->config->get('queue.failed', []);

            $type = Arr::pull($config, 'type', 'none');

            $class = false !== strpos($type, '\\') ? $type : '\\shirakun\\queue\\failed\\' . Str::studly($type);

            return $this->app->invokeClass($class, [$config]);
        });
    }

    public function boot()
    {
        $this->commands([
            FailedJob::class,
            Table::class,
            FlushFailed::class,
            ForgetFailed::class,
            ListFailed::class,
            Retry::class,
            Work::class,
            Restart::class,
            Listen::class,
            FailedTable::class,
        ]);
    }
}
