<?php

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use PhpSocketIO\SocketIOServer;
use Psr\Log\LogLevel;
use PhpSocketIO\Adapter\ClusterAdapter;

try {
    $io = new SocketIOServer('0.0.0.0:8088', [
        'pingInterval' => 25000,
        'pingTimeout' => 20000,
        'maxPayload' => 10485760,
        'logLevel' => LogLevel::DEBUG
    ]);
} catch (Exception $e) {
    echo "Error creating server: " . $e->getMessage() . "\n";
    exit(1);
}

// 启动服务器（必须在设置 adapter 之后调用）

$io->on('connection', function ($socket) use ($io) {
    $socket->emit('welcome', 'Welcome to Socket.IO server!');

    $socket->on('chat message', function ($msg) use ($socket) {
        $socket->broadcast->emit('chat message', $msg);
    });

    $socket->on('message', function ($msg) use ($socket) {
        $socket->emit('message', $msg);
    });

    $socket->on('customEvent', function ($msg = null) use ($io) {
        $io->of('/chat')->emit('customEvent', $msg);
    });

    $socket->on('ack', function ($msg, $callback) use ($socket) {
        if (is_callable($callback)) {
            $callback(['status' => 'ok', 'data' => $msg]);
        }
        return ['status' => 'ok', 'data' => $msg];
    });

    $socket->on('reqAck', function ($msg) use ($socket) {
        $socket->emitWithAck('ackResponse', 'Server ACK response', function($userdata) {
        });
        return ['status' => 'ok'];
    });

    $socket->on('buffer', function ($msg) use ($socket) {
        $socket->emitBinary('binaryResponse', $msg, ['status' => 'ok']);
    });

    $socket->on('file', function ($data) use ($socket) {
        if (isset($data['name']) && isset($data['data'])) {
            $fileName = $data['name'];
            $fileSize = strlen($data['data']);
            $socket->emit('message', "File received: {$fileName} ({$fileSize} bytes)");
        }
    });

    $socket->on('blob', function ($data) use ($socket) {
        if (is_string($data)) {
            $dataSize = strlen($data);
            $socket->emit('blob', ['status' => 'received', 'size' => $dataSize]);
        }
    });

    $socket->on('ping', function () use ($socket) {
        $socket->emit('pong');
    });

    $socket->on('ackTimeout', function ($timeout) use ($socket) {
        usleep($timeout * 1000);
    });

    $socket->on('twoWay', function ($data) use ($socket) {
        $socket->emit('twoWay', 'Server response', function($clientResponse) use ($socket) {
            $socket->emit('twoWayBack', 'Final response: ' . $clientResponse);
        });
        return ['status' => 'ok', 'twoWay' => 'success'];
    });

    $socket->on('binaryEvent', function ($data) use ($socket) {
        $socket->emitBinary('binaryEvent', pack("N","100000"));
    });

    $socket->on('reqBinary', function ($data) use ($socket) {
        $binaryData = "Hello binary world!";
        $socket->emit('binaryResponse', '123');
        return ['status' => 'ok'];
    });

    $socket->on('typedArray', function ($data) use ($socket) {
        $socket->emit('binaryResponse', ['status' => 'ok', 'data' => $data]);
    });

    $socket->on('ack1', function ($data) use ($socket) {
        $socket->emit('ack1', 'Response for ack1');
    });

    $socket->on('ack2', function ($data) use ($socket) {
        $socket->emit('ack2', 'Response for ack2');
    });

    $socket->on('ack3', function ($data) use ($socket) {
        $socket->emit('ack3', 'Response for ack3');
    });

    $socket->on('disconnect', function () use ($socket) {
    });
}, '/chat');

$io->on('connection', function ($socket) use ($io) {
    $socket->emit('welcome', 'Welcome to /test namespace!');

    $socket->on('message', function ($msg) use ($socket) {
        $socket->emit('message', 'Test namespace: ' . $msg);
    });

    $socket->on('disconnect', function () use ($socket) {
    });
}, '/test');

$io->start();
Worker::runAll();
