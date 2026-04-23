<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use PhpSocketIO\SocketIOServer;
use Psr\Log\LogLevel;


$io = new SocketIOServer('0.0.0.0:8088', [
        'pingInterval' => 25000,
        'pingTimeout' => 20000,
        'maxPayload' => 10485760,
        'logLevel' => LogLevel::DEBUG
]);


$io->on('connection', function (mixed $socket) use ($io): void {
    $socket->emit('welcome', 'Welcome to Socket.IO server!');

    $socket->on('chat message', function (mixed $msg) use ($socket): void {
        $socket->broadcast->emit('chat message', $msg);
    });

    $socket->on('message', function (mixed $msg) use ($socket): void {
        $socket->emit('message', $msg);
    });

    $socket->on('customEvent', function (mixed $msg = null) use ($io): void {
        $io->of('/chat')->emit('customEvent', $msg);
    });

    $socket->on('ack', function (mixed $msg, mixed $callback = null) use ($socket): array {
        if (is_callable($callback)) {
            $callback(['status' => 'ok', 'data' => $msg]);
        }
        return ['status' => 'ok', 'data' => $msg];
    });

    $socket->on('reqAck', function (mixed $msg) use ($socket): array {
        $socket->emitWithAck('ackResponse', 'Server ACK response', function(mixed $userdata): void {
        });
        return ['status' => 'ok'];
    });

    $socket->on('buffer', function (mixed $msg) use ($socket): void {
        $socket->emitBinary('binaryResponse', $msg, ['status' => 'ok']);
    });

    $socket->on('file', function (mixed $data) use ($socket): void {
        if (isset($data['name']) && isset($data['data'])) {
            $fileName = $data['name'];
            $fileSize = strlen((string)$data['data']);
            $socket->emit('message', "File received: {$fileName} ({$fileSize} bytes)");
        }
    });

    $socket->on('blob', function (mixed $data) use ($socket): void {
        if (is_string($data)) {
            $dataSize = strlen($data);
            $socket->emit('blob', ['status' => 'received', 'size' => $dataSize]);
        }
    });

    $socket->on('ping', function () use ($socket): void {
        $socket->emit('pong');
    });

    $socket->on('ackTimeout', function (mixed $timeout) use ($socket): void {
        if (is_numeric($timeout)) {
            usleep((int)$timeout * 1000);
        }
    });

    $socket->on('twoWay', function (mixed $data) use ($socket): array {
        $socket->emit('twoWay', 'Server response', function(mixed $clientResponse) use ($socket): void {
            $socket->emit('twoWayBack', 'Final response: ' . $clientResponse);
        });
        return ['status' => 'ok', 'twoWay' => 'success'];
    });

    $socket->on('binaryEvent', function (mixed $data) use ($socket): void {
        $socket->emitBinary('binaryEvent', pack("N", 100000));
    });

    $socket->on('reqBinary', function (mixed $data) use ($socket): array {
        $binaryData = "Hello binary world!";
        $socket->emit('binaryResponse', '123');
        return ['status' => 'ok'];
    });

    $socket->on('typedArray', function (mixed $data) use ($socket): void {
        $socket->emit('binaryResponse', ['status' => 'ok', 'data' => $data]);
    });

    $socket->on('ack1', function (mixed $data) use ($socket): void {
        $socket->emit('ack1', 'Response for ack1');
    });

    $socket->on('ack2', function (mixed $data) use ($socket): void {
        $socket->emit('ack2', 'Response for ack2');
    });

    $socket->on('ack3', function (mixed $data) use ($socket): void {
        $socket->emit('ack3', 'Response for ack3');
    });

    $socket->on('disconnect', function () use ($socket): void {
    });
}, '/chat');

$io->on('connection', function (mixed $socket) use ($io): void {
    $socket->emit('welcome', 'Welcome to /test namespace!');

    $socket->on('message', function (mixed $msg) use ($socket): void {
        $socket->emit('message', 'Test namespace: ' . $msg);
    });

    $socket->on('disconnect', function () use ($socket): void {
    });
}, '/test');

$io->start();
Worker::runAll();
