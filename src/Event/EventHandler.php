<?php

declare(strict_types=1);

namespace PhpSocketIO\Event;

use ReflectionFunction;
use ReflectionException;
use PhpSocketIO\SocketIOServer;
use PhpSocketIO\Session;
use PhpSocketIO\Socket;
use PhpSocketIO\Protocol\PacketParser;
use Psr\Log\LoggerInterface;

/**
 * Socket.IO 事件处理器
 * 负责事件分发、命名空间管理和ACK机制
 * @package PhpSocketIO\Event
 */
class EventHandler
{
    private array $namespaceHandlers = [];
    private array $connectedSockets = [];
    private array $globalMiddlewares = [];
    private array $ackCallbacks = [];
    private array $ackCallbacksById = [];
    private ?SocketIOServer $server = null;
    private ?LoggerInterface $logger = null;

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function __construct(array $options = [])
    {
        $this->namespaceHandlers = [];
        $this->connectedSockets = [];
        $this->globalMiddlewares = [];
        $this->ackCallbacks = [];
        $this->ackCallbacksById = [];
        $this->server = $options['server'] ?? null;

        $this->initDefaultNamespace();
    }

    private function initDefaultNamespace(): void
    {
        $this->namespaceHandlers['/'] = [
            'connect' => null,
            'disconnect' => null,
            'events' => [],
            'sockets' => [],
            'middlewares' => []
        ];
    }

    private function ensureNamespaceInitialized(string $namespace): void
    {
        if (!isset($this->namespaceHandlers[$namespace])) {
            $this->namespaceHandlers[$namespace] = [
                'connect' => null,
                'disconnect' => null,
                'events' => [],
                'sockets' => [],
                'middlewares' => []
            ];
        }
    }

    public function getServer(): ?SocketIOServer
    {
        return $this->server;
    }

    public function use(callable $middleware): void
    {
        $this->globalMiddlewares[] = $middleware;
    }

    public function useForNamespace(string $namespace, callable $middleware): void
    {
        $this->ensureNamespaceInitialized($namespace);
        $this->namespaceHandlers[$namespace]['middlewares'][] = $middleware;
    }

    public function getNamespaceMiddlewares(string $namespace): array
    {
        $namespaceMiddlewares = $this->namespaceHandlers[$namespace]['middlewares'] ?? [];
        return array_merge($this->globalMiddlewares, $namespaceMiddlewares);
    }

    public function runMiddlewares(Socket $socket, callable $next): void
    {
        $namespace = $socket->namespace;
        $namespaceMiddlewares = $this->namespaceHandlers[$namespace]['middlewares'] ?? [];

        $allMiddlewares = array_merge($this->globalMiddlewares, $namespaceMiddlewares);

        if (empty($allMiddlewares)) {
            $next();
            return;
        }

        $index = 0;
        $middlewareCount = count($allMiddlewares);

        $runNext = function (mixed $error = null) use (&$index, $middlewareCount, $allMiddlewares, $socket, $next, &$runNext, $namespace): void {
            if ($error !== null) {
                $this->logger?->debug('Middleware rejected', [
                    'namespace' => $namespace,
                    'sid' => $socket->sid,
                    'error' => $error instanceof \Exception ? $error->getMessage() : (string)$error
                ]);

                $errorData = ['message' => $error instanceof \Exception ? $error->getMessage() : (string)$error];

                if ($error instanceof \Exception && property_exists($error, 'data')) {
                    $errorData['data'] = $error->data;
                }

                $errorPacket = PacketParser::buildSocketIOPacket('CONNECT_ERROR', [
                    'namespace' => $namespace,
                    'data' => $errorData,
                ]);
                $socket->session?->send($errorPacket);
                return;
            }

            if ($index < $middlewareCount) {
                $middleware = $allMiddlewares[$index];
                $index++;
                $middleware($socket, $runNext);
            } else {
                $next();
            }
        };

        try {
            $runNext();
        } catch (\Exception $e) {
            $this->logger?->debug('Middleware threw exception', [
                'namespace' => $namespace,
                'sid' => $socket->sid,
                'error' => $e->getMessage()
            ]);

            $errorData = ['message' => $e->getMessage()];

            if (property_exists($e, 'data')) {
                $errorData['data'] = $e->data;
            }

            $errorPacket = PacketParser::buildSocketIOPacket('CONNECT_ERROR', [
                'namespace' => $namespace,
                'data' => $errorData,
            ]);
            $socket->session?->send($errorPacket);
        }
    }

