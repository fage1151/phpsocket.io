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