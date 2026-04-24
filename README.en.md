# PHPSocket.IO

A PHP server implementation of [Socket.IO](https://socket.io), supporting WebSocket and Long-Polling transports, compatible with Socket.IO protocol v4.

## Features

- **Multi-transport support**: Supports WebSocket and HTTP Long-Polling (only WebSocket is supported in multi-process mode and needs to be explicitly used)
- **Binary data support**: Supports binary event transmission
- **Cluster support**: Enables multi-process cluster communication via Channel or Redis
- **Room management**: Supports Room management functionality
- **Namespaces**: Supports multiple namespaces
- **Event acknowledgments**: Supports ACK acknowledgment mechanism
- **Middleware**: Supports connection and event middleware
- **Heartbeat detection**: Automatic heartbeat keep-alive mechanism
- **PSR-3 Logging**: Supports PSR-3 standard logging interface

## System Requirements

- PHP >= 8.1
- workerman/workerman >= 4.0
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

#### When using ClusterAdapter:

ClusterAdapter is based on Workerman Channel, which is not included by default and needs to be installed:

```bash
composer require workerman/channel
```

#### When using RedisAdapter:

```bash
composer require workerman/redis
```

## Project Structure

```
├── src/                          # Core source code directory
│   ├── Adapter/                  # Adapter directory
│   │   ├── AdapterInterface.php  # Adapter interface
│   │   ├── ClusterAdapter.php    # Channel-based cluster adapter
│   │   └── RedisAdapter.php      # Redis-based cluster adapter
│   ├── Broadcaster.php           # Unified broadcaster
│   ├── EngineIOHandler.php       # Engine.IO protocol handler
│   ├── EventHandler.php          # Event handler
│   ├── HttpRequestHandler.php    # HTTP request handler
│   ├── Logger.php                # PSR-3 compatible logger
│   ├── MiddlewareHandler.php     # Middleware handler
│   ├── PacketParser.php          # Packet parser
│   ├── PollingHandler.php        # Polling handler
│   ├── RoomManager.php           # Room manager
│   ├── ServerManager.php         # Server manager
│   ├── Session.php               # Session management
│   ├── Socket.php                # Socket class
│   └── SocketIOServer.php        # Socket.IO server main class
├── examples/                     # Examples directory
│   └── simple-chat/              # Simple chat example
├── tests/                        # Tests directory
├── server.php                    # Server startup script
├── index.html                    # Client example
├── composer.json                 # Composer configuration file
├── CHANGELOG.md                  # Change log
├── CONTRIBUTING.md               # Contribution guide
├── README.md                     # Project documentation (Chinese)
├── README.en.md                  # Project documentation (English)
├── USAGE.md                      # Usage documentation
└── LICENSE                       # License file
```

## Core File Descriptions

- **src/SocketIOServer.php**: Socket.IO server main class, handles connections and event dispatch
- **src/EventHandler.php**: Event handler, handles various Socket.IO events
- **src/HttpRequestHandler.php**: HTTP request handler, handles HTTP polling and WebSocket handshake
- **src/EngineIOHandler.php**: Engine.IO protocol handler, handles underlying transport protocol
- **src/Session.php**: Session management, manages client session state
- **src/Socket.php**: Socket class, encapsulates client connection interface
- **src/RoomManager.php**: Room manager, handles room-related operations
- **src/PacketParser.php**: Packet parser, parses Socket.IO packets
- **src/Broadcaster.php**: Unified broadcaster, responsible for message broadcasting
- **src/Logger.php**: PSR-3 compatible logger

## Quick Start

### Basic Usage

```php
use Workerman\Worker;
use PhpSocketIO\SocketIOServer;

// Create Socket.IO server instance
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,  // Heartbeat interval (milliseconds)
    'pingTimeout'  => 20000,  // Heartbeat timeout (milliseconds)
    'maxPayload'   => 10485760, // Maximum payload (bytes)
    'workerCount'  => 1,       // Worker count, default is 1
    'logLevel'     => \Psr\Log\LogLevel::INFO, // Log level
]);

// Connection event handling
$io->on('connection', function ($socket) use ($io) {
    // Send welcome message
    $socket->emit('welcome', 'Welcome to Socket.IO server!');
    
    // Chat message handling
    $socket->on('chat message', function ($msg) use ($socket) {
        $socket->broadcast->emit('chat message', $msg);
    });
    
    // Disconnect handling
    $socket->on('disconnect', function () use ($socket) {
        // Cleanup logic
    });
});

// Start server
Worker::runAll();
```

### Using Logging

```php
// 1. Use built-in logger (default)
$io = new SocketIOServer('0.0.0.0:8088', [
    'logLevel' => \Psr\Log\LogLevel::DEBUG
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

### Multi-worker Configuration Example

When using multiple worker processes, adapter must be set via setAdapter method:

##### Using ClusterAdapter (based on Workerman Channel)

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

Worker::runAll();
```

##### Using RedisAdapter (based on Redis)

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

Worker::runAll();
```

## Room Operations

```php
$io->on('connection', function ($socket) {
    // Join room
    $socket->join('room1');
    
    // Leave room
    $socket->leave('room1');
    
    // Send only to specified room
    $io->to('room1')->emit('some event', 'message');
    
    // Send to multiple rooms
    $io->to('room1')->to('room2')->emit('some event', 'message');
    
    // Broadcast (exclude current socket)
    $socket->broadcast->emit('some event', 'message');
    
    // Broadcast within specified room
    $socket->to('room1')->emit('some event', 'message');
});
```

## Event Acknowledgments (ACK)

```php
$io->on('connection', function ($socket) {
    $socket->on('reqAck', function ($data, $ack) {
        // Process data
        $result = ['status' => 'ok', 'data' => $data];
        
        // Call callback to send acknowledgment
        $ack($result);
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

## Composer Scripts

```bash
# Start server
composer start

# Start as daemon
composer start-daemon

# Stop server
composer stop

# Restart server
composer restart

# Check server status
composer status

# Run tests
composer test

# Check code style
composer cs-check

# Fix code style
composer cs-fix

# Static analysis
composer analyse
```

## More Information

For detailed usage instructions, please refer to the [USAGE.md](USAGE.md) file.

## Contributing

Please refer to the [CONTRIBUTING.md](CONTRIBUTING.md) file.

## License

This project is licensed under the Mulan PSL v2 License - see the [LICENSE](LICENSE) file for details.