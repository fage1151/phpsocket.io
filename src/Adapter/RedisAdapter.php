<?php

declare(strict_types=1);

namespace PhpSocketIO\Adapter;

final class RedisAdapter implements AdapterInterface
{
    private array $config;
    private ?object $redis = null;
    private array $rooms = [];
    private array $sids = [];
    private string $prefix = 'socketio_';
    private bool $initialized = false;
    private int $processId;
    private int $reconnectAttempts = 3;
    private int $reconnectInterval = 1000;
    private bool $isReconnecting = false;
    private ?object $subscriber = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'host' => '127.0.0.1',
            'port' => 6379,
            'auth' => null,
            'db' => 0,
            'prefix' => 'socketio_',
            'heartbeat' => 25
        ], $config);

        $this->prefix = $this->config['prefix'];
        $this->processId = getmypid();
    }

    private function getRedisClientClass(): string
    {
        if (!class_exists('\Workerman\Redis\Client')) {
            throw new \RuntimeException('Redis client not found. Please install workerman/redis via composer: composer require workerman/redis');
        }
        return '\Workerman\Redis\Client';
    }

    public function init(array $config = []): void
    {
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
            $this->prefix = $this->config['prefix'];
        }

        $this->connectRedis();
        $this->subscribeToChannels();
        $this->initialized = true;
    }

    private function connectRedis(): void
    {
        $redisClientClass = $this->getRedisClientClass();
        $attempt = 0;
        $maxAttempts = $this->reconnectAttempts;

        while ($attempt <= $maxAttempts) {
            try {
                $redisUrl = "redis://{$this->config['host']}:{$this->config['port']}";
                $this->redis = new $redisClientClass($redisUrl, ['connect_timeout' => 10]);

                if ($this->config['auth']) {
                    $this->redis->auth($this->config['auth']);
                }

                $this->redis->select($this->config['db']);
                $this->isReconnecting = false;
                return;
            } catch (\Exception $e) {
                $attempt++;
                if ($attempt > $maxAttempts) {
                    throw new \RuntimeException("Failed to connect to Redis", 0, $e);
                }

                usleep($this->reconnectInterval * 1000);
            }
        }
    }

    private function tryReconnect(): bool
    {
        if ($this->isReconnecting) {
            return false;
        }

        $this->isReconnecting = true;

        try {
            $this->connectRedis();
            return true;
        } catch (\Exception $e) {
            $this->isReconnecting = false;
            return false;
        }
    }

    private function executeRedisCommand(callable $callback, bool $retry = true): mixed
    {
        try {
            return $callback($this->redis);
        } catch (\Exception $e) {
            if ($retry && $this->tryReconnect()) {
                try {
                    return $callback($this->redis);
                } catch (\Exception $e2) {
                    throw $e2;
                }
            }

            throw $e;
        }
    }

    public function broadcast(array $packet): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('Adapter not initialized');
        }

        $data = [
            'packet' => $packet,
            'sender' => $this->processId,
            'timestamp' => microtime(true)
        ];

        $channel = $this->prefix . 'broadcast';
        $this->executeRedisCommand(function($redis) use ($channel, $data) {
            $redis->publish($channel, json_encode($data));
        });

        $this->sendToLocalAll($packet);
    }

    public function to(string $room, array $packet): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('Adapter not initialized');
        }

        $data = [
            'room' => $room,
            'packet' => $packet,
            'sender' => $this->processId,
            'timestamp' => microtime(true)
        ];

        $channel = $this->prefix . 'room';
        $this->executeRedisCommand(function($redis) use ($channel, $data) {
            $redis->publish($channel, json_encode($data));
        });

        $this->sendToLocalRoom($room, $packet);
    }

    public function emit(string $sid, array $packet): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('Adapter not initialized');
        }

        if (isset($this->sids[$sid])) {
            $this->sendToLocalSession($sid, $packet);
        } else {
            $data = [
                'sid' => $sid,
                'packet' => $packet,
                'sender' => $this->processId,
                'timestamp' => microtime(true)
            ];

            $channel = $this->prefix . 'send';
            $this->executeRedisCommand(function($redis) use ($channel, $data) {
                $redis->publish($channel, json_encode($data));
            });
        }
    }

    public function join(string $sid, string $room): void
    {
        if (!isset($this->rooms[$room])) {
            $this->rooms[$room] = [];
        }

        if (!in_array($sid, $this->rooms[$room])) {
            $this->rooms[$room][] = $sid;
        }

        if (!isset($this->sids[$sid])) {
            $this->sids[$sid] = [];
        }

        if (!in_array($room, $this->sids[$sid])) {
            $this->sids[$sid][] = $room;
        }

        $roomKey = $this->prefix . 'room:' . $room;
        $sidKey = $this->prefix . 'sid:' . $sid;
        $this->executeRedisCommand(function($redis) use ($roomKey, $sidKey, $sid, $room) {
            $redis->sAdd($roomKey, $sid);
            $redis->sAdd($sidKey, $room);
        });

        $data = [
            'action' => 'add',
            'sid' => $sid,
            'room' => $room,
            'process_id' => $this->processId,
            'timestamp' => microtime(true)
        ];

        $channel = $this->prefix . 'member';
        $this->executeRedisCommand(function($redis) use ($channel, $data) {
            $redis->publish($channel, json_encode($data));
        });
    }

    public function leave(string $sid, string $room): void
    {
        if (isset($this->rooms[$room])) {
            $index = array_search($sid, $this->rooms[$room]);
            if ($index !== false) {
                array_splice($this->rooms[$room], $index, 1);
            }

            if (empty($this->rooms[$room])) {
                unset($this->rooms[$room]);
            }
        }

        if (isset($this->sids[$sid])) {
            $index = array_search($room, $this->sids[$sid]);
            if ($index !== false) {
                array_splice($this->sids[$sid], $index, 1);
            }

            if (empty($this->sids[$sid])) {
                unset($this->sids[$sid]);
            }
        }

        $roomKey = $this->prefix . 'room:' . $room;
        $sidKey = $this->prefix . 'sid:' . $sid;
        $this->executeRedisCommand(function($redis) use ($roomKey, $sidKey, $sid, $room) {
            $redis->sRem($roomKey, $sid);
            $redis->sRem($sidKey, $room);
        });

        $data = [
            'action' => 'del',
            'sid' => $sid,
            'room' => $room,
            'process_id' => $this->processId,
            'timestamp' => microtime(true)
        ];

        $channel = $this->prefix . 'member';
        $this->executeRedisCommand(function($redis) use ($channel, $data) {
            $redis->publish($channel, json_encode($data));
        });
    }

    public function remove(string $sid): void
    {
        if (isset($this->sids[$sid])) {
            $rooms = $this->sids[$sid];
            foreach ($rooms as $room) {
                $this->leave($sid, $room);
            }
            unset($this->sids[$sid]);
        }

        $sidKey = $this->prefix . 'sid:' . $sid;
        $this->executeRedisCommand(function($redis) use ($sidKey) {
            $redis->del($sidKey);
        });
    }

    public function clients(string $room): array
    {
        return $this->rooms[$room] ?? [];
    }

    public function register(string $sid): void
    {
        $sessionKey = $this->prefix . 'session:' . $sid;
        $this->executeRedisCommand(function($redis) use ($sessionKey, $sid) {
            $redis->set($sessionKey, $this->processId);
            $redis->expire($sessionKey, 3600);
        });
    }

    public function unregister(string $sid): void
    {
        $sessionKey = $this->prefix . 'session:' . $sid;
        $this->executeRedisCommand(function($redis) use ($sessionKey) {
            $redis->del($sessionKey);
        });
    }

    public function close(): void
    {
        if ($this->redis) {
            $this->redis->close();
        }
        $this->initialized = false;
    }

    private function sendToLocalRoom(string $room, array $packet): void
    {
        if (isset($this->rooms[$room]) && !empty($this->rooms[$room])) {
            $sessionClass = '\PhpSocketIO\Session';
            if (class_exists($sessionClass)) {
                foreach ($this->rooms[$room] as $sid) {
                    $sessionClass::sendToSession($sid, $packet);
                }
            }
        }
    }

    private function sendToLocalAll(array $packet): void
    {
        $sessionClass = '\PhpSocketIO\Session';
        if (class_exists($sessionClass)) {
            $activeSessions = $sessionClass::all();
            foreach ($activeSessions as $sid => $session) {
                $sessionClass::sendToSession($sid, $packet);
            }
        }
    }

    private function sendToLocalSession(string $sid, array $packet): void
    {
        $sessionClass = '\PhpSocketIO\Session';
        if (class_exists($sessionClass)) {
            $sessionClass::sendToSession($sid, $packet);
        }
    }

    private function subscribeToChannels(): void
    {
        $redisClientClass = $this->getRedisClientClass();
        $redisUrl = "redis://{$this->config['host']}:{$this->config['port']}";

        $this->subscriber = new $redisClientClass($redisUrl, ['connect_timeout' => 10]);

        if ($this->config['auth']) {
            $this->subscriber->auth($this->config['auth']);
        }

        $this->subscriber->select($this->config['db']);

        $channels = [
            $this->prefix . 'broadcast',
            $this->prefix . 'room',
            $this->prefix . 'send',
            $this->prefix . 'member'
        ];

        $adapter = $this;
        $processId = $this->processId;

        foreach ($channels as $channel) {
            $this->subscriber->subscribe($channel, function($channel, $message) use ($adapter, $processId) {
                $adapter->handleRedisMessage($channel, $message, $processId);
            });
        }
    }

    private function handleRedisMessage(string $channel, string $message, int $processId): void
    {
        try {
            $data = json_decode($message, true);
            if (!$data) {
                return;
            }

            if (isset($data['sender']) && $data['sender'] === $processId) {
                return;
            }

            $prefix = $this->prefix;
            match ($channel) {
                $prefix . 'broadcast' => $this->handleBroadcastMessage($data),
                $prefix . 'room' => $this->handleRoomMessage($data),
                $prefix . 'send' => $this->handleSendMessage($data),
                $prefix . 'member' => $this->handleMemberMessage($data),
                default => null
            };
        } catch (\Exception $e) {
        }
    }

    private function handleBroadcastMessage(array $data): void
    {
        if (isset($data['packet'])) {
            $this->sendToLocalAll($data['packet']);
        }
    }

    private function handleRoomMessage(array $data): void
    {
        if (isset($data['room']) && isset($data['packet'])) {
            $this->sendToLocalRoom($data['room'], $data['packet']);
        }
    }

    private function handleSendMessage(array $data): void
    {
        if (isset($data['sid']) && isset($data['packet'])) {
            $this->sendToLocalSession($data['sid'], $data['packet']);
        }
    }

    private function handleMemberMessage(array $data): void
    {
    }
}
