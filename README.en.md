# PHPSocket.IO

A PHP server implementation of [Socket.IO](https://socket.io), supporting WebSocket and Long-Polling transports, compatible with Socket.IO protocol v4.

## Features

- **Multi-transport support**: Supports WebSocket and HTTP Long-Polling
- **Binary data support**: Supports binary event transmission
- **Cluster support**: Enables multi-process cluster communication via Redis
- **Room management**: Supports Room management functionality
- **Namespaces**: Supports multiple namespaces
- **Event acknowledgments**: Supports ACK acknowledgment mechanism
- **Middleware**: Supports connection and event middleware
- **Heartbeat detection**: Automatic heartbeat keep-alive mechanism

## System Requirements

- PHP >= 7.4
- [workerman/workerman](https://github.com/walkerman/workerman) >= 4.0
- Redis extension (required for cluster mode)

## Installation

Install via Composer:

```bash
composer require phpsocketio/server
```

## Quick Start

### Basic Usage

```php
use PhpSocketIO\SocketIOServer;

// Create Socket.IO server instance
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,  // Heartbeat interval (milliseconds)
    'pingTimeout'  => 20000,  // Heartbeat timeout (milliseconds)
    'maxPayload'   => 10485760, // Maximum payload (bytes)
    'workerCount'  => 1,       // Worker count, default is 1
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

### Multi-worker Configuration Example

When using multiple worker processes, adapter must be set via setAdapter method:

```php
use PhpSocketIO\SocketIOServer;
use PhpSocketIO\Adapter\ClusterAdapter;

// Create multi-worker server instance (4 workers)
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,
    'pingTimeout'  => 20000,
    'workerCount'  => 4,  // Set 4 workers
]);

// Set cluster adapter
$io->setAdapter(new ClusterAdapter($io->getServerManager()));

// Event handling code...

Worker::runAll();
```

## More Information

For detailed usage instructions, please refer to the [USAGE.md](USAGE.md) file.