<?php
use Workerman\Worker;
require_once __DIR__ . '/vendor/autoload.php';

// 不传参数默认是监听0.0.0.0:2206
$channel_server = new Channel\Server();

if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}