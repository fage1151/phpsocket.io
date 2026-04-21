<?php

declare(strict_types=1);

namespace PhpSocketIO;

final class PacketParser
{
    private const ENGINE_PACKET_TYPES = [
        0 => 'open',
        1 => 'close',
        2 => 'ping',
        3 => 'pong',
        4 => 'message',
        5 => 'upgrade',
        6 => 'noop'
    ];

    private const SOCKET_PACKET_TYPES = [
        0 => 'CONNECT',
        1 => 'DISCONNECT',
        2 => 'EVENT',
        3 => 'ACK',
        4 => 'CONNECT_ERROR',
        5 => 'BINARY_EVENT',
        6 => 'BINARY_ACK'
    ];

    private static ?array $engineTypeMap = null;
    private static ?array $socketTypeMap = null;

    public static function parseEngineIOPacket(string $data): ?array
    {
        if (empty($data)) {
            return ['type' => 'noop', 'data' => ''];
        }

        if ($data[0] === 'b') {
            return ['type' => 'binary', 'data' => substr($data, 1)];
        }

        if (!ctype_digit($data[0])) {
            return null;
        }

        $type = (int)$data[0];
        if (!isset(self::ENGINE_PACKET_TYPES[$type])) {
            return null;
        }

        $payload = substr($data, 1);
        $packetType = self::ENGINE_PACKET_TYPES[$type];

        if ($packetType === 'message' && !empty($payload) && $payload[0] === '{') {
            $jsonData = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return ['type' => $packetType, 'data' => $jsonData];
            }
        }

