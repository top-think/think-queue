<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

return [
    'connector' => 'Sync',
    
    'Sync' => [],
    
    'database' => [
        'expire'  => 60,
        'default' => 'default',
        'table'   => 'jobs',
        'dsn'     => []
    ],
    
    'redis' => [
        'expire'     => 60,
        'default'    => 'default',
        'host'       => '127.0.0.1',
        'port'       => 6379,
        'password'   => '',
        'select'     => 0,
        'timeout'    => 0,
        'persistent' => false
    ],

    'topthink' => [
        'token'       => '',
        'project_id'  => '',
        'protocol'    => 'https',
        'host'        => 'qns.topthink.com',
        'port'        => 443,
        'api_version' => 1,
        'max_retries' => 3,
        'default'     => 'default'
    ],
];
