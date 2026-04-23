<?php

declare(strict_types=1);

namespace PhpSocketIO;

/**
 * Engine.IO 数据包类型枚举
 */
enum EnginePacketType: int
{
    case OPEN = 0;
    case CLOSE = 1;
    case PING = 2;
    case PONG = 3;
    case MESSAGE = 4;
    case UPGRADE = 5;
    case NOOP = 6;

    public static function tryFromInt(int $value): ?self
    {
        return self::tryFrom($value);
    }
}

/**
 * Socket.IO 数据包类型枚举
 */
enum SocketPacketType: int
{
    case CONNECT = 0;
    case DISCONNECT = 1;
    case EVENT = 2;
    case ACK = 3;
    case CONNECT_ERROR = 4;
    case BINARY_EVENT = 5;
    case BINARY_ACK = 6;

    public static function tryFromInt(int $value): ?self
    {
        return self::tryFrom($value);
    }
}

/**
 * Socket.IO 数据包解析器
 *
 * 提供数据包解析和构建功能
 *
 * @package PhpSocketIO
 */
final class PacketParser
{
    private const CACHE_MAX = 100;

    /** @var array<string, string> 缓存 */
    private static array $packetCache = [];

    /** @var array<string, int> 缓存访问时间 */
    private static array $cacheAccess = [];

    /**
     * 解析 Engine.IO 数据包
     *
     * @param string $data 原始数据
     * @return array{type: string, data: mixed}|null
     */
    public static function parseEngineIOPacket(string $data): ?array
    {
        if ($data === '') {
            return ['type' => 'noop', 'data' => ''];
        }

        if ($data[0] === 'b') {
            return ['type' => 'binary', 'data' => substr($data, 1)];
        }

        if (!ctype_digit($data[0])) {
            return null;
        }

        $typeValue = (int)$data[0];
        $type = EnginePacketType::tryFromInt($typeValue);

        if ($type === null) {
            return null;
        }

        $payload = substr($data, 1);
        $packetType = $type->name;

        if ($type === EnginePacketType::MESSAGE && $payload !== '' && $payload[0] === '{') {
            $jsonData = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return ['type' => $packetType, 'data' => $jsonData];
            }
        }

        return ['type' => $packetType, 'data' => $payload];
    }

    /**
     * 解析 Socket.IO 数据包
     *
     * @param mixed $data 原始数据
     * @return array|null
     */
    public static function parseSocketIOPacket(mixed $data): ?array
    {
        if ($data === null || $data === '' || $data === false) {
            return null;
        }

        $decoded = is_array($data) ? $data : self::decodeStringData((string)$data);

        if (!is_array($decoded) || (!isset($decoded[0]) && !isset($decoded['type']))) {
            return null;
        }

        try {
            $typeValue = isset($decoded['type']) ? (int)$decoded['type'] : (int)$decoded[0];
            $type = SocketPacketType::tryFromInt($typeValue);

            if ($type === null) {
                return null;
            }

            $packet = ['type' => $type->name];
            return self::extractPacketFields($packet, $decoded);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * 解码字符串格式的数据
     *
     * @param string $data 字符串数据
     * @return array|null
     */
    private static function decodeStringData(string $data): ?array
    {
        if ($data[0] === 'b') {
            return ['type' => 'binary', 'data' => $data];
        }

        if (preg_match('/^4(\d)(\d*)-?\/?([^,]+)?,?(\d+)?(\[.*\])?$/', $data, $matches)) {
            return self::decodeStandardFormat($matches);
        }

        if (preg_match('/^(\d)\[(.*)\]$/', $data, $matches)) {
            return self::decodeJsonFormat($matches);
        }

        if (preg_match('/^(\d)(\d*)-?(.*)$/', $data, $matches)) {
            return self::decodeSimpleFormat($matches);
        }

        return null;
    }

    /**
     * 解码标准格式
     *
     * @param array $matches 匹配结果
     * @return array
     */
    private static function decodeStandardFormat(array $matches): array
    {
        $result = ['type' => (int)$matches[1]];

        if ($matches[2] !== '') {
            $result['binaryCount'] = (int)$matches[2];
        }

        if (isset($matches[3]) && $matches[3] !== '') {
            $result['namespace'] = '/' . $matches[3];
        }

        if (isset($matches[4]) && $matches[4] !== '') {
            $result['ackId'] = (int)$matches[4];
        }

        if (isset($matches[5]) && $matches[5] !== '') {
            $jsonData = json_decode($matches[5], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $result['data'] = $jsonData;
            }
        }

        return $result;
    }

    /**
     * 解码JSON格式
     *
     * @param array $matches 匹配结果
     * @return array
     */
    private static function decodeJsonFormat(array $matches): array
    {
        $result = ['type' => (int)$matches[1]];
        $jsonStr = trim($matches[2]);

        if ($jsonStr === '') {
            $result['data'] = [];
        } else {
            $jsonData = json_decode('[' . $jsonStr . ']', true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                if (is_string($jsonData[0] ?? '') && str_starts_with($jsonData[0], '/')) {
                    $result['namespace'] = $jsonData[0];
                    $result['data'] = array_slice($jsonData, 1);
                } else {
                    $result['data'] = $jsonData;
                }
            }
        }

        return $result;
    }

    /**
     * 解码简单格式
     *
     * @param array $matches 匹配结果
     * @return array
     */
    private static function decodeSimpleFormat(array $matches): array
    {
        $result = ['type' => (int)$matches[1]];

        if ($matches[2] !== '') {
            $result['binaryCount'] = (int)$matches[2];
        }

        if (isset($matches[3]) && $matches[3] !== '') {
            [$namespace, $ackId, $jsonData] = self::parseNamespaceAckAndJson($matches[3]);
            $result['namespace'] = $namespace;
            if ($ackId !== null) {
                $result['ackId'] = $ackId;
            }
            if ($jsonData !== null) {
                $result['data'] = $jsonData;
            }
        }

        return $result;
    }

    /**
     * 提取数据包字段
     *
     * @param array $packet 数据包
     * @param array $decoded 解码后的数据
     * @return array
     */
    private static function extractPacketFields(array $packet, array $decoded): array
    {
        if (isset($decoded['type'])) {
            return self::extractFromAssocArray($packet, $decoded);
        }

        return self::extractFromIndexArray($packet, $decoded);
    }

    /**
     * 从关联数组提取数据
     *
     * @param array $packet 数据包
     * @param array $decoded 解码后的数据
     * @return array
     */
    private static function extractFromAssocArray(array $packet, array $decoded): array
    {
        $packet['namespace'] = $decoded['namespace'] ?? '/';

        if (isset($decoded['ackId'])) {
            $packet['id'] = $decoded['ackId'];
        }

        if (isset($decoded['binaryCount'])) {
            $packet['binaryCount'] = $decoded['binaryCount'];
        }

        $type = SocketPacketType::tryFrom((int)($decoded['type'] ?? $decoded[0] ?? 0));

        return match ($type) {
            SocketPacketType::CONNECT => self::extractConnectData($packet, $decoded),
            SocketPacketType::EVENT, SocketPacketType::BINARY_EVENT => self::extractEventPacketData($packet, $decoded),
            SocketPacketType::ACK, SocketPacketType::BINARY_ACK => self::extractAckPacketData($packet, $decoded),
            SocketPacketType::CONNECT_ERROR => self::extractErrorPacketData($packet, $decoded),
            default => $packet,
        };
    }

    /**
     * 提取连接数据
     *
     * @param array $packet 数据包
     * @param array $decoded 解码后的数据
     * @return array
     */
    private static function extractConnectData(array $packet, array $decoded): array
    {
        if (isset($decoded['data'])) {
            $packet['auth'] = $decoded['data'];
        }
        return $packet;
    }

    /**
     * 提取事件数据包数据
     *
     * @param array $packet 数据包
     * @param array $decoded 解码后的数据
     * @return array
     */
    private static function extractEventPacketData(array $packet, array $decoded): array
    {
        self::extractEventData($packet, $decoded);
        return $packet;
    }

    /**
     * 提取ACK数据包数据
     *
     * @param array $packet 数据包
     * @param array $decoded 解码后的数据
     * @return array
     */
    private static function extractAckPacketData(array $packet, array $decoded): array
    {
        $packet['data'] = self::normalizeData($decoded['data'] ?? []);
        return $packet;
    }

    /**
     * 提取错误数据包数据
     *
     * @param array $packet 数据包
     * @param array $decoded 解码后的数据
     * @return array
     */
    private static function extractErrorPacketData(array $packet, array $decoded): array
    {
        $packet['error'] = $decoded['data'] ?? 'Unknown error';
        return $packet;
    }

    /**
     * 从索引数组提取数据
     *
     * @param array $packet 数据包
     * @param array $decoded 解码后的数据
     * @return array
     */
    private static function extractFromIndexArray(array $packet, array $decoded): array
    {
        $packet['namespace'] = '/';

        foreach ($decoded as $i => $value) {
            if ($i === 0) continue;

            if (is_string($value) && str_starts_with($value, '/')) {
                $packet['namespace'] = $value;
            } elseif (is_numeric($value) && !isset($packet['id'])) {
                $packet['id'] = (int)$value;
            } elseif (!isset($packet['data'])) {
                $packet['data'] = $value;
            }
        }

        $type = SocketPacketType::tryFrom((int)($decoded[0] ?? 0));
        if ($type === SocketPacketType::EVENT || $type === SocketPacketType::BINARY_EVENT) {
            self::extractEventData($packet, $decoded);
        }

        return $packet;
    }

    /**
     * 提取事件数据
     *
     * @param array $packet 数据包
     * @param array $decoded 解码后的数据
     * @return void
     */
    private static function extractEventData(array &$packet, array $decoded): void
    {
        $data = $decoded['data'] ?? $decoded[1] ?? [];

        if (is_array($data) && !empty($data)) {
            if (is_string($data[0] ?? '')) {
                $packet['event'] = $data[0];
                $packet['data'] = array_slice($data, 1);
            }
        }

        $packet['event'] ??= 'unknown';
        $packet['data'] ??= [];
    }

    /**
     * 规范化数据为数组
     *
     * @param mixed $data 数据
     * @return array
     */
    private static function normalizeData(mixed $data): array
    {
        return is_array($data) ? $data : [$data];
    }

    /**
     * 构建 Engine.IO 数据包
     *
     * @param string $type 类型
     * @param mixed $data 数据
     * @return string
     */
    public static function buildEngineIOPacket(string $type, mixed $data = ''): string
    {
        $enumType = null;
        $type = strtoupper($type);
        foreach (EnginePacketType::cases() as $case) {
            if ($case->name === $type) {
                $enumType = $case;
                break;
            }
        }
        $typeCode = $enumType !== null ? $enumType->value : EnginePacketType::MESSAGE->value;

        if (is_array($data) || is_object($data)) {
            $data = json_encode($data);
        }

        return $typeCode . $data;
    }

    /**
     * 构建 Socket.IO 数据包
     *
     * @param string $type 类型
     * @param array $params 参数
     * @return string
     */
    public static function buildSocketIOPacket(string $type, array $params = []): string
    {
        $cacheKey = $type . json_encode($params);

        if (isset(self::$packetCache[$cacheKey])) {
            self::$cacheAccess[$cacheKey] = time();
            return self::$packetCache[$cacheKey];
        }

        $enumType = null;
        foreach (SocketPacketType::cases() as $case) {
            if ($case->name === $type) {
                $enumType = $case;
                break;
            }
        }
        $typeCode = $enumType !== null ? $enumType->value : SocketPacketType::EVENT->value;
        $packet = (string)$typeCode;
        $namespace = $params['namespace'] ?? '/';

        if (($enumType === SocketPacketType::BINARY_EVENT || $enumType === SocketPacketType::BINARY_ACK) && isset($params['binaryCount'])) {
            $packet .= $params['binaryCount'];
        }

        if ($namespace !== '/' && $enumType !== SocketPacketType::DISCONNECT) {
            if (($enumType === SocketPacketType::BINARY_EVENT || $enumType === SocketPacketType::BINARY_ACK) && isset($params['binaryCount'])) {
                $packet .= '-';
            }
            $packet .= $namespace . ',';
        } elseif ($namespace !== '/' && $enumType === SocketPacketType::DISCONNECT) {
            $packet .= $namespace;
        }

        $result = self::buildPacketByType($packet, $enumType ?? SocketPacketType::EVENT, $params);

        self::$packetCache[$cacheKey] = $result;
        self::$cacheAccess[$cacheKey] = time();

        if (count(self::$packetCache) > self::CACHE_MAX) {
            self::cleanupCache();
        }

        return $result;
    }

    /**
     * 清理缓存
     *
     * @return void
     */
    private static function cleanupCache(): void
    {
        asort(self::$cacheAccess);
        $sortedKeys = array_keys(self::$cacheAccess);
        $itemsToRemove = count(self::$packetCache) - self::CACHE_MAX;

        for ($i = 0; $i < $itemsToRemove && $i < count($sortedKeys); $i++) {
            $keyToRemove = $sortedKeys[$i];
            unset(self::$packetCache[$keyToRemove], self::$cacheAccess[$keyToRemove]);
        }
    }

    /**
     * 根据类型构建数据包
     *
     * @param string $packet 数据包前缀
     * @param SocketPacketType $type 类型枚举
     * @param array $params 参数
     * @return string
     */
    private static function buildPacketByType(string $packet, SocketPacketType $type, array $params): string
    {
        return match ($type) {
            SocketPacketType::CONNECT => self::buildConnectPacket($packet, $params),
            SocketPacketType::EVENT, SocketPacketType::BINARY_EVENT => self::buildEventPacket($packet, $params),
            SocketPacketType::ACK, SocketPacketType::BINARY_ACK => self::buildAckPacket($packet, $params),
            SocketPacketType::CONNECT_ERROR => self::buildErrorPacket($packet, $params),
            default => $packet,
        };
    }

    /**
     * 构建连接数据包
     *
     * @param string $packet 数据包前缀
     * @param array $params 参数
     * @return string
     */
    private static function buildConnectPacket(string $packet, array $params): string
    {
        if (isset($params['data'])) {
            $packet .= json_encode($params['data']);
        }
        return $packet;
    }

    /**
     * 构建事件数据包
     *
     * @param string $packet 数据包前缀
     * @param array $params 参数
     * @return string
     */
    private static function buildEventPacket(string $packet, array $params): string
    {
        $eventName = $params['event'] ?? 'unknown';
        $data = self::prepareEventData($params);
        if (isset($params['id'])) {
            $packet .= $params['id'];
        }
        $packet .= json_encode(array_merge([$eventName], $data));
        return $packet;
    }

    /**
     * 构建ACK数据包
     *
     * @param string $packet 数据包前缀
     * @param array $params 参数
     * @return string
     */
    private static function buildAckPacket(string $packet, array $params): string
    {
        if (isset($params['id'])) {
            $packet .= $params['id'];
        }
        if (isset($params['data'])) {
            $ackData = $params['data'];
            if (!is_array($ackData) || (count(array_filter(array_keys($ackData), 'is_string')) > 0)) {
                $ackData = [$ackData];
            }
            $packet .= json_encode($ackData);
        } else {
            $packet .= '[]';
        }
        return $packet;
    }

    /**
     * 构建错误数据包
     *
     * @param string $packet 数据包前缀
     * @param array $params 参数
     * @return string
     */
    private static function buildErrorPacket(string $packet, array $params): string
    {
        $packet .= json_encode(['message' => $params['error'] ?? 'Unknown error']);
        return $packet;
    }

    /**
     * 准备事件数据
     *
     * @param array $params 参数
     * @return array
     */
    private static function prepareEventData(array $params): array
    {
        if (isset($params['data'])) {
            return is_array($params['data']) ? $params['data'] : [$params['data']];
        }
        if (isset($params['args'])) {
            return $params['args'];
        }
        return [];
    }

    /**
     * 验证数据包
     *
     * @param mixed $packet 数据包
     * @return bool
     */
    public static function validatePacket(mixed $packet): bool
    {
        if (!is_array($packet) || !isset($packet['type'])) {
            return false;
        }

        $validTypeNames = array_merge(
            array_map(fn($case) => $case->name, EnginePacketType::cases()),
            array_map(fn($case) => $case->name, SocketPacketType::cases()),
            ['binary' => 'binary']
        );

        return isset($validTypeNames[$packet['type']]) || in_array($packet['type'], $validTypeNames);
    }

    /**
     * 检查是否是心跳数据包
     *
     * @param string $data 数据
     * @return bool
     */
    public static function isHeartbeat(string $data): bool
    {
        return $data === '2' || $data === '3';
    }

    /**
     * 构建握手数据包
     *
     * @param array $sessionInfo 会话信息
     * @return string
     */
    public static function buildHandshakePacket(array $sessionInfo): string
    {
        $handshake = [
            'sid' => $sessionInfo['sid'],
            'upgrades' => ['websocket'],
            'pingInterval' => $sessionInfo['pingInterval'] ?? 25000,
            'pingTimeout' => $sessionInfo['pingTimeout'] ?? 5000
        ];

        return self::buildEngineIOPacket('OPEN', $handshake);
    }

    /**
     * 解析命名空间、ACK ID和JSON数据
     *
     * @param string $rest 剩余字符串
     * @return array{0: string, 1: int|null, 2: array|null}
     */
    private static function parseNamespaceAckAndJson(string $rest): array
    {
        $namespace = '/';
        $ackId = null;
        $jsonData = null;

        if (preg_match('/^\/([^,]+)(?:\,)?(\d+)?(\[.*\])?(?:\,)?$/', $rest, $matches)) {
            $namespace = '/' . $matches[1];
            if (isset($matches[2]) && $matches[2] !== '') {
                $ackId = (int)$matches[2];
            }
            if (isset($matches[3]) && $matches[3] !== '') {
                $jsonData = json_decode($matches[3], true);
            }
        } elseif (preg_match('/^(\[.*\])$/', $rest, $matches)) {
            $jsonData = json_decode($matches[1], true);
        } elseif (preg_match('/^(\d+)(\[.*\])$/', $rest, $matches)) {
            $ackId = (int)$matches[1];
            $jsonData = json_decode($matches[2], true);
        }

        return [$namespace, $ackId, $jsonData];
    }

    /**
     * 替换二进制占位符
     *
     * @param array $packet 数据包
     * @param array $binaryAttachments 二进制附件
     * @return array
     */
    public static function replaceBinaryPlaceholders(array $packet, array $binaryAttachments): array
    {
        if (empty($binaryAttachments) || !isset($packet['data'])) {
            return $packet;
        }

        $packet['data'] = self::doReplaceBinaryPlaceholders($packet['data'], $binaryAttachments);

        return $packet;
    }

    /**
     * 递归替换二进制占位符
     *
     * @param mixed $data 数据
     * @param array $binaryAttachments 二进制附件
     * @return mixed
     */
    private static function doReplaceBinaryPlaceholders(mixed $data, array $binaryAttachments): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        if (isset($data['_placeholder'], $data['num'])
            && $data['_placeholder'] === true
            && isset($binaryAttachments[$data['num']])) {
            return $binaryAttachments[$data['num']];
        }

        foreach ($data as $key => $value) {
            $data[$key] = self::doReplaceBinaryPlaceholders($value, $binaryAttachments);
        }

        return $data;
    }
}