    public function of(string $namespace = '/', ?callable $handler = null): array
    {
        $this->ensureNamespaceInitialized($namespace);

        if ($handler) {
            $handler($this->namespaceHandlers[$namespace]);
        }

        return $this->namespaceHandlers[$namespace];
    }

    public static function buildHandlerArguments(callable $handler, array $socket, array $eventData, string $namespace, ?int $ackId = null, ?callable $ackCallback = null): array
    {
        try {
            $reflection = new ReflectionFunction($handler);
            $params = $reflection->getParameters();
            $paramCount = count($params);

            $callArgs = [];
            $hasSocketParam = false;
            $firstParamType = null;

            if ($paramCount > 0 && isset($params[0])) {
                $firstParam = $params[0];
                $type = $firstParam->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $firstParamType = $type->getName();
                    if (self::isSocketInstanceType($firstParamType)) {
                        $hasSocketParam = true;
                    }
                }
            }

            $callbackParamIndex = null;
            if ($ackId !== null && $ackCallback !== null && $paramCount > 0) {
                $lastParam = $params[$paramCount - 1];
                if ($lastParam->isOptional()) {
                    $callbackParamIndex = $paramCount - 1;
                } elseif ($paramCount > count($eventData) + ($hasSocketParam ? 1 : 0)) {
                    $callbackParamIndex = $paramCount - 1;
                }
            }

            if ($hasSocketParam) {
                $socketInstance = self::createSocketInstanceForHandler($socket, $namespace);
                $callArgs[] = $socketInstance;

                foreach ($eventData as $data) {
                    $callArgs[] = $data;
                }
            } else {
                $callArgs = $eventData;
            }

            if ($callbackParamIndex !== null && $ackCallback !== null) {
                while (count($callArgs) < $callbackParamIndex) {
                    $callArgs[] = null;
                }
                if (count($callArgs) === $callbackParamIndex) {
                    $callArgs[] = $ackCallback;
                } else {
                    $callArgs[] = $ackCallback;
                }
            }

            while (count($callArgs) < $paramCount) {
                $callArgs[] = null;
            }

            return $callArgs;
        } catch (ReflectionException $e) {
            $args = array_merge([$socket], $eventData);
            if ($ackId !== null && $ackCallback !== null) {
                $args[] = $ackCallback;
            }
            return $args;
        }
    }

    public static function isSocketInstanceType(?string $typeName): bool
    {
        if (!$typeName) {
            return false;
        }

        if (strpos($typeName, 'Socket') !== false) {
            return true;
        }

        return class_exists($typeName) && method_exists($typeName, 'emit');
    }

    public static function createSocketInstanceForHandler(array $socket, string $namespace): mixed
    {
        if (isset($socket['socket']) && is_object($socket['socket'])) {
            return $socket['socket'];
        }

        if (isset($socket['session']) && is_object($socket['session'])) {
            return [
                'id' => $socket['id'] ?? 'unknown',
                'namespace' => $namespace,
                'session' => $socket['session']
            ];
        }

        return $socket;
    }

    public function on(string $event, callable $callback, string $namespace = '/'): void
    {
        if (!isset($this->namespaceHandlers[$namespace])) {
            $this->of($namespace);
        }

        $this->namespaceHandlers[$namespace]['events'][$event] = $callback;
    }

    public function removeEventHandler(string $namespace, string $event, ?callable $callback = null): void
    {
        $namespace = $this->normalizeNamespace($namespace);

        if (!isset($this->namespaceHandlers[$namespace]['events'][$event])) {
            return;
        }

        if ($callback === null) {
            unset($this->namespaceHandlers[$namespace]['events'][$event]);
            return;
        }

        if ($this->namespaceHandlers[$namespace]['events'][$event] === $callback) {
            unset($this->namespaceHandlers[$namespace]['events'][$event]);
        }
    }

    public function triggerConnect(array $socket, string $namespace = '/', ?SocketIOServer $socketIOServer = null): void
    {
        $socket['namespace'] = $namespace;
        $this->connectedSockets[$socket['id']] = $socket;

        $this->logger?->info('Socket.IO 客户端连接到命名空间', [
            'sid' => $socket['id'],
            'namespace' => $namespace
        ]);

        if (isset($this->namespaceHandlers[$namespace])) {
            $this->namespaceHandlers[$namespace]['sockets'][$socket['id']] = $socket;
        }

        $hasRealHandler = $socketIOServer && method_exists($socketIOServer, 'getNamespaceHandlers')
            && isset($socketIOServer->getNamespaceHandlers()[$namespace]);

        if ($hasRealHandler || isset($this->namespaceHandlers[$namespace]['connect'])) {
            $this->executeConnectionHandler($socket, $namespace, $socketIOServer);
        }
    }

    private function executeConnectionHandler(array $socket, string $namespace, ?SocketIOServer $socketIOServer): void
    {
        if (!$socketIOServer) {
            if (isset($this->namespaceHandlers[$namespace]['connect']) && is_callable($this->namespaceHandlers[$namespace]['connect'])) {
                call_user_func($this->namespaceHandlers[$namespace]['connect'], $socket);
            }
            return;
        }

        $sessionId = $socket['session']->sid ?? null;
        $realSocket = method_exists($socketIOServer, 'getOrCreateSocket')
            ? $socketIOServer->getOrCreateSocket($socket['session'], $namespace)
            : new Socket($sessionId, $namespace, $socketIOServer, $socket['connection'] ?? null);

        $adapter = $socketIOServer->getServerManager()->getAdapter();
        if ($adapter) {
            try {
                $adapter->register($socket['id']);
            } catch (\Exception $e) {
                $this->logger?->error('Failed to register socket in adapter', [
                    'socket_id' => $socket['id'],
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $callback = $socketIOServer->getSocketIoCallback('connection', $namespace)
            ?? ($this->namespaceHandlers[$namespace]['connect'] ?? null);

        if ($callback instanceof \Closure || is_callable($callback)) {
            $callback($realSocket);
        }
    }

    public function triggerDisconnect(array $socket, string $reason = 'client disconnect'): void
    {
        $namespace = $socket['namespace'] ?? '/';
        $socketId = $socket['id'] ?? '';

        $this->logger?->info('Socket.IO 客户端断开连接', [
            'sid' => $socketId,
            'namespace' => $namespace,
            'reason' => $reason
        ]);

        if (isset($this->namespaceHandlers[$namespace])) {
            unset($this->namespaceHandlers[$namespace]['sockets'][$socketId]);
        }

        unset($this->connectedSockets[$socketId]);

        $adapter = null;

        if ($this->server && method_exists($this->server, 'getServerManager')) {
            $serverManager = $this->server->getServerManager();
            $adapter = $serverManager->getAdapter();
        }

        if (!$adapter && isset($socket['socket']) && $socket['socket'] instanceof Socket) {
            $socketServer = $socket['socket']->server ?? null;
            if ($socketServer && method_exists($socketServer, 'getServerManager')) {
                $adapter = $socketServer->getServerManager()->getAdapter();
            }
        }

        if ($adapter && method_exists($adapter, 'unregister')) {
            try {
                $adapter->unregister($socketId);
            } catch (\Exception $e) {
                $this->logger?->error('Failed to unregister socket in adapter', [
                    'socket_id' => $socketId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        if (
            isset($this->namespaceHandlers[$namespace]['disconnect']) &&
            is_callable($this->namespaceHandlers[$namespace]['disconnect'])
        ) {
            $socketInstance = $socket['socket'] ?? null;
            if ($socketInstance && $socketInstance instanceof Socket) {
                call_user_func($this->namespaceHandlers[$namespace]['disconnect'], $socketInstance, $reason);
            } else {
                call_user_func($this->namespaceHandlers[$namespace]['disconnect'], $socket, $reason);
            }
        }
    }

    public function triggerDisconnecting(array $socket, string $reason): void
    {
        $namespace = $socket['namespace'] ?? '/';
        $eventName = 'disconnecting';

        if (isset($this->namespaceHandlers[$namespace]['events'][$eventName])
            && is_callable($this->namespaceHandlers[$namespace]['events'][$eventName])) {
            $socketInstance = $socket['socket'] ?? null;
            if ($socketInstance && $socketInstance instanceof Socket) {
                call_user_func($this->namespaceHandlers[$namespace]['events'][$eventName], $socketInstance, $reason);
            } else {
                call_user_func($this->namespaceHandlers[$namespace]['events'][$eventName], $socket, $reason);
            }
        }
    }

    public function handlePacket(array $packet, Socket $socket, ?callable $customHandler = null): mixed
    {
        if ($customHandler) {
            $customHandler($socket, $packet);
            return true;
        }

        $socketInfo = [
            'id' => $socket->sid,
            'namespace' => $socket->namespace,
            'session' => $socket->session,
            'connection' => $socket->connection,
            'socket' => $socket,
        ];

        switch ($packet['type']) {
            case 'CONNECT':
                return $this->handleConnect($packet, $socketInfo);
            case 'DISCONNECT':
                return $this->handleDisconnect($packet, $socketInfo);
            case 'EVENT':
            case 'BINARY_EVENT':
                return $this->handleEvent($packet, $socketInfo);
            case 'ACK':
            case 'BINARY_ACK':
                return $this->handleAck($packet, $socketInfo);
            case 'CONNECT_ERROR':
                return $this->handleError($packet, $socketInfo);
            default:
                $this->sendError($socketInfo, "Unknown packet type: {$packet['type']}");
                return false;
        }
    }

    private function handleConnect(array $packet, array $socket): bool
    {
        $namespace = $packet['namespace'] ?? '/';
        $auth = $packet['auth'] ?? null;

        if (!$this->validateAuth($namespace, $auth)) {
            $this->sendError($socket, 'Authentication failed');
            return false;
        }

        $this->triggerConnect($socket, $namespace);

        return true;
    }

    private function handleDisconnect(array $packet, array $socket): bool
    {
        $namespace = $packet['namespace'] ?? '/';
        $this->triggerDisconnect($socket, 'client disconnect');
        return true;
    }

    private function handleEvent(array $packet, array $socket): bool
    {
        $namespace = $packet['namespace'] ?? '/';
        $eventName = $packet['event'] ?? '';
        $eventData = $packet['data'] ?? [];
        $ackId = $packet['id'] ?? null;

        $this->logger?->debug('handleEvent 开始处理', [
            'namespace' => $namespace,
            'eventName' => $eventName,
            'eventData' => $eventData,
            'ackId' => $ackId,
            'socket' => $socket
        ]);

        if (!$eventName) {
            $this->sendError($socket, 'Event name is required');
            return false;
        }

        $found = $this->executeEventHandler($namespace, $eventName, $socket, $eventData, $ackId);

        if (!$found) {
            $this->logger?->debug('未找到事件处理器', [
                'namespace' => $namespace,
                'eventName' => $eventName,
                'availableHandlers' => array_keys($this->namespaceHandlers[$namespace]['events'] ?? [])
            ]);
        }

        return $found;
    }

    private function handleAck(array $packet, array $socket): bool
    {
        $namespace = $packet['namespace'] ?? '/';
        $ackId = $packet['id'] ?? null;
        $ackData = $packet['data'] ?? [];

        if ($ackId === null) {
            $this->sendError($socket, 'ACK ID is required');
            return false;
        }

        $callbackKey = "{$socket['id']}:{$namespace}:{$ackId}";

        if (isset($this->ackCallbacks[$callbackKey])) {
            $callback = $this->ackCallbacks[$callbackKey];

            try {
                $reflection = new ReflectionFunction($callback);
                $expectedParams = $reflection->getNumberOfParameters();

                $ackArgs = [];
                if (is_array($ackData)) {
                    if ($expectedParams >= count($ackData)) {
                        $ackArgs = array_pad($ackData, $expectedParams, null);
                    } else {
                        $ackArgs = $ackData;
                    }
                } else {
                    $ackArgs = array_pad([$ackData], $expectedParams, null);
                }

                call_user_func_array($callback, $ackArgs);

                $this->removeAckCallback($callbackKey, $ackId);

                return true;
            } catch (ReflectionException $e) {
                $this->logger?->debug('Reflection failed for ACK callback, using fallback strategy', [
                    'ack_id' => $ackId,
                    'error' => $e->getMessage()
                ]);
                call_user_func($callback, $ackData);
                $this->removeAckCallback($callbackKey, $ackId);
                return true;
            }
        }

        $this->sendError($socket, "ACK callback not found for id: {$ackId}");
        return false;
    }

    private function handleError(array $packet, array $socket): bool
    {
        return false;
    }

    private function validateAuth(string $namespace, mixed $auth): bool
    {
        if (!isset($this->namespaceHandlers[$namespace]['authValidator'])) {
            return true;
        }

        $validator = $this->namespaceHandlers[$namespace]['authValidator'];
        if (is_callable($validator)) {
            return (bool) $validator($auth);
        }

        return true;
    }

    public function setAuthValidator(string $namespace, callable $validator): void
    {
        $this->ensureNamespaceInitialized($namespace);
        $this->namespaceHandlers[$namespace]['authValidator'] = $validator;
    }

    private function sendPacket(array $socket, array $packet): void
    {
        if (isset($socket['session']) && method_exists($socket['session'], 'send')) {
            $session = $socket['session'];

            switch ($packet['type']) {
                case 'ACK':
                    $namespace = $packet['namespace'] ?? '/';
                    $ackId = $packet['id'] ?? null;
                    $ackData = $packet['data'] ?? [];
                    $ackPacket = PacketParser::buildSocketIOPacket('ACK', [
                        'namespace' => $namespace,
                        'id' => $ackId,
                        'data' => $ackData,
                    ]);
                    $session->send($ackPacket);
                    break;

                case 'CONNECT':
                    $namespace = $packet['namespace'] ?? '/';
                    $data = $packet['data'] ?? [];
                    $connectPacket = PacketParser::buildSocketIOPacket('CONNECT', [
                        'namespace' => $namespace,
                        'data' => $data,
                    ]);
                    $session->send($connectPacket);
                    break;

                case 'EVENT':
                    $namespace = $packet['namespace'] ?? '/';
                    $eventName = $packet['event'] ?? '';
                    $eventData = $packet['data'] ?? [];
                    $eventPacket = PacketParser::buildSocketIOPacket('EVENT', [
                        'namespace' => $namespace,
                        'event' => $eventName,
                        'data' => $eventData,
                    ]);
                    $session->send($eventPacket);
                    break;

                case 'CONNECT_ERROR':
                    $namespace = $packet['namespace'] ?? '/';
                    $error = $packet['error'] ?? 'Unknown error';
                    $errorPacket = PacketParser::buildSocketIOPacket('CONNECT_ERROR', [
                        'namespace' => $namespace,
                        'data' => ['message' => $error],
                    ]);
                    $session->send($errorPacket);
                    break;

                default:
                    $namespace = $packet['namespace'] ?? '/';
                    $defaultPacket = PacketParser::buildSocketIOPacket('EVENT', [
                        'namespace' => $namespace,
                        'event' => $packet['type'],
                        'data' => $packet,
                    ]);
                    $session->send($defaultPacket);
                    break;
            }
        }
    }

    private function sendError(array $socket, string $message): void
    {
        $this->sendPacket($socket, [
            'type' => 'CONNECT_ERROR',
            'namespace' => $socket['namespace'] ?? '/',
            'error' => $message
        ]);
    }

    private function sendAck(array $socket, string $namespace, int $ackId, mixed $data): void
    {
        if (isset($socket['session']) && method_exists($socket['session'], 'send')) {
            $session = $socket['session'];
            $ackData = is_array($data) ? $data : [$data];
            $ackPacket = PacketParser::buildSocketIOPacket('ACK', [
                'namespace' => $namespace,
                'id' => $ackId,
                'data' => $ackData,
            ]);
            $session->send($ackPacket);
        } else {
            $ackData = is_array($data) ? $data : [$data];
            $this->sendPacket($socket, [
                'type' => 'ACK',
                'namespace' => $namespace,
                'data' => $ackData,
                'id' => $ackId
            ]);
        }
    }

    public function storeAckCallback(array $socket, string $namespace, int $ackId, callable $callback): void
    {
        $callbackKey = "{$socket['id']}:{$namespace}:{$ackId}";
        $this->ackCallbacks[$callbackKey] = $callback;

        if (!isset($this->ackCallbacksById[$ackId])) {
            $this->ackCallbacksById[$ackId] = [];
        }
        $this->ackCallbacksById[$ackId][] = $callbackKey;
    }

    private function removeAckCallback(string $callbackKey, int $ackId): void
    {
        unset($this->ackCallbacks[$callbackKey]);

        if (isset($this->ackCallbacksById[$ackId])) {
            $ids = &$this->ackCallbacksById[$ackId];
            foreach ($ids as $i => $key) {
                if ($key === $callbackKey) {
                    array_splice($ids, $i, 1);
                    break;
                }
            }
            if (empty($ids)) {
                unset($this->ackCallbacksById[$ackId]);
            }
        }
    }

    public function executeAckCallback(int $ackId, mixed $data): bool
    {
        if (!isset($this->ackCallbacksById[$ackId])) {
            return false;
        }

        foreach ($this->ackCallbacksById[$ackId] as $callbackKey) {
            if (!isset($this->ackCallbacks[$callbackKey])) {
                continue;
            }

            $callback = $this->ackCallbacks[$callbackKey];
            $ackArgs = is_array($data) ? $data : [$data];

            try {
                $reflection = new ReflectionFunction($callback);
                $expectedParams = $reflection->getNumberOfParameters();

                $finalArgs = [];
                if (is_array($data)) {
                    if ($expectedParams >= count($data)) {
                        $finalArgs = array_pad($data, $expectedParams, null);
                    } else {
                        $finalArgs = $data;
                    }
                } else {
                    $finalArgs = array_pad([$data], $expectedParams, null);
                }

                call_user_func_array($callback, $finalArgs);
                $this->removeAckCallback($callbackKey, $ackId);
                return true;
            } catch (\Exception $e) {
                $this->logger?->error('Failed to execute ACK callback', [
                    'ack_id' => $ackId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return false;
            }
        }

        return false;
    }

    private function normalizeNamespace(string $namespace): string
    {
        return $namespace === '' ? '/' : $namespace;
    }

    public function buildAckCallback(array $socket, string $namespace, ?int $ackId): ?callable
    {
        if ($ackId === null) {
            return null;
        }

        return function (mixed ...$data) use ($socket, $namespace, $ackId): void {
            $this->logger?->debug('ackCallback 被调用', [
                'namespace' => $namespace,
                'ackId' => $ackId,
                'data' => $data
            ]);
            if (count($data) === 1) {
                $this->sendAck($socket, $namespace, $ackId, $data[0]);
            } else {
                $this->sendAck($socket, $namespace, $ackId, $data);
            }
        };
    }

    public function executeEventHandler(string $namespace, string $eventName, array $socketInfo, array $eventArgs, ?int $ackId): bool
    {
        $handler = $this->getEventHandler($namespace, $eventName);
        if (!$handler) {
            return false;
        }

        $this->logger?->debug('找到事件处理器', [
            'namespace' => $namespace,
            'eventName' => $eventName
        ]);

        $ackCallback = $this->buildAckCallback($socketInfo, $namespace, $ackId);
        $callArgs = self::buildHandlerArguments($handler, $socketInfo, $eventArgs, $namespace, $ackId, $ackCallback);

        $this->logger?->debug('构建的调用参数', [
            'callArgs' => $callArgs,
            'ackCallbackExists' => $ackCallback !== null
        ]);

        call_user_func_array($handler, $callArgs);
        $this->logger?->debug('事件处理器调用完成');

        return true;
    }

    public function getEventHandler(string $namespace, string $eventName): ?callable
    {
        $namespace = $this->normalizeNamespace($namespace);
        return $this->namespaceHandlers[$namespace]['events'][$eventName] ?? null;
    }

    public function hasEventHandler(string $namespace, string $eventName): bool
    {
        $namespace = $this->normalizeNamespace($namespace);
        return isset($this->namespaceHandlers[$namespace]['events'][$eventName]);
    }

    public function getAllEventHandlers(string $namespace): array
    {
        $namespace = $this->normalizeNamespace($namespace);
        return $this->namespaceHandlers[$namespace]['events'] ?? [];
    }

    public function trigger(string $event, mixed ...$args): void
    {
        $handler = $this->getEventHandler('/', $event);
        if ($handler !== null) {
            call_user_func_array($handler, $args);
        }
    }
}
