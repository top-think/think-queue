# think-queue-lts for ThinkPHP8

## 安装

> composer require tobycroft/think-queue-lts

## 配置

> 配置文件位于 `config/queue.php`

### 公共配置

```bash
[
    'default'=>'sync' //驱动类型，可选择 sync(默认):同步执行，database:数据库驱动,redis:Redis驱动//或其他自定义的完整的类名
]
```

### 环境变量

配置文件中的每一项都支持通过 `.env`（或系统环境变量）覆盖，安装后无需修改配置文件即可完成部署。

Redis 连接信息（`REDIS_HOST`、`REDIS_PORT`、`REDIS_PASSWORD` 等）与缓存（`cache.php`）共用同一组环境变量，一处配置两边生效；队列特有参数使用 `QUEUE_*` 前缀：

```bash
[QUEUE]
DRIVER = redis

[QUEUE_REDIS]
MODE = stream
RETRY_AFTER = 60
BLOCK_FOR = 5
GROUP = think-queue

[REDIS]
HOST = 127.0.0.1
PORT = 6379
PASSWORD =
SELECT = 0
TIMEOUT = 0
PERSISTENT = false
```

完整对应关系：

| 配置项 | 环境变量 | 默认值 |
|--------|----------|--------|
| default | QUEUE_DRIVER | sync |
| queue（队列名） | QUEUE_NAME | default |
| database.table | QUEUE_TABLE | jobs |
| database.connection | QUEUE_CONNECTION | null |
| redis.mode | QUEUE_REDIS_MODE | list |
| redis.host | REDIS_HOST | 127.0.0.1 |
| redis.port | REDIS_PORT | 6379 |
| redis.password | REDIS_PASSWORD | (空) |
| redis.select | REDIS_SELECT | 0 |
| redis.timeout | REDIS_TIMEOUT | 0 |
| redis.persistent | REDIS_PERSISTENT | false |
| redis.retry_after | QUEUE_REDIS_RETRY_AFTER | 60 |
| redis.block_for | QUEUE_REDIS_BLOCK_FOR | null |
| redis.lock | QUEUE_REDIS_LOCK | true |
| redis.lock_ttl | QUEUE_REDIS_LOCK_TTL | 3600 |
| redis.group | QUEUE_REDIS_GROUP | think-queue |
| redis.consumer | QUEUE_REDIS_CONSUMER | (空，自动生成) |
| failed.type | QUEUE_FAILED_DRIVER | none |
| failed.table | QUEUE_FAILED_TABLE | failed_jobs |

> 未设置的环境变量使用配置文件中的默认值。

### Redis 连接（mode 模式）

```bash
'redis' => [
    'type'       => 'redis',
    'mode'       => 'list',  //队列实现方式：list(默认):Redis列表实现，stream:Redis Stream消费者组实现(需要Redis 5.0+)
    'queue'      => 'default',
    'host'       => '127.0.0.1',
    'port'       => 6379,
    'password'   => '',
    'select'     => 0,
    'timeout'    => 0,
    'persistent' => false,
    'retry_after' => 60,     //任务执行超过该秒数未被确认，将被重新投递
    'block_for'  => null,    //pop时最长阻塞秒数，为null表示不阻塞
    'lock'       => true,    //任务幂等锁：防止执行时长超过retry_after被重复投递的任务被多个worker同时执行
    'lock_ttl'   => 3600,    //幂等锁TTL(秒)，应大于最长任务执行时长；worker崩溃后任务等待该时长才能被重新认领

    //以下为 mode=stream 专用配置
    'group'      => 'think-queue',  //消费者组名称
    'consumer'   => '',             //消费者名称，为空时自动按 主机名-进程号 生成
],
```

`stream` 模式说明：

- 消息通过 Redis Stream + 消费者组投递，支持消息的待处理（PEL）语义，消费失败或超时（`retry_after`）后消息会被重新投递；重试次数由消费者组的投递次数 + release 时固化的尝试基数记录，保证 maxTries 语义正确
- 延迟任务复用有序集合（`{queues:xxx}:delayed`），到期后自动迁移到 Stream
- 切换 mode 不影响已有延迟任务的消费

### 任务幂等锁（lock）

默认开启。任务执行时长超过 `retry_after` 时，消息会被重新投递给其他 worker，导致同一任务被多个进程同时执行。开启幂等锁后，pop 到已被其他 worker 处理中的同一任务（按 payload 中的任务 id 判定）会直接跳过本次投递，避免重复执行。

使用约束：

- `lock_ttl` 应大于最长任务执行时长，否则锁过期后仍可能重复执行
- worker 崩溃时，其锁定的任务需等待 `lock_ttl` 过期后才能被重新认领
- list 模式下被跳过的重复投递会被直接丢弃（任务至少执行一次）；stream 模式下消息保留在 PEL 中，持有者完成后正常删除
- 可通过 `QUEUE_REDIS_LOCK=false` 关闭（关闭后请务必保证 `retry_after` > 最长任务时长）


## 创建任务类
> 推荐使用 `app\job` 作为任务类的命名空间
> 也可以放在任意可以自动加载到的地方

任务类不需继承任何类，如果这个类只有一个任务，那么就只需要提供一个`fire`方法就可以了，如果有多个小任务，就写多个方法，下面发布任务的时候会有区别  
每个方法会传入两个参数 `think\queue\Job $job`（当前的任务对象） 和 `$data`（发布任务时自定义的数据）

还有个可选的任务失败执行的方法 `failed` 传入的参数为`$data`（发布任务时自定义的数据）

### 下面写两个例子

```php
namespace app\job;

use think\queue\Job;

class Job1{
    
    public function fire(Job $job, $data){
    
            //....这里执行具体的任务 
            
             if ($job->attempts() > 3) {
                  //通过这个方法可以检查这个任务已经重试了几次了
             }
            
            
            //如果任务执行成功后 记得删除任务，不然这个任务会重复执行，直到达到最大重试次数后失败后，执行failed方法
            $job->delete();
            
            // 也可以重新发布这个任务
            $job->release($delay); //$delay为延迟时间
          
    }
    
    public function failed($data){
    
        // ...任务达到最大重试次数后，失败了
    }

}

```

```php

namespace app\lib\job;

use think\queue\Job;

class Job2{
    
    public function task1(Job $job, $data){
    
          
    }
    
    public function task2(Job $job, $data){
    
          
    }
    
    public function failed($data){
    
          
    }

}

```


## 发布任务
> `think\facade\Queue::push($job, $data = '', $queue = null)` 和 `think\facade\Queue::later($delay, $job, $data = '', $queue = null)` 两个方法，前者是立即执行，后者是在`$delay`秒后执行

`$job` 是任务名  
命名空间是`app\job`的，比如上面的例子一,写`Job1`类名即可  
其他的需要些完整的类名，比如上面的例子二，需要写完整的类名`app\lib\job\Job2`  
如果一个任务类里有多个小任务的话，如上面的例子二，需要用@+方法名`app\lib\job\Job2@task1`、`app\lib\job\Job2@task2`

`$data` 是你要传到任务里的参数

`$queue` 队列名，指定这个任务是在哪个队列上执行，同下面监控队列的时候指定的队列名,可不填

## 监听任务并执行

```bash
&> php think queue:listen

&> php think queue:work
```

两种，具体的可选参数可以输入命令加 `--help` 查看

> 可配合supervisor使用，保证进程常驻