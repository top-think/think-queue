<?php

namespace think\test\queue;

use InvalidArgumentException;
use Mockery as m;
use think\Config;
use think\Queue;
use think\queue\connector\Sync;

class QueueTest extends TestCase
{
    /** @var Queue */
    protected $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new Queue($this->app);
    }

    public function testDefaultConnectionCanBeResolved()
    {
        $sync = new Sync();

        $this->app->shouldReceive('invokeClass')->once()->with('\think\queue\connector\Sync', [['driver' => 'sync']])->andReturn($sync);

        $config = m::mock(Config::class);

        $config->shouldReceive('get')->with('queue.connections.sync', [])->andReturn(['type' => 'sync', 'driver' => 'sync']);
        $config->shouldReceive('get')->with('queue.connections.sync')->andReturn(['driver' => 'sync']);
        $config->shouldReceive('get')->with('queue.default')->andReturn('sync');

        $this->app->shouldReceive('get')->times(3)->with('config')->andReturn($config);

        $this->assertSame($sync, $this->queue->connection('sync'));
        $this->assertSame($sync, $this->queue->connection());
    }

    public function testNotSupportDriver()
    {
        $config = m::mock(Config::class);

        $config->shouldReceive('get')->with('queue.connections.hello', [])->andReturn(['type' => 'hello']);
        $config->shouldReceive('get')->with('queue.connections.hello')->andReturn(['driver' => 'hello']);
        $this->app->shouldReceive('get')->times(2)->with('config')->andReturn($config);

        $this->expectException(InvalidArgumentException::class);
        $this->queue->connection('hello');
    }
}