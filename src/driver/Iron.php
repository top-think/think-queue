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

namespace think\queue\driver;


use Exception;
use IronMQ\IronMQ;
use think\queue\job\Iron as IronJob;
use think\Request;
use think\Response;

class Iron
{
    /** @var  IronMQ */
    protected $iron;

    protected $options = [
        'token'          => '',
        'project_id'     => '',
        'protocol'       => 'https',
        'host'           => 'mq-aws-us-east-1-1.iron.io',
        'port'           => '443',
        'api_version'    => '3',
        'encryption_key' => '',
        'default'        => 'default'
    ];

    /** @var  Request */
    protected $request;

    public function __construct($options)
    {
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }

        $this->iron    = new IronMQ($this->options);
        $this->request = Request::instance();

    }

    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data, $queue), $queue);
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $payload = $this->createPayload($job, $data, $queue);

        return $this->pushRaw($payload, $queue, compact('delay'));
    }

    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        $job = $this->iron->reserveMessage($queue);

        if (!is_null($job)) {
            return new IronJob($this, $job);
        }
    }

    public function recreate($payload, $queue, $delay)
    {
        return $this->pushRaw($payload, $queue, compact('delay'));
    }


    public function pushRaw($payload, $queue = null, array $options = [])
    {
        return $this->iron->postMessage($this->getQueue($queue), $payload, $options)->id;
    }

    public function getQueue($queue)
    {
        return $queue ?: $this->options['default'];
    }

    protected function createPayload($job, $data = '', $queue = null)
    {
        $payload = json_encode(['job' => $job, 'data' => $data]);

        $payload = $this->setMeta($payload, 'attempts', 1);

        return $this->setMeta($payload, 'queue', $this->getQueue($queue));
    }

    public function deleteMessage($queue, $id, $reservation_id)
    {
        $this->iron->deleteMessage($queue, $id, $reservation_id);
    }

    public function marshal()
    {
        $this->createPushedIronJob($this->marshalPushedJob())->fire();

        return new Response('OK');
    }

    public function subscribe($name, $url, $queue)
    {
        $this->iron->addSubscriber($queue, ['name' => $name, 'url' => $url]);
    }

    /**
     * Marshal out the pushed job and payload.
     *
     * @return object
     */
    protected function marshalPushedJob()
    {
        return (object)[
            'id'             => $this->request->header('iron-message-id'),
            'body'           => $this->request->getContent(),
            'reservation_id' => $this->request->header('iron-reservation-id')
        ];
    }

    /**
     * Create a new IronJob for a pushed job.
     *
     * @param  object $job
     * @return IronJob
     */
    protected function createPushedIronJob($job)
    {
        return new IronJob($this, $job, true);
    }

    protected function setMeta($payload, $key, $value)
    {
        $payload       = json_decode($payload, true);
        $payload[$key] = $value;
        return json_encode($payload);
    }
}