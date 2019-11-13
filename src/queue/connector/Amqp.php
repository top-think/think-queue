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

namespace think\queue\connector;

use Exception;
use think\helper\Str;
use think\queue\Connector;
use think\queue\job\Amqp as AmqpJob;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use think\queue\command\Amqp as AmqpCommand;
use think\queue\Amqp as AmqpQueue;


class Amqp extends Connector
{
    protected $connection;
    protected $channel;


    protected $options = [
        'expire'     => 60,
        'host'       => '127.0.0.1',
        'port'       => 5672,
        'username'   => 'guest',
        'password'   => 'guest',
        'timeout'    => 0
    ];

    public function __construct(array $options)
    {
        if (!extension_loaded('sockets')) {
            throw new Exception('sockets扩展未安装');
        }
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }

        $this->connection = new AMQPStreamConnection($this->options['host'], $this->options['port'], $this->options['username'], $this->options['password']);
        $this->channel = $this->connection->channel();
    }

    public function __destruct() 
    {
        $this->channel->close();
        $this->connection->close();
    }

    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue);
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $payload = $this->createPayload($job, $data);

        $queue_name = $this->getQueue($queue);

        if (empty($delay)) {
            $delay = 5 * 1000;
        }else{
            $delay = $delay * 1000;
        }

        $topic_name = $queue_name.'_topic';
        $queue_name_delay = $queue_name.$delay.'_delay';
        $topic_name_delay = $queue_name.$delay.'_topic_delay';
        

        $this->channel->exchange_declare($topic_name, 'topic', false, true, false);
        $this->channel->queue_declare($queue_name, false, true, false, false);
        $this->channel->queue_bind($queue_name, $topic_name);

        $option = new AMQPTable();
        $option->set('x-message-ttl', $delay);
        $option->set('x-dead-letter-exchange', $topic_name);
        //$option->set('x-dead-letter-routing-key','routing_key');

        $this->channel->exchange_declare($topic_name_delay, 'topic', false, true, false);
        $this->channel->queue_declare($queue_name_delay, false, true, false, false, false, $option);
        $this->channel->queue_bind($queue_name_delay, $topic_name_delay);

        $msg = new AMQPMessage($payload);
        $this->channel->basic_publish($msg, $topic_name_delay);
    }

    //拉取模式,默认模式
    public function pop($queue = null)
    {
        $queue_name = $this->getQueue($queue);
        $topic_name = $queue_name.'_topic';
        $this->channel->exchange_declare($topic_name, 'topic', false, true, false);
        $this->channel->queue_declare($queue_name, false, true, false, false);
        $this->channel->queue_bind($queue_name, $topic_name);

        //拉取模式
        $msg = $this->channel->basic_get($queue_name,false);
        if (!empty($msg)) {
            $job = $msg->body;
            $this->channel->basic_ack($msg->delivery_info['delivery_tag']);

            return new AmqpJob($this, $job, $queue);
        }

    }

    //消费者模式，amqp模式下
    //echo date('Y-m-d H:i:s')." [x] Received",$msg->body,PHP_EOL;
    public function consume(AmqpQueue $work, AmqpCommand $amqcmd, $queue = null, $delay = 0, $maxTries = 0)
    {    echo date('Y-m-d H:i:s')." [x] Received-","consume",PHP_EOL;
         $queue_name = $this->getQueue($queue);
         $topic_name = $queue_name.'_topic';
         $this->channel->exchange_declare($topic_name, 'topic', false, true, false);
         $this->channel->queue_declare($queue_name, false, true, false, false);
         $this->channel->queue_bind($queue_name, $topic_name);

         $_this = $this;
         //消费者模式
         $callback = function($msg) use ($_this, $work, $amqcmd, $queue, $delay, $maxTries){
             //echo date('Y-m-d H:i:s')." [x] Received",$msg->body,PHP_EOL;

             $amqpJob = new AmqpJob($_this, $msg->body, $queue);
             $result = $work->process($amqpJob, $maxTries, $delay);
             $amqcmd->checkDaemon($queue, $result);

             $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
         };

         //只有consumer已经处理并确认了上一条message时queue才分派新的message给它
         $this->channel->basic_qos(0, 1, false);
         $this->channel->basic_consume($queue_name,'',false,false,false,false, $callback);
         while (count($this->channel->callbacks)) {
             $this->channel->wait();
         }
    }

    /**
     * 重新发布任务
     *
     * @param  string $queue
     * @param  string $payload
     * @param  int    $delay
     * @param  int    $attempts
     * @return void
     */
    public function release($queue, $payload, $delay, $attempts)
    {
        $payload = $this->setMeta($payload, 'attempts', $attempts);

        $queue_name = $this->getQueue($queue);

        if (empty($delay)) {
            $delay = 5 * 1000;
        }else{
            $delay = $delay * 1000;
        }

        $queue_name_delay = $queue_name.$delay.'_delay';
        $topic_name_delay = $queue_name.$delay.'_topic_delay';

        

        $option = new AMQPTable();
        $option->set('x-message-ttl', $delay);
        $option->set('x-dead-letter-exchange', $queue_name.'_topic');
        //$option->set('x-dead-letter-routing-key','routing_key');

        $this->channel->exchange_declare($topic_name_delay, 'topic', false, true, false);
        $this->channel->queue_declare($queue_name_delay, false, true, false, false, false, $option);
        $this->channel->queue_bind($queue_name_delay, $topic_name_delay);

        $msg = new AMQPMessage($payload);
        $this->channel->basic_publish($msg, $topic_name_delay);

    }

    public function pushRaw($payload, $queue = null)
    {
        $queue_name = $this->getQueue($queue);
        $topic_name = $queue_name.'_topic';

        $this->channel->exchange_declare($topic_name, 'topic', false, true, false);
        $this->channel->queue_declare($queue_name, false, true, false, false);
        $this->channel->queue_bind($queue_name, $topic_name);
        
        $msg = new AMQPMessage($payload);
        $this->channel->basic_publish($msg, $topic_name);

        //测试延迟队列
        //$this->release($queue, $payload, 0, 2);

        return json_decode($payload, true)['id'];
    }

    protected function createPayload($job, $data = '', $queue = null)
    {
        $payload = $this->setMeta(
            parent::createPayload($job, $data), 'id', $this->getRandomId()
        );

        return $this->setMeta($payload, 'attempts', 1);
    }

    /**
     * 删除任务
     *
     * @param  string $queue
     * @param  string $job
     * @return void
     */
    public function deleteReserved($queue, $job)
    {
        
    }


    /**
     * 随机id
     *
     * @return string
     */
    protected function getRandomId()
    {
        return Str::random(32);
    }

    /**
     * 获取队列名
     *
     * @param  string|null $queue
     * @return string
     */
    protected function getQueue($queue)
    {
        return 'queues_' . ($queue ?: $this->options['default']);
    }
}
