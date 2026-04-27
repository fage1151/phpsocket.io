# PHPSocket.IO

A PHP server implementation of [Socket.IO](https://socket.io), supporting WebSocket and Long-Polling transports, compatible with Socket.IO protocol v4.

## Features

- **Multi-transport support**：Supports WebSocket and HTTP Long-Polling (only WebSocket is supported in multi-process mode and requires explicit usage)
- **Binary data support**：Supports binary event transmission
- **Cluster support**：Enables multi-process cluster communication via Channel or Redis
- **Room management**：Supports Room management functionality
- **Namespaces**：Supports multiple namespaces, recommend using $io->of() method
- **Event Acknowledgments (ACK)**：Supports bidirectional ACK acknowledgment mechanism, must use callback to respond
- **Three-layer middleware system**：Supports global, namespace-level and Socket instance-level middlewares
- **Heartbeat detection**：Automatic heartbeat keep-alive mechanism
- **PSR-3 Logging System**：Supports PSR-3 standard logging interface
- **PHP 8.1+ Optimized Implementation**：Uses PHP 8.1 latest features with performance optimization

## System Requirements

- PHP >= 8.1
- Workerman >= 4.0
- psr/log >= 3.0
- Optional dependencies:
  - workerman/channel (required when using ClusterAdapter)
  - workerman/redis (required when using RedisAdapter)

## Installation

### Clone from GitHub

```bash
# Clone the project
git clone https://github.com/phpsocketio/socket.io.git

# Enter the project directory
cd socket.io

# Install dependencies
composer install
```

### Install Optional Dependencies

#### When using ClusterAdapter：

ClusterAdapter is based on Workerman Channel, not included by default and requires installation:

```bash
composer require workerman/channel
```

#### When using RedisAdapter：

```bash
composer require workerman/redis
```

## Project Structure

```
├── src/                          # Core source directory
│   ├── Adapter/                  # Adapter directory
│   │   ├── AdapterInterface.php  # Adapter interface
│   │   ├── ClusterAdapter.php    # Channel-based cluster adapter
│   │   └── RedisAdapter.php      # Redis-based cluster adapter
│   ├── Broadcaster.php           # Unified broadcaster
│   ├── EngineIOHandler.php       # Engine.IO protocol handler
│   ├── EventHandler.php          # Event handler
│   ├── HttpRequestHandler.php    # HTTP request handler
│   ├── Logger.php                # PSR-3 compatible logger
│   ├── MiddlewarePipeline.php    # Middleware execution pipeline
│   ├── PacketParser.php          # Packet parser
│   ├── PollingHandler.php        # Polling handler
│   ├── RoomManager.php           # Room manager
│   ├── ServerManager.php         # Server manager
│   ├── Session.php               # Session management
│   ├── Socket.php                # Socket class
│   ├── SocketNamespace.php       # Namespace handler class
│   └── SocketIOServer.php        # Socket.IO server main class
├── examples/                     # Examples directory
│   └── simple-chat/              # Simple chat example
├── tests/                        # Tests directory
├── server.php                    # Server startup script
├── index.html                    # Client example
├── README.md                     # Project documentation (Chinese)
├── README.en.md                  # Project documentation (English)
├── USAGE.md                      # Detailed usage documentation (Chinese)
└── LICENSE                       # License file
```

## Core File Descriptions

- **src/SocketIOServer.php**：Socket.IO server main class, handles connections and event dispatch
- **src/SocketNamespace.php**：Namespace handler class, accessed via $io->of()
- **src/EventHandler.php**：Event handler, handles various Socket.IO events
- **src/HttpRequestHandler.php**：HTTP request handler, handles HTTP polling and WebSocket handshake
- **src/EngineIOHandler.php**：Engine.IO protocol handler, handles underlying transport protocol
- **src/Session.php**：Session management, manages client session state
- **src/Socket.php**：Socket class, encapsulates client connection interface
- **src/RoomManager.php**：Room manager, handles room-related operations
- **src/PacketParser.php**：Packet parser, parses Socket.IO packets
- **src/Broadcaster.php**：Unified broadcaster, responsible for message broadcasting
- **src/Logger.php**：PSR-3 compatible logger

## Quick Start

### Basic Usage

```php
use Workerman\Worker;
use PhpSocketIO\SocketIOServer;
use Psr\Log\LogLevel;

// Create Socket.IO server instance
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,  // Heartbeat interval (milliseconds)
    'pingTimeout'  => 20000,  // Heartbeat timeout (milliseconds)
    'maxPayload'   => 10485760, // Maximum payload (bytes)
    'workerCount'  => 1,       // Worker count, default is 1
    'logLevel'     => LogLevel::INFO, // Log level
]);

// Use $io->of() to register namespace connection event handler
$io->of('/chat')->on('connection', function ($socket) use ($io) {
    // Send welcome message
    $socket->emit('welcome', 'Welcome to Socket.IO server!');
    
    // Chat message handler
    $socket->on('chat message', function ($msg) use ($socket) {
        $socket->broadcast->emit('chat message', $msg);
    });
    
    // ACK message handler - must use callback, don't use return
    $socket->on('ack', function ($msg, $callback = null) use ($socket) {
        if (is_callable($callback)) {
            $callback(['status' => 'ok', 'data' => $msg]);
        }
    });
    
    // Disconnect handler
    $socket->on('disconnect', function () use ($socket) {
        // Cleanup logic
    });
});

// Start server
$io->start();
Worker::runAll();
```

### Using Logging

```php
use Psr\Log\LogLevel;

// 1. Use built-in logger (default)
$io = new SocketIOServer('0.0.0.0:8088', [
    'logLevel' => LogLevel::DEBUG
]);

// 2. Set custom log handler
$io->getLogger()->setHandler(function ($level, $message, $context) {
    // Here you can write to files, databases, or other logging services
    file_put_contents('/path/to/logs/socketio.log',
        "[{$level}] {$message}\n", FILE_APPEND
    );
});

// 3. Use third-party PSR-3 logging library, like Monolog
$logger = new \Monolog\Logger('socketio');
$logger->pushHandler(new \Monolog\Handler\StreamHandler('/path/to/logs/socketio.log'));
$io->setLogger($logger);
```

### Using Middlewares

```php
// 1. Global middleware - applies to all namespaces and events
$io->use(function ($socket, $packet, $next) {
    $sid = $socket['id'] ?? 'unknown';
    echo "[Global Middleware] SID: {$sid}\n";
    $next();
});

// 2. Namespace-level middleware - only applies to /chat namespace
$io->of('/chat')->use(function ($socket, $packet, $next) {
    echo "[Namespace Middleware] /chat\n";
    $next();
});

// 3. Socket instance-level middleware - only applies to current connection
$io->of('/chat')->on('connection', function ($socket) {
    $socket->use(function ($packet, $next) use ($socket) {
        echo "[Socket Middleware] Socket {$socket->id}\n";
        $next();
    });
});
```

### Multi-worker Configuration Example

When using multiple worker processes, adapter must be set via setAdapter method:

#### Using ClusterAdapter (based on Workerman Channel)

> Note: To use ClusterAdapter, you need to install workerman/channel first:
> ```bash
> composer require workerman/channel
> ```

```php
use Workerman\Worker;
use PhpSocketIO\SocketIOServer;
use PhpSocketIO\Adapter\ClusterAdapter;

// Create multi-worker server instance (4 workers)
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,
    'pingTimeout'  => 20000,
    'workerCount'  => 4,  // Set 4 workers
]);

// Create and set cluster adapter
$adapter = new ClusterAdapter([
    'channel_ip' => '127.0.0.1',
    'channel_port' => 2206,
    'prefix' => 'socketio_',
    'heartbeat' => 25
]);
$io->setAdapter($adapter);

// Event handling code...
$io->of('/chat')->on('connection', function ($socket) {
    // Handle connection...
});

Worker::runAll();
```

#### Using RedisAdapter (based on Redis)

> Note: To use RedisAdapter, you need to install workerman/redis first:
> ```bash
> composer require workerman/redis
> ```

```php
use Workerman\Worker;
use PhpSocketIO\SocketIOServer;
use PhpSocketIO\Adapter\RedisAdapter;

// Create multi-worker server instance (4 workers)
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,
    'pingTimeout'  => 20000,
    'workerCount'  => 4,  // Set 4 workers
]);

// Create and set Redis adapter
$adapter = new RedisAdapter([
    'host' => '127.0.0.1',
    'port' => 6379,
    'auth' => null,  // Redis authentication password, null for no password
    'db' => 0,       // Redis database number
    'prefix' => 'socketio_',
    'heartbeat' => 25
]);
$io->setAdapter($adapter);

// Event handling code...
$io->of('/chat')->on('connection', function ($socket) {
    // Handle connection...
});

Worker::runAll();
```

## Room Operations

```php
$io->of('/chat')->on('connection', function ($socket) use ($io) {
    // Join room
    $socket->join('room1');
    
    // Leave room
    $socket->leave('room1');
    
    // Send only to specified room
    $io->of('/chat')->to('room1')->emit('some event', 'message');
    
    // Send to multiple rooms
    $io->of('/chat')->to('room1')->to('room2')->emit('some event', 'message');
    
    // Broadcast (exclude current socket)
    $socket->broadcast->emit('some event', 'message');
    
    // Broadcast within specified room
    $socket->to('room1')->emit('some event', 'message');
    
    // Exclude specific rooms
    $socket->except('room2')->emit('some event', 'message');
});
```

## Event Acknowledgments (ACK)

**Important: ACK responses must use callback, don't use return!**

```php
$io->of('/chat')->on('connection', function ($socket) {
    $socket->on('reqAck', function ($data, $callback = null) {
        // Process data
        $result = ['status' => 'ok', 'data' => $data];
        
        // Call callback to send acknowledgment
        if (is_callable($callback)) {
            $callback($result);
        }
    });
    
    // Server sends ACK message to client
    $socket->on('ping', function () use ($socket) {
        $socket->emitWithAck('ackResponse', 'Hello', function ($clientData) {
            // Process client response
        });
    });
});
```

## Configuration Options

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `pingInterval` | int | 25000 | Heartbeat interval (milliseconds) |
| `pingTimeout` | int | 20000 | Heartbeat timeout (milliseconds) |
| `maxPayload` | int | 10485760 | Maximum payload size (bytes) |
| `workerCount` | int | 1 | Number of worker processes |
| `logLevel` | string | `LogLevel::INFO` | Log level (PSR-3) |
| `ssl` | array | [] | SSL configuration (for HTTPS/WSS) |

## Starting the Server

Use the provided server.php to start the server:

```bash
# Start server (foreground mode)
php server.php

# Start server (daemon mode)
php server.php -d

# Check server status
php server.php status

# Stop server
php server.php stop
```

## More Information

For detailed usage instructions, please refer to [USAGE.md](USAGE.md) file (Chinese only for now), which includes:
- Complete three-layer middleware system documentation
- Correct usage of namespaces
- Detailed ACK mechanism documentation
- API reference documentation
- Troubleshooting guide
- Performance optimization suggestions
- Security considerations

## Contributing

Issues and Pull Requests are welcome to improve this project. Before submitting code, please ensure:

1. Code conforms to the project's code style (following PSR standards)
2. Appropriate tests are added
3. Documentation is updated (README.md, USAGE.md, etc.)
4. All PHP syntax checks pass

## License

This project is licensed under the MulanPSL-2.0 License - see the [LICENSE](LICENSE) file for details.
