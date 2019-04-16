<?php

namespace think\test\queue;

use Mockery as m;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Config;
use think\Queue;
use think\queue\connector\Sync;

class QueueTest extends TestCase
{
    public function tearDown()
    {
        m::close();
    }

    public function testDefaultConnectionCanBeResolved()
    {
        $app = m::mock(App::class);

        $sync = new Sync();

        $app->shouldReceive('make')->once()->with('\think\queue\connector\Sync')->andReturn($sync);

        $config = m::mock(Config::class);

        $config->shouldReceive('get')->once()->with('queue.connector', 'Sync')->andReturn('sync');

        $app->shouldReceive('get')->once()->with('config')->andReturn($config);

        $queue = new Queue($app);

        $this->assertSame($sync, $queue->driver('sync'));
        $this->assertSame($sync, $queue->driver());
    }
}
