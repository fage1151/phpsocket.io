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
- **Connection State Recovery**：Full Socket.IO v4 connection state recovery support (private id, offset tracking, state recovery)
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
│   ├── Protocol/                 # Protocol related
│   │   ├── PacketParser.php      # Packet parser
│   │   └── EngineIOHandler.php   # Engine.IO protocol handler
│   ├── Transport/                # Transport layer
│   │   ├── WebSocketHandler.php  # WebSocket handler
│   │   ├── PollingHandler.php    # Polling handler
│   │   └── ConnectionManager.php # Connection manager
│   ├── Room/                     # Room management
│   │   └── RoomManager.php       # Room manager
│   ├── Event/                    # Event system
│   │   ├── EventHandler.php      # Event handler
│   │   └── MiddlewarePipeline.php # Middleware execution pipeline
│   ├── Enum/                     # Enum types
│   │   ├── EnginePacketType.php  # Engine.IO packet type enum
│   │   ├── SocketPacketType.php  # Socket.IO packet type enum
│   │   └── LogLevelPriority.php  # Log level priority enum
│   ├── Exceptions/               # Exception classes
│   │   ├── SocketIOException.php # Socket.IO base exception
│   │   ├── ConnectionException.php # Connection exception
│   │   └── ProtocolException.php # Protocol exception
│   ├── Support/                  # Support classes
│   │   ├── Logger.php            # PSR-3 compatible logger
│   │   ├── ErrorHandler.php      # Error handler
│   │   ├── SocketConn.php        # Socket connection info class
│   │   ├── Set.php               # Set data structure
│   │   └── ServerManager.php     # Server manager
│   ├── SocketIOServer.php        # Socket.IO server main class
│   ├── Socket.php                # Socket class
│   ├── SocketNamespace.php       # Namespace handler class
│   ├── Session.php               # Session management
│   └── Broadcaster.php           # Unified broadcaster
├── examples/                     # Examples directory
│   └── server.php                # Full server example
├── docs/                         # Documentation directory
│   ├── API.md                    # API reference
│   └── USAGE.md                  # Detailed usage documentation
├── tests/                        # Tests directory
├── README.md                     # Project documentation (Chinese)
├── README.en.md                  # Project documentation (English)
├── composer.json                 # Composer configuration
├── phpstan.neon                  # PHPStan configuration
├── phpcs.xml                     # PHPCS configuration
└── LICENSE                       # License file
```

## Core File Descriptions

### Core Classes (Root Directory)
- **src/SocketIOServer.php**：Socket.IO server main class, handles connections and event dispatch
- **src/SocketNamespace.php**：Namespace handler class, accessed via $io->of()
- **src/Session.php**：Session management, manages client session state and connection recovery
- **src/Socket.php**：Socket class, encapsulates client connection interface
- **src/Broadcaster.php**：Unified broadcaster, responsible for message broadcasting

### Protocol Related (Protocol/)
- **src/Protocol/PacketParser.php**：Packet parser, parses and constructs Socket.IO packets
- **src/Protocol/EngineIOHandler.php**：Engine.IO protocol handler, handles underlying transport protocol

### Transport Layer (Transport/)
- **src/Transport/WebSocketHandler.php**：WebSocket handler, handles HTTP polling and WebSocket handshake
- **src/Transport/PollingHandler.php**：Polling handler
- **src/Transport/ConnectionManager.php**：Connection manager

### Room Management (Room/)
- **src/Room/RoomManager.php**：Room manager, handles room-related operations

### Event System (Event/)
- **src/Event/EventHandler.php**：Event handler, handles various Socket.IO events
- **src/Event/MiddlewarePipeline.php**：Middleware execution pipeline

### Enum Types (Enum/)
- **src/Enum/EnginePacketType.php**：Engine.IO packet type enum
- **src/Enum/SocketPacketType.php**：Socket.IO packet type enum
- **src/Enum/LogLevelPriority.php**：Log level priority enum

### Exception Classes (Exceptions/)
- **src/Exceptions/SocketIOException.php**：Socket.IO base exception
- **src/Exceptions/ConnectionException.php**：Connection exception
- **src/Exceptions/ProtocolException.php**：Protocol exception

### Support Classes (Support/)
- **src/Support/Logger.php**：PSR-3 compatible logger
- **src/Support/ErrorHandler.php**：Error handler
- **src/Support/Set.php**：Set data structure, similar to JavaScript Set
- **src/Support/SocketConn.php**：Socket connection info class
- **src/Support/ServerManager.php**：Server manager

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

// Start Workerman
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

### Socket Methods

| Method | Description |
| --- | --- |
| `emit(string $event, mixed ...$args)` | Emit event to client |
| `emitBinary(string $event, mixed ...$args)` | Emit binary event to client |
| `emitWithAck($event, ...$args)` | Emit event with ACK, last parameter is callback |
| `join(string $room)` | Join a room |
| `leave(string $room)` | Leave a room |
| `to($room)` | Create broadcaster targeting specified room |
| `except($rooms)` | Create broadcaster excluding specified rooms |
| `broadcast()` | Broadcast event to all connections except self |
| `disconnect($close = false)` | Disconnect connection |
| `use(callable $middleware)` | Register socket-level middleware |

#### Socket Properties

| Property | Type | Description |
| --- | --- | --- |
| `id` | string | Socket ID (Session ID) |
| `namespace` | string | Current namespace |
| `auth` | mixed | Authentication data |
| `handshake` | object | Handshake information object |
| `data` | array | Custom data storage |

#### handshake Object Structure

The `socket.handshake` object contains complete handshake information:

```php
$io->of('/chat')->on('connection', function ($socket) {
    // Get handshake information
    $handshake = $socket->handshake;
    
    // Client IP address
    $clientIp = $handshake['address'];
    
    // Request headers
    $headers = $handshake['headers'];
    $userAgent = $headers['user-agent'] ?? 'Unknown';
    $origin = $headers['origin'] ?? null;
    
    // Is cross-domain
    $isCrossDomain = $handshake['xdomain'];
    
    // Is secure connection (HTTPS/WSS)
    $isSecure = $handshake['secure'];
    
    // Connection time
    $connectTime = $handshake['time'];
    $issued = $handshake['issued']; // Timestamp (milliseconds)
    
    // Request URL
    $url = $handshake['url'];
    
    // Query parameters
    $query = $handshake['query'];
    
    // Authentication data (if provided by client)
    $auth = $handshake['auth'];
});
```

| Field | Type | Description |
| --- | --- | --- |
| `headers` | array | HTTP request headers |
| `time` | string | Connection time (ISO 8601 format) |
| `issued` | int | Connection timestamp (milliseconds) |
| `address` | string | Client IP address |
| `xdomain` | bool | Is cross-domain request |
| `secure` | bool | Is secure connection (HTTPS/WSS) |
| `url` | string | Request URL |
| `query` | array | Query parameters |
| `auth` | mixed | Authentication data |

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
| `cors` | array/string | null | CORS configuration |

### CORS Configuration

```php
// Simple configuration - specify allowed origin only
$io = new SocketIOServer('0.0.0.0:8088', [
    'cors' => 'https://example.com'
]);