        return ['type' => $packetType, 'data' => $payload];
    }

    public static function parseSocketIOPacket(mixed $data): ?array
    {
        if ($data === null || $data === '' || $data === false) {
            return null;
        }

        $decoded = is_array($data) ? $data : self::decodeStringData($data);

        if (!is_array($decoded) || (!isset($decoded[0]) && !isset($decoded['type']))) {
            return null;
        }

        try {
            $type = isset($decoded['type']) ? (int)$decoded['type'] : (int)$decoded[0];
            if (!isset(self::SOCKET_PACKET_TYPES[$type])) {
                return null;
            }

            $packet = ['type' => self::SOCKET_PACKET_TYPES[$type]];
            return self::extractPacketFields($packet, $decoded);
        } catch (\Exception) {
            return null;
        }
    }

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

    private static function decodeStandardFormat(array $matches): array
    {
        $result = ['type' => (int)$matches[1]];

        if (isset($matches[2]) && $matches[2] !== '') {
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

    private static function decodeJsonFormat(array $matches): array
    {
        $result = ['type' => (int)$matches[1]];
        $jsonStr = trim($matches[2]);

        if (empty($jsonStr)) {
            $result['data'] = [];
        } else {
            $jsonData = json_decode('[' . $jsonStr . ']', true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                if (is_string($jsonData[0] ?? '') && strpos($jsonData[0], '/') === 0) {
                    $result['namespace'] = $jsonData[0];
                    $result['data'] = array_slice($jsonData, 1);
                } else {
                    $result['data'] = $jsonData;
                }
            }
        }

        return $result;
    }

    private static function decodeSimpleFormat(array $matches): array
    {
        $result = ['type' => (int)$matches[1]];

        if (!empty($matches[2])) {
            $result['binaryCount'] = (int)$matches[2];
        }

        if (!empty($matches[3])) {
            list($namespace, $ackId, $jsonData) = self::parseNamespaceAckAndJson($matches[3]);
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

    private static function extractPacketFields(array $packet, array $decoded): array
    {
        if (isset($decoded['type'])) {
            return self::extractFromAssocArray($packet, $decoded);
        }

        return self::extractFromIndexArray($packet, $decoded);
    }

    private static function extractFromAssocArray(array $packet, array $decoded): array
    {
        $packet['namespace'] = $decoded['namespace'] ?? '/';

        if (isset($decoded['ackId'])) {
            $packet['id'] = $decoded['ackId'];
        }

        if (isset($decoded['binaryCount'])) {
            $packet['binaryCount'] = $decoded['binaryCount'];
        }

        return match ($packet['type']) {
            'CONNECT' => self::extractConnectData($packet, $decoded),
            'EVENT', 'BINARY_EVENT' => self::extractEventPacketData($packet, $decoded),
            'ACK', 'BINARY_ACK' => self::extractAckPacketData($packet, $decoded),
            'CONNECT_ERROR' => self::extractErrorPacketData($packet, $decoded),
            default => $packet,
        };
    }

    private static function extractConnectData(array $packet, array $decoded): array
    {
        if (isset($decoded['data'])) {
            $packet['auth'] = $decoded['data'];
        }
        return $packet;
    }

    private static function extractEventPacketData(array $packet, array $decoded): array
    {
        self::extractEventData($packet, $decoded);
        return $packet;
    }

    private static function extractAckPacketData(array $packet, array $decoded): array
    {
        $packet['data'] = self::normalizeData($decoded['data'] ?? []);
        return $packet;
    }

    private static function extractErrorPacketData(array $packet, array $decoded): array
    {
        $packet['error'] = $decoded['data'] ?? 'Unknown error';
        return $packet;
    }

    private static function extractFromIndexArray(array $packet, array $decoded): array
    {
        $packet['namespace'] = '/';

        foreach ($decoded as $i => $value) {
            if ($i === 0) continue;

            if (is_string($value) && $value[0] === '/') {
                $packet['namespace'] = $value;
            } elseif (is_numeric($value) && !isset($packet['id'])) {
                $packet['id'] = (int)$value;
            } elseif (!isset($packet['data'])) {
                $packet['data'] = $value;
            }
        }

        if ($packet['type'] === 'EVENT' || $packet['type'] === 'BINARY_EVENT') {
            self::extractEventData($packet, $decoded);
        }

        return $packet;
    }

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

    private static function normalizeData(mixed $data): array
    {
        return is_array($data) ? $data : [$data];
    }

    public static function buildEngineIOPacket(string $type, mixed $data = ''): string
    {
        if (self::$engineTypeMap === null) {
            self::$engineTypeMap = array_flip(self::ENGINE_PACKET_TYPES);
        }

        $typeCode = self::$engineTypeMap[$type] ?? 4;

        if (is_array($data) || is_object($data)) {
            $data = json_encode($data);
        }

        return $typeCode . $data;
    }

    public static function buildSocketIOPacket(string $type, array $params = []): string
    {
        if (self::$socketTypeMap === null) {
            self::$socketTypeMap = array_flip(self::SOCKET_PACKET_TYPES);
        }

        $typeCode = self::$socketTypeMap[$type] ?? 2;
        $packet = (string)$typeCode;
        $namespace = $params['namespace'] ?? '/';
        
        if (($type === 'BINARY_EVENT' || $type === 'BINARY_ACK') && isset($params['binaryCount'])) {
            $packet .= $params['binaryCount'];
        }

        if ($namespace !== '/' && $type !== 'DISCONNECT') {
            if (($type === 'BINARY_EVENT' || $type === 'BINARY_ACK') && isset($params['binaryCount'])) {
                $packet .= '-';
            }
            $packet .= $namespace . ',';
        } elseif ($namespace !== '/' && $type === 'DISCONNECT') {
            $packet .= $namespace;
        }

        return self::buildPacketByType($packet, $type, $params);
    }

    private static function buildPacketByType(string $packet, string $type, array $params): string
    {
        return match ($type) {
            'CONNECT' => self::buildConnectPacket($packet, $params),
            'EVENT', 'BINARY_EVENT' => self::buildEventPacket($packet, $params),
            'ACK', 'BINARY_ACK' => self::buildAckPacket($packet, $params),
            'CONNECT_ERROR' => self::buildErrorPacket($packet, $params),
            default => $packet,
        };
    }

    private static function buildConnectPacket(string $packet, array $params): string
    {
        if (isset($params['data'])) {
            $packet .= json_encode($params['data']);
        }
        return $packet;
    }

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

    private static function buildErrorPacket(string $packet, array $params): string
    {
        $packet .= json_encode(['message' => $params['error'] ?? 'Unknown error']);
        return $packet;
    }

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

    public static function validatePacket(mixed $packet): bool
    {
        if (!is_array($packet) || !isset($packet['type'])) {
            return false;
        }

        $validTypes = array_merge(
            self::ENGINE_PACKET_TYPES,
            self::SOCKET_PACKET_TYPES,
            ['binary' => 'binary']
        );

        return isset($validTypes[$packet['type']]) || in_array($packet['type'], $validTypes);
    }

    public static function isHeartbeat(string $data): bool
    {
        return $data === '2' || $data === '3';
    }

    public static function buildHandshakePacket(array $sessionInfo): string
    {
        $handshake = [
            'sid' => $sessionInfo['sid'],
            'upgrades' => ['websocket'],
            'pingInterval' => $sessionInfo['pingInterval'] ?? 25000,
            'pingTimeout' => $sessionInfo['pingTimeout'] ?? 5000
        ];

        return self::buildEngineIOPacket('open', $handshake);
    }

    private static function parseNamespaceAckAndJson(string $rest): array
    {
        $namespace = '/';
        $ackId = null;
        $jsonData = null;

        if (preg_match('/^\/([^,]+)\,?(\d+)?(\[.*\])?$/', $rest, $matches)) {
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

    public static function replaceBinaryPlaceholders(array $packet, array $binaryAttachments): array
    {
        if (empty($binaryAttachments) || !isset($packet['data'])) {
            return $packet;
        }

        $packet['data'] = self::doReplaceBinaryPlaceholders($packet['data'], $binaryAttachments);

        return $packet;
    }

    private static function doReplaceBinaryPlaceholders(mixed $data, array $binaryAttachments): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        if (isset($data['_placeholder'], $data['num']) &&
            $data['_placeholder'] === true &&
            isset($binaryAttachments[$data['num']])) {
            return $binaryAttachments[$data['num']];
        }

        foreach ($data as $key => $value) {
            $data[$key] = self::doReplaceBinaryPlaceholders($value, $binaryAttachments);
        }

        return $data;
    }
}
