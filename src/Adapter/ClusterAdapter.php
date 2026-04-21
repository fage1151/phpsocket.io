<?php

declare(strict_types=1);

namespace PhpSocketIO\Adapter;

final class ClusterAdapter implements AdapterInterface
{
    private array $config;
    private array $rooms = [];
    private array $sids = [];
    private string $prefix = 'socketio_';
    private bool $initialized = false;
    private array $sessionProcessMap = [];
    private int $processId;
    private array $messageQueue = [];
    private bool $batchTimerStarted = false;
    private int $maxBatchSize = 50;
    private float $batchInterval = 0.1;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'channel_ip' => '127.0.0.1',
            'channel_port' => 2206,
            'prefix' => 'socketio_',
            'heartbeat' => 25
        ], $config);

        $this->prefix = $this->config['prefix'];
        $this->processId = getmypid();
    }

    private function getChannelClientClass(): string
    {
        if (!class_exists('\Channel\Client')) {
            throw new \RuntimeException('Channel client not found. Please install workerman/channel via composer: composer require workerman/channel');
        }
        return '\Channel\Client';
    }

    public function init(array $config = []): void
    {
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
            $this->prefix = $this->config['prefix'];
        }

        $this->initChannelClient();
        $this->initialized = true;
    }

    private function initChannelClient(): void
    {
        $channelClientClass = $this->getChannelClientClass();

        try {
            $channelClientClass::connect($this->config['channel_ip'], $this->config['channel_port']);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to initialize channel client", 0, $e);
        }

        $channelClientClass::on($this->prefix . 'broadcast', function($packet) {
            $this->handleBroadcast($packet);
        });

        $channelClientClass::on($this->prefix . 'room', function($data) {
            $this->handleRoomMessage($data);
        });

        $channelClientClass::on($this->prefix . 'member', function($data) {
            $this->handleMemberChange($data);
        });

        $channelClientClass::on($this->prefix . 'send', function($data) {
            $this->handleSendMessage($data);
        });

        $channelClientClass::on($this->prefix . 'session_register', function($data) {
            $this->handleSessionRegister($data);
        });

        $channelClientClass::on($this->prefix . 'session_query', function($data) {
            $this->handleSessionQuery($data);
        });

        $channelClientClass::on($this->prefix . 'session_response', function($data) {
            $this->handleSessionResponse($data);
        });

        $channelClientClass::on($this->prefix . 'session_probe', function($data) {
            $this->handleSessionProbe($data);
        });

        $this->startSessionMappingHeartbeat();
        $this->startSessionCleanupTimer();
        $this->startHeartbeat();
    }

    private function startHeartbeat(): void
    {
        \Workerman\Timer::add($this->config['heartbeat'], function() {
            try {
                $this->publishBatch($this->prefix . 'heartbeat', [
                    'pid' => getmypid(),
                    'time' => time()
                ], true);
            } catch (\Exception $e) {
                $this->initChannelClient();
            }
        });
    }

    public function to(string $room, array $packet): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('Adapter not initialized');
        }

        if (!$this->validateSocketIOV4Packet($packet)) {
            return;
        }

        $data = [
            'room' => $room,
            'packet' => $packet,
            'sender' => getmypid(),
            'timestamp' => microtime(true)
        ];

        $this->publishBatch($this->prefix . 'room', $data);
    }

    public function broadcast(array $packet): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('Adapter not initialized');
        }

        if (!$this->validateSocketIOV4Packet($packet)) {
            return;
        }

        $data = [
            'packet' => $packet,
            'sender' => $this->processId,
            'timestamp' => microtime(true)
        ];

        $this->publishBatch($this->prefix . 'broadcast', $data);
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

        $this->publishBatch($this->prefix . 'member', [
            'action' => 'add',
            'sid' => $sid,
            'room' => $room,
            'process_id' => $this->processId,
            'timestamp' => microtime(true)
        ]);
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

        $this->publishBatch($this->prefix . 'member', [
            'action' => 'del',
            'sid' => $sid,
            'room' => $room,
            'process_id' => $this->processId,
            'timestamp' => microtime(true)
        ]);
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
    }

    public function clients(string $room): array
    {
        return $this->rooms[$room] ?? [];
    }

    private function handleBroadcast(array $data): void
    {
        if ($data['sender'] === getmypid()) {
            return;
        }

        $packet = $data['packet'];

        if (!$this->validateSocketIOV4Packet($packet)) {
            return;
        }

        $sessionClass = '\PhpSocketIO\Session';
        if (class_exists($sessionClass)) {
            $activeSessions = $sessionClass::all();
            foreach ($activeSessions as $sid => $session) {
                $sessionClass::sendToSession($sid, $packet);
            }
        }
    }

    private function handleRoomMessage(array $data): void
    {
        if ($data['sender'] === getmypid()) {
            return;
        }

        $room = $data['room'];
        $packet = $data['packet'];

        if (!$this->validateSocketIOV4Packet($packet)) {
            return;
        }

        if (isset($this->rooms[$room]) && !empty($this->rooms[$room])) {
            $sessionClass = '\PhpSocketIO\Session';
            if (class_exists($sessionClass)) {
                foreach ($this->rooms[$room] as $sid) {
                    $sessionClass::sendToSession($sid, $packet);
                }
            }
        }
    }

    private function handleMemberChange(array $data): void
    {
        if ($data['sender'] === getmypid()) {
            return;
        }

        $action = $data['action'];
        $sid = $data['sid'];
        $room = $data['room'];

        match ($action) {
            'add' => $this->handleMemberAdd($sid, $room),
            'del' => $this->handleMemberDel($sid, $room),
            default => null
        };
    }

    private function handleMemberAdd(string $sid, string $room): void
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
    }

    private function handleMemberDel(string $sid, string $room): void
    {
        if (isset($this->rooms[$room])) {
            $index = array_search($sid, $this->rooms[$room]);
            if ($index !== false) {
                array_splice($this->rooms[$room], $index, 1);
            }
        }

        if (isset($this->sids[$sid])) {
            $index = array_search($room, $this->sids[$sid]);
            if ($index !== false) {
                array_splice($this->sids[$sid], $index, 1);
            }
        }
    }

    public function emit(string $sid, array $packet): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('Adapter not initialized');
        }

        $targetProcess = $this->getSessionProcess($sid);

        if ($targetProcess === $this->processId) {
            $this->handleDirectSend($sid, $packet);
        } else {
            $this->forwardToProcess($targetProcess, $sid, $packet);
        }
    }

    private function getSessionProcess(string $sid): int
    {
        if (isset($this->sessionProcessMap[$sid])) {
            return is_array($this->sessionProcessMap[$sid]) ?
                $this->sessionProcessMap[$sid]['processId'] :
                $this->sessionProcessMap[$sid];
        }

        if ($this->isSessionLocal($sid)) {
            $this->sessionProcessMap[$sid] = $this->processId;
            return $this->processId;
        }

        return $this->discoverSessionProcess($sid);
    }

    private function isSessionLocal(string $sid): bool
    {
        $sessionClass = '\PhpSocketIO\Session';
        if (!class_exists($sessionClass)) {
            return false;
        }

        $session = $sessionClass::get($sid);
        return $session !== null;
    }

    private function discoverSessionProcess(string $sid): int
    {
        $queryId = uniqid('query_', true);

        $retryConfigs = [
            ['timeout' => 0.1, 'description' => '快速查询'],
            ['timeout' => 0.3, 'description' => '标准重试'],
            ['timeout' => 0.5, 'description' => '扩展重试']
        ];

        foreach ($retryConfigs as $config) {
            $result = $this->performSessionQuery($sid, $queryId, $config['timeout']);
            if ($result !== null) {
                return $result;
            }
        }

        if ($this->isSessionAvailableAnywhere($sid)) {
            throw new \RuntimeException(
                "Socket.IO v4 Session routing failed: Session {$sid} " .
                "exists in cluster but could not be routed after 0.9s timeout. " .
                "This may be due to network latency or high cluster load."
            );
        }

        throw new \RuntimeException(
            "Socket.IO v4 Session not found: Session {$sid} " .
            "does not exist in the cluster (checked all nodes). " .
            "The session may have been disconnected or expired."
        );
    }

    private function performSessionQuery(string $sid, string $queryId, float $timeout): ?int
    {
        $queryData = [
            'sid' => $sid,
            'query_id' => $queryId,
            'requester' => $this->processId,
            'timestamp' => microtime(true)
        ];

        $this->sessionProcessMap[$sid] = null;
        $this->publishBatch($this->prefix . 'session_query', $queryData, true);

        $startTime = microtime(true);
        while (microtime(true) - $startTime < $timeout) {
            if (isset($this->sessionProcessMap[$sid]) && $this->sessionProcessMap[$sid] !== null) {
                $sessionInfo = $this->sessionProcessMap[$sid];
                $this->sessionProcessMap[$sid] = is_array($sessionInfo) ?
                    $sessionInfo['processId'] : $sessionInfo;
                return is_array($sessionInfo) ? $sessionInfo['processId'] : $sessionInfo;
            }
            usleep(10000);
        }

        if (isset($this->sessionProcessMap[$sid]) && $this->sessionProcessMap[$sid] === null) {
            unset($this->sessionProcessMap[$sid]);
        }

        return null;
    }

    private function isSessionAvailableAnywhere(string $sid): bool
    {
        if ($this->isSessionLocal($sid)) {
            return true;
        }

        return $this->probeSessionExistence($sid);
    }

    private function probeSessionExistence(string $sid): bool
    {
        $probeId = uniqid('probe_', true);
        $probeData = [
            'sid' => $sid,
            'probe_id' => $probeId,
            'requester' => $this->processId,
            'timestamp' => microtime(true)
        ];

        $this->publishBatch($this->prefix . 'session_probe', $probeData, true);

        $startTime = microtime(true);
        while (microtime(true) - $startTime < 0.1) {
            if (isset($this->sessionProcessMap[$sid])) {
                return true;
            }
            usleep(10000);
        }

        return false;
    }

    private function handleDirectSend(string $sid, array $packet): void
    {
        $sessionClass = '\PhpSocketIO\Session';
        if (!class_exists($sessionClass)) {
            throw new \RuntimeException('Session class not found');
        }

        $session = $sessionClass::get($sid);
        if ($session) {
            if (isset($packet['type'])) {
                $sessionClass::sendToSession($sid, $packet);
            } else {
                if (isset($packet['data'])) {
                    $session->send($packet['data']);
                }
            }
        }
    }

    private function forwardToProcess(int $targetProcess, string $sid, array $packet): void
    {
        if (!$this->validateSocketIOV4Packet($packet)) {
            return;
        }

        $data = [
            'target_sid' => $sid,
            'packet' => $packet,
            'sender' => $this->processId,
            'target_process' => $targetProcess,
            'timestamp' => microtime(true)
        ];

        $this->publishBatch($this->prefix . 'send', $data);
    }

    private function validateSocketIOV4Packet(array $packet): bool
    {
        if (!isset($packet['type'])) {
            return false;
        }

        if ($packet['type'] === 2) {
            if (!isset($packet['event']) || !is_string($packet['event'])) {
                return false;
            }

            if (isset($packet['data']) && !is_array($packet['data'])) {
                return false;
            }
        }

        if ($packet['type'] === 3) {
            if (!isset($packet['id']) || !is_numeric($packet['id'])) {
                return false;
            }
        }

        if ($packet['type'] === 0 || $packet['type'] === 1 || $packet['type'] === 6) {
            return true;
        }

        if ($packet['type'] === 4) {
            if (!isset($packet['data']) || !is_array($packet['data'])) {
                return false;
            }
        }

        if ($packet['type'] === 5) {
            if (!isset($packet['event']) || !is_string($packet['event'])) {
                return false;
            }
        }

        if ($packet['type'] === 6) {
            if (!isset($packet['id']) || !is_numeric($packet['id'])) {
                return false;
            }
        }

        if (!in_array($packet['type'], [0, 1, 2, 3, 4, 5, 6])) {
            return false;
        }

        return true;
    }

    private function handleSendMessage(array $data): void
    {
        if ($data['target_process'] !== $this->processId) {
            return;
        }

        $sid = $data['target_sid'];
        $packet = $data['packet'];

        $this->handleDirectSend($sid, $packet);
    }

    public function register(string $sid): void
    {
        $this->sessionProcessMap[$sid] = $this->processId;

        $channelClientClass = $this->getChannelClientClass();
        $channelClientClass::publish($this->prefix . 'session_register', [
            'sid' => $sid,
            'process_id' => $this->processId,
            'timestamp' => microtime(true)
        ]);
    }

    public function unregister(string $sid): void
    {
        if (isset($this->sessionProcessMap[$sid])) {
            unset($this->sessionProcessMap[$sid]);
        }
    }

    private function handleSessionRegister(array $data): void
    {
        $sid = $data['sid'];
        $processId = $data['process_id'];

        if ($processId !== $this->processId) {
            $this->sessionProcessMap[$sid] = $processId;
        }
    }

    private function handleSessionQuery(array $data): void
    {
        $sid = $data['sid'];
        $requester = $data['requester'];

        if ($requester === $this->processId) {
            return;
        }

        if ($this->isSessionLocal($sid)) {
            $channelClientClass = $this->getChannelClientClass();
            $channelClientClass::publish($this->prefix . 'session_response', [
                'sid' => $sid,
                'query_id' => $data['query_id'],
                'responder' => $this->processId,
                'process_id' => $this->processId,
                'timestamp' => microtime(true)
            ]);
        }
    }

    private function handleSessionResponse(array $data): void
    {
        $sid = $data['sid'];
        $processId = $data['process_id'];
        $responder = $data['responder'] ?? $processId;

        if ($responder === $this->processId) {
            return;
        }

        if (!isset($this->sessionProcessMap[$sid]) || $this->sessionProcessMap[$sid] !== null) {
            return;
        }

        $this->sessionProcessMap[$sid] = [
            'processId' => $processId,
            'responder' => $responder,
            'queryId' => $data['query_id'],
            'responseTime' => microtime(true)
        ];
    }

    private function handleSessionProbe(array $data): void
    {
        $sid = $data['sid'];
        $requester = $data['requester'];

        if ($requester === $this->processId) {
            return;
        }

        if ($this->isSessionLocal($sid)) {
            $channelClientClass = $this->getChannelClientClass();
            $channelClientClass::publish($this->prefix . 'session_response', [
                'sid' => $sid,
                'query_id' => $data['probe_id'],
                'process_id' => $this->processId,
                'timestamp' => microtime(true)
            ]);
        }
    }

    private function startSessionMappingHeartbeat(): void
    {
        \Workerman\Timer::add(30, function() {
            if (empty($this->sessionProcessMap)) {
                return;
            }

            $channelClientClass = $this->getChannelClientClass();
            $channelClientClass::publish($this->prefix . 'session_heartbeat', [
                'process_id' => $this->processId,
                'sessions' => array_keys($this->sessionProcessMap),
                'timestamp' => microtime(true)
            ]);
        });
    }

    private function startSessionCleanupTimer(): void
    {
        \Workerman\Timer::add(60, function() {
            $currentTime = microtime(true);
            $expirationTime = $currentTime - 120;
            $cleanupCount = 0;

            foreach ($this->sessionProcessMap as $sid => $sessionInfo) {
                if (is_array($sessionInfo) && isset($sessionInfo['responseTime'])) {
                    if ($sessionInfo['responseTime'] < $expirationTime) {
                        unset($this->sessionProcessMap[$sid]);
                        $cleanupCount++;
                    }
                } elseif ($sessionInfo === null) {
                    unset($this->sessionProcessMap[$sid]);
                    $cleanupCount++;
                }
            }

            $sessionClass = '\PhpSocketIO\Session';
            if (class_exists($sessionClass)) {
                foreach (array_keys($this->sessionProcessMap) as $sid) {
                    if (!$sessionClass::get($sid)) {
                        unset($this->sessionProcessMap[$sid]);
                        $cleanupCount++;
                    }
                }
            }
        });
    }

    public function close(): void
    {
        $this->initialized = false;
    }

    public function getStats(): array
    {
        return [
            'rooms_count' => count($this->rooms),
            'sessions_count' => count($this->sids),
            'initialized' => $this->initialized,
            'prefix' => $this->prefix,
            'message_queue_size' => count($this->messageQueue),
            'batch_timer_started' => $this->batchTimerStarted
        ];
    }

    private function publishBatch(string $channel, array $data, bool $urgent = false): void
    {
        $channelClientClass = $this->getChannelClientClass();
        if ($urgent || strpos($channel, 'heartbeat') !== false || strpos($channel, 'session_') !== false) {
            try {
                $channelClientClass::publish($channel, $data);
                return;
            } catch (\Exception $e) {
                $this->initChannelClient();
                try {
                    $channelClientClass::publish($channel, $data);
                } catch (\Exception $e2) {
                }
                return;
            }
        }

        $this->messageQueue[] = [
            'channel' => $channel,
            'data' => $data,
            'timestamp' => microtime(true)
        ];

        if (count($this->messageQueue) >= $this->maxBatchSize) {
            $this->flushMessageQueue();
        } else if (!$this->batchTimerStarted) {
            $this->startBatchTimer();
        }
    }

    private function startBatchTimer(): void
    {
        if ($this->batchTimerStarted) {
            return;
        }

        $this->batchTimerStarted = true;
        \Workerman\Timer::add($this->batchInterval, function($timerId) {
            if (empty($this->messageQueue)) {
                $this->batchTimerStarted = false;
                \Workerman\Timer::del($timerId);
                return;
            }

            $this->flushMessageQueue();

            if (empty($this->messageQueue)) {
                $this->batchTimerStarted = false;
                \Workerman\Timer::del($timerId);
            }
        });
    }

    private function flushMessageQueue(): void
    {
        if (empty($this->messageQueue)) {
            return;
        }

        $messages = array_splice($this->messageQueue, 0, $this->maxBatchSize);
        $channelClientClass = $this->getChannelClientClass();

        try {
            foreach ($messages as $message) {
                $channelClientClass::publish($message['channel'], $message['data']);
            }
        } catch (\Exception $e) {
            $this->initChannelClient();
            $this->messageQueue = array_merge($messages, $this->messageQueue);
        }
    }
}