// Full configuration
$io = new SocketIOServer('0.0.0.0:8088', [
    'cors' => [
        'origin' => 'https://example.com',
        'methods' => ['GET', 'POST', 'OPTIONS'],
        'allowedHeaders' => ['my-custom-header', 'Content-Type'],
        'credentials' => true
    ]
]);
```

#### CORS Options

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `origin` | string/array | `*` | Allowed origin(s) |
| `methods` | array | `['GET', 'POST', 'OPTIONS']` | Allowed HTTP methods |
| `allowedHeaders` | array | `['Content-Type', 'Authorization']` | Allowed request headers |
| `credentials` | bool | `false` | Allow credentials (Cookies) |

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

For detailed usage instructions, please refer to [docs/USAGE.md](docs/USAGE.md) file (Chinese only for now), which includes:
- Complete three-layer middleware system documentation
- Correct usage of namespaces
- Detailed ACK mechanism documentation
- API reference documentation
- Troubleshooting guide
- Performance optimization suggestions
- Security considerations

## Version History

- **v1.5.0**：Complete Socket.IO v4 connection state recovery mechanism, added `$socket->recovered` property, supports state recovery, room recovery, and lost message retransmission; optimized project directory structure
- **v1.4.0**：PHP 8.1+ optimization, complete three-layer middleware system, fixed ACK mechanism, unified $io->of() usage
- **v1.3.0**：Optimized performance and stability, added PSR-3 logging
- **v1.2.0**：Added cluster mode support
- **v1.1.0**：Added binary data transmission support
- **v1.0.0**：Initial version, supports Socket.IO v4 protocol

## Contributing

Issues and Pull Requests are welcome to improve this project. Before submitting code, please ensure:

1. Code conforms to the project's code style (following PSR standards)
2. Appropriate tests are added
3. Documentation is updated (README.md, docs/USAGE.md, etc.)
4. All PHP syntax checks pass

## License

This project is licensed under the MulanPSL-2.0 License - see the [LICENSE](LICENSE) file for details.
