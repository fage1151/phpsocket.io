<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use PhpSocketIO\SocketIOServer;
use Psr\Log\LogLevel;


$io = new SocketIOServer('0.0.0.0:8088', [
        'maxPayload' => 10485760,
        'logLevel' => LogLevel::DEBUG
]);

// ========== Middleware 示例 ==========

// 1. 日志记录 middleware
$io->use(function (array $socket, array $packet, callable $next): void {
    $sid = $socket['id'] ?? 'unknown';
    $type = $packet['type'] ?? 'unknown';
    $namespace = $socket['namespace'] ?? '/';

    echo "[Middleware] 收到请求: SID={$sid}, Type={$type}, Namespace={$namespace}\n";

    // 继续处理
    $next();
});

// 2. 身份验证 middleware
$io->use(function (array $socket, array $packet, callable $next): void {
    $sid = $socket['id'] ?? 'unknown';
    
    // 如果是连接请求，可以验证 auth
    if ($packet['type'] === 'CONNECT') {
        $auth = $packet['auth'] ?? null;
        
        if ($auth) {
            echo "[Middleware] 验证 auth: " . json_encode($auth) . "\n";
        }
    }
    
    // 继续处理
    $next();
});

// 3. 消息过滤 middleware
$io->use(function (array $socket, array $packet, callable $next): void {
    $sid = $socket['id'] ?? 'unknown';
    
    // 检查是否是事件请求
    if (in_array($packet['type'], ['EVENT', 'BINARY_EVENT'])) {
        $eventName = $packet['event'] ?? '';
        echo "[Middleware] 事件: {$eventName} (SID: {$sid})\n";
        
        // 这里可以添加事件过滤逻辑
    }
    
    // 继续处理
    $next();
});


$io->of('/chat')->on('connection', function (mixed $socket) use ($io): void {
    // ========== Socket 实例级别的中间件 ==========
    
    // 1. Socket 级别日志记录
    $socket->use(function (array $packet, callable $next) use ($socket): void {
        $eventName = $packet['event'] ?? 'unknown';
        echo "[Socket Middleware] Socket {$socket->id} 收到事件: {$eventName}\n";
        $next();
    });
    
    // 2. Socket 级别事件过滤
    $socket->use(function (array $packet, callable $next) use ($socket): void {
        $eventName = $packet['event'] ?? '';
        
        // 只允许特定的事件通过
        $allowedEvents = ['chat message', 'message', 'ping', 'customEvent'];
        
        if (in_array($eventName, $allowedEvents)) {
            $next();
        } else {
            echo "[Socket Middleware] 阻止未授权的事件: {$eventName}\n";
            // 不调用 next()，阻止继续处理
            $next();
        }
    });
    
    // 3. Socket 级别数据验证
    $socket->use(function (array $packet, callable $next) use ($socket): void {
        $eventName = $packet['event'] ?? '';
        $data = $packet['data'] ?? [];
        
        // 对特定事件进行数据验证
        if ($eventName === 'chat message') {
            $message = $data[0] ?? '';
            if (is_string($message) && strlen($message) > 0 && strlen($message) <= 1000) {
                // 验证通过
                $next();
            } else {
                echo "[Socket Middleware] 消息验证失败: 消息长度必须在1-1000之间\n";
                // 发送错误反馈
                $socket->emit('error', '消息格式无效');
            }
        } else {
            // 其他事件直接通过
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
        var_dump("ack");
        if (is_callable($callback)) {
            $callback(['status' => 'ok', 'data' => $msg]);
        }
    });

    $socket->on('reqAck', function (mixed $msg, mixed $callback = null) use ($socket): void {
        $socket->emitWithAck('ackResponse', 'Server ACK response', function(mixed $userdata): void {
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
        var_dump('ping');
        $socket->emit('pong');
    });

    $socket->on('ackTimeout', function (mixed $timeout) use ($socket): void {
        if (is_numeric($timeout)) {
            usleep((int)$timeout * 1000);
        }
    });

    $socket->on('twoWay', function (mixed $data, mixed $callback = null) use ($socket): void {
        $socket->emit('twoWay', 'Server response', function(mixed $clientResponse) use ($socket, $callback): void {
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
        $binaryData = "Hello binary world!";
        $socket->emit('binaryResponse', '123');
        if (is_callable($callback)) {
            $callback(['status' => 'ok']);
        }
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
});

// ========== 使用 $io.of() 处理命名空间 ==========

$io->of('/test')->on('connection', function (mixed $socket) use ($io): void {
    $socket->emit('welcome', 'Welcome to /test namespace!');

    $socket->on('message', function (mixed $msg) use ($socket): void {
        $socket->emit('message', 'Test namespace: ' . $msg);
    });

    $socket->on('disconnect', function () use ($socket): void {
    });
});

$io->start();
Worker::runAll();
