<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Worker;
use PhpSocketIO\SocketIOServer;
use PhpSocketIO\Socket;
use Psr\Log\LogLevel;


$io = new SocketIOServer('0.0.0.0:8088', [
    'maxPayload' => 10485760,
    'logLevel' => LogLevel::DEBUG
]);

// ========== Middleware 示例 ==========
// Socket.IO v4 规范：全局/命名空间中间件签名为 (Socket $socket, callable $next)

// 1. 日志记录 middleware
$io->use(function (Socket $socket, callable $next): void {
    $sid = $socket->sid;
    $namespace = $socket->namespace;

    echo "[Middleware] 收到连接请求: SID={$sid}, Namespace={$namespace}\n";

    $next();
});

// 2. 身份验证 middleware
$io->use(function (Socket $socket, callable $next): void {
    $auth = $socket->handshake['auth'];

    if ($auth) {
        echo "[Middleware] 验证 auth: " . json_encode($auth) . "\n";
    }

    $next();
});

// 3. 拒绝连接示例（取消注释可测试）
// Socket.IO v4 规范：中间件可通过 next(new \Exception('msg')) 拒绝连接
// 支持 error.data 属性传递额外信息给客户端
// $io->use(function (Socket $socket, callable $next): void {
//     $auth = $socket->handshake['auth'];
//     if (!$auth || !isset($auth['token'])) {
//         $err = new \Exception('Authentication required');
//         $err->data = ['content' => 'Please provide a valid token']; // 客户端会收到 error.data
//         $next($err);
//         return;
//     }
//     $next();
// });


$io->of('/chat')->on('connection', function (mixed $socket) use ($io): void {
    $clientIp = $socket->handshake['address'];
    echo "新连接: ID={$socket->sid}, IP={$clientIp}\n";

    // ========== Socket 实例级别的中间件 ==========
    // Socket.IO v4 规范：Socket 级别中间件签名为 (array $packet, callable $next)
    // $packet 格式为 [eventName, ...data]，与 JS 版 [event, ...args] 一致

    // 1. Socket 级别日志记录
    $socket->use(function (array $packet, callable $next) use ($socket): void {
        $eventName = $packet[0] ?? 'unknown';
        $clientIp = $socket->handshake['address'];
        echo "[Socket Middleware] Socket {$socket->sid} (IP: {$clientIp}) 收到事件: {$eventName}\n";
        $next();
    });

    // 2. Socket 级别事件过滤
    $socket->use(function (array $packet, callable $next) use ($socket): void {
        $eventName = $packet[0] ?? '';

        $allowedEvents = ['chat message', 'message', 'ping', 'customEvent'];

        if (in_array($eventName, $allowedEvents) || true) {
            $next();
        } else {
            echo "[Socket Middleware] 阻止未授权的事件: {$eventName}\n";
        }
    });

    // 3. Socket 级别数据验证
    $socket->use(function (array $packet, callable $next) use ($socket): void {
        $eventName = $packet[0] ?? '';
        $data = array_slice($packet, 1);

        if ($eventName === 'chat message') {
            $message = $data[0] ?? '';
            if (is_string($message) && strlen($message) > 0 && strlen($message) <= 1000) {
                $next();
            } else {
                echo "[Socket Middleware] 消息验证失败: 消息长度必须在1-1000之间\n";
                $socket->emit('error', '消息格式无效');
            }
        } else {
            $next();
        }
    });

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

    $socket->on('ack', function (mixed $msg, mixed $callback = null) use ($socket) {
        if (is_callable($callback)) {
            $callback(['status' => 'ok', 'data' => $msg]);
        }
    });

    $socket->on('reqAck', function (mixed $msg, mixed $callback = null) use ($socket): void {
        $socket->emitWithAck('ackResponse', 'Server ACK response', function (mixed $userdata): void {
        });
        if (is_callable($callback)) {
            $callback(['status' => 'ok']);
        }
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

    $socket->on('twoWay', function (mixed $data, mixed $callback = null) use ($socket): void {
        $socket->emitWithAck('twoWay', 'Server response', function (mixed $clientResponse) use ($socket, $callback): void {
            $socket->emit('twoWayBack', 'Final response: ' . $clientResponse);
            if (is_callable($callback)) {
                $callback(['status' => 'ok', 'twoWay' => 'success']);
            }
        });
    });

    $socket->on('binaryEvent', function (mixed $data) use ($socket): void {
        $socket->emitBinary('binaryEvent', pack("N", 100000));
    });

    $socket->on('reqBinary', function (mixed $data, mixed $callback = null) use ($socket): void {
        $socket->emit('binaryResponse', '123');
        if (is_callable($callback)) {
            $callback(['status' => 'ok']);
        }
    });

    $socket->on('typedArray', function (mixed $data) use ($socket): void {
        $socket->emit('binaryResponse', ['status' => 'ok'], $data);
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

    $socket->on('disconnect', function (string $reason) use ($socket): void {
        echo "Socket {$socket->sid} disconnected: {$reason}\n";
    });
});

// ========== 使用 $io.of() 处理命名空间 ==========

$io->of('/test')->on('connection', function (mixed $socket) use ($io): void {
    $socket->emit('welcome', 'Welcome to /test namespace!');

    $socket->on('message', function (mixed $msg) use ($socket): void {
        $socket->emit('message', 'Test namespace: ' . $msg);
    });

    $socket->on('disconnect', function (string $reason) use ($socket): void {
        echo "Socket {$socket->sid} disconnected from /test: {$reason}\n";
    });
});

Worker::runAll();
