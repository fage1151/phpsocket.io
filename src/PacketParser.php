<?php

declare(strict_types=1);

namespace PhpSocketIO;

/**
 * Socket.IO 数据包解析器
 * 负责Engine.IO和Socket.IO协议的封包和解包操作
 * @package SocketIO
 */
class PacketParser
{
    /**
     * Engine.IO 数据包类型常量
     * @const array
     */
    const ENGINE_PACKET_TYPES = [
        0  => 'open',      // 握手响应
        1  => 'close',     // 关闭连接
        2  => 'ping',      // 心跳请求
        3  => 'pong',      // 心跳响应
        4  => 'message',   // 普通消息
        5  => 'upgrade',   // 协议升级
        6  => 'noop'       // 空操作
    ];

    /**
     * Socket.IO v4 协议包类型常量 - 完整官方定义
     * 协议规范: https://socket.io/docs/v4/socket-io-protocol/
     * @const array
     */
    const SOCKET_PACKET_TYPES = [
        0 => 'CONNECT',        // 客户端 → 服务端：连接命名空间
        1 => 'DISCONNECT',     // 客户端 → 服务端：断开命名空间连接
        2 => 'EVENT',          // 双向：普通事件传输
        3 => 'ACK',            // 双向：事件确认响应
        4 => 'CONNECT_ERROR',  // 服务端 → 客户端：连接错误（Socket.IO v4新增）
        5 => 'BINARY_EVENT',   // 双向：二进制事件传输
        6 => 'BINARY_ACK'      // 双向：二进制事件确认
    ];

    /**
     * 解析Engine.IO数据包
     * @param string $data 原始数据
     * @return array|null 解析后的数据包，解析失败返回null
     */
    public static function parseEngineIOPacket($data)
    {
        // 空数据检查
        if (empty($data)) {
            return ['type' => 'noop', 'data' => ''];
        }
        
        // 二进制数据包识别（'b'前缀）
        if ($data[0] === 'b') {
            return ['type' => 'binary', 'data' => substr($data, 1)];
        }
        
        // 文本消息解析
        $typeChar = $data[0];
        $payload = substr($data, 1);
        
        // 类型转换和验证
        $type = ctype_digit($typeChar) ? (int)$typeChar : -1;
        
        if (!isset(self::ENGINE_PACKET_TYPES[$type])) {
            return null;
        }
        
        $packetType = self::ENGINE_PACKET_TYPES[$type];
        
        // 特殊处理JSON数据
        if ($packetType === 'message' && !empty($payload) && $payload[0] === '{') {
            try {
                $jsonData = json_decode($payload, true);
                if ($jsonData !== null) {
                    return ['type' => $packetType, 'data' => $jsonData];
                }
            } catch (Exception $e) {
                // 静默处理
            }
        }
        
        return ['type' => $packetType, 'data' => $payload];
    }

    /**
     * 解析Socket.IO数据包
     * @param string $data Socket.IO协议数据
     * @return array|null 解析后的数据包
     */
    /**
     * Socket.IO v4协议解析
     * 支持一种格式：
     * 文本格式：type[n-][namespace,][ackId][payload]
     * 协议规范：https://socket.io/docs/v4/socket-io-protocol/
     */
    
    /**
     * 从解码的数据中提取Socket.IO数据包字段
     * @param array $packet 数据包容器
     * @param array $decoded 解码后的数据
     * @return array 完整的数据包
     */
    private static function extractPacketData(array $packet, array $decoded)
    {
        // 检查是否为关联数组格式
        if (isset($decoded['type'])) {
            // 处理关联数组格式
            switch ($packet['type']) {
                case 'CONNECT':
                    $packet['namespace'] = $decoded['namespace'] ?? '/';
                    if (isset($decoded['data'])) {
                        $packet['auth'] = $decoded['data'];
                    }
                    break;
                    
                case 'DISCONNECT':
                    $packet['namespace'] = $decoded['namespace'] ?? '/';
                    break;
                    
                case 'EVENT':
                case 'BINARY_EVENT':
                    $packet['namespace'] = $decoded['namespace'] ?? '/';
                    
                    // 处理 data 可能是字符串 JSON 的情况
                    $eventData = $decoded['data'];
                    if (is_string($eventData) && !empty($eventData)) {
                        $eventData = json_decode($eventData, true);
                    }
                    
                    if (is_array($eventData) && !empty($eventData)) {
                        $packet['event'] = $eventData[0] ?? 'unknown';
                        $packet['data'] = array_slice($eventData, 1) ?? [];
                    } else {
                        $packet['event'] = 'unknown';
                        $packet['data'] = [];
                    }
                    
                    if (isset($decoded['ackId'])) { $packet['id'] = $decoded['ackId']; }
                    if (isset($decoded['binaryCount'])) { $packet['binaryCount'] = $decoded['binaryCount']; }
                    break;
                    
                case 'ACK':
                case 'BINARY_ACK':
                    $packet['namespace'] = $decoded['namespace'] ?? '/';
                    
                    // 处理 data 可能是字符串 JSON 的情况
                    $ackData = $decoded['data'];
                    if (is_string($ackData) && !empty($ackData)) {
                        $ackData = json_decode($ackData, true);
                    }
                    
                    $packet['data'] = is_array($ackData) ? $ackData : [$ackData];
                    
                    if (isset($decoded['ackId'])) { $packet['id'] = $decoded['ackId']; }
                    break;
                    
                case 'CONNECT_ERROR':
                    $packet['data'] = $decoded['data'] ?? [];
                    break;
                    
                case 'MESSAGE':
                    $packet['data'] = $decoded['data'] ?? '';
                    break;
            }
        } else {
            // 处理旧的索引数组格式（向后兼容）
            switch ($packet['type']) {
                case 'CONNECT':
                    $packet['namespace'] = $decoded[1] ?? '/';
                    if (isset($decoded[2])) { $packet['auth'] = $decoded[2]; }
                    break;
                    
                case 'DISCONNECT':
                    $packet['namespace'] = $decoded[1] ?? '/';
                    break;
                    
                case 'EVENT':
                case 'BINARY_EVENT':
                    self::extractEventPacketData($packet, $decoded);
                    break;
                    
                case 'ACK':
                case 'BINARY_ACK':
                    self::extractAckPacketData($packet, $decoded);
                    break;
                    
                case 'CONNECT_ERROR':
                    self::extractConnectErrorPacketData($packet, $decoded);
                    break;
            }
        }
        
        return $packet;
    }
    
    /**
     * 提取事件数据包数据
     */
    private static function extractEventPacketData(array &$packet, array $decoded): void
    {
        // 处理二进制附件数量
        if (isset($decoded['binaryCount'])) { $packet['binaryCount'] = $decoded['binaryCount']; }
        
        // 检查是否有事件数据
        if (!isset($decoded[1]) || !is_string($decoded[1])) {
            self::handleLegacyEventFormat($packet, $decoded);
            return;
        }
        
        // 检查第一个元素是否为命名空间（格式如"/chat"）
        if (strpos($decoded[1], '/') === 0 && strlen($decoded[1]) > 1) {
            self::handleNamespaceEventFormat($packet, $decoded);
            return;
        }
        
        // 格式1：[type, event_name, data_args..., namespace?, ack_id?]
        self::handleStandardEventFormat($packet, $decoded);
    }
    
    /**
     * 处理命名空间格式的事件数据包
     */
    private static function handleNamespaceEventFormat(array &$packet, array $decoded): void
    {
        // 格式2：[type, namespace, event_name, data_args...]
        $packet['namespace'] = $decoded[1];
        
        // 检查事件名：可能是字符串，也可能是数组 [eventName, data...]
        if (!isset($decoded[2])) {
            $packet['event'] = 'unknown';
            $packet['data'] = [];
            return;
        }
        
        if (is_array($decoded[2])) {
            // 格式是：[type, namespace, [eventName, data...]]
            $eventArray = $decoded[2];
            $packet['event'] = isset($eventArray[0]) && is_string($eventArray[0]) ? $eventArray[0] : 'unknown';
            $packet['data'] = array_slice($eventArray, 1);
        } elseif (is_string($decoded[2])) {
            // 格式是：[type, namespace, eventName, data1, data2...]
            $packet['event'] = $decoded[2];
            // 提取从索引3开始的所有元素作为data
            $packet['data'] = array_slice($decoded, 3);
        } else {
            $packet['event'] = 'unknown';
            $packet['data'] = [];
            return;
        }
    }
    
    /**
     * 处理标准格式的事件数据包
     */
    private static function handleStandardEventFormat(array &$packet, array $decoded): void
    {
        // 检查 $decoded[1] 是字符串还是数组
        if (is_array($decoded[1])) {
            // 格式：[type, [eventName, data...]]
            $eventArray = $decoded[1];
            $packet['event'] = isset($eventArray[0]) && is_string($eventArray[0]) ? $eventArray[0] : 'unknown';
            $packet['data'] = array_slice($eventArray, 1);
            $packet['namespace'] = '/'; // 默认命名空间
        } elseif (is_string($decoded[1])) {
            // 格式：[type, eventName, data1, data2...]
            $packet['event'] = $decoded[1];
            $packet['namespace'] = '/'; // 默认命名空间
            $totalElements = count($decoded);
            $dataStart = 2;
            $dataEnd = $totalElements;
            
            // 快速查找命名空间 - 优先检查索引2
            if ($dataEnd > 2) {
                $element = $decoded[2];
                if (is_string($element) && $element !== '/' && $element[0] === '/') {
                    $packet['namespace'] = $element;
                    $dataStart = 3;
                } else {
                    // 只有索引2不是命名空间时才继续循环
                    for ($i = 3; $i < $dataEnd; $i++) {
                        $element = $decoded[$i];
                        if (is_string($element) && $element !== '/' && $element[0] === '/') {
                            $packet['namespace'] = $element;
                            $dataStart = $i + 1;
                            break;
                        }
                    }
                }
            }
            
            // 直接使用array_slice提取数据参数
            $packet['data'] = array_slice($decoded, $dataStart, $dataEnd - $dataStart);
        }
    }
    
    /**
     * 处理旧格式的事件数据包
     */
    private static function handleLegacyEventFormat(array &$packet, array $decoded): void
    {
        // 兼容旧格式：[$type, $namespace, [event_name, ...data], $ack_id?]
        $packet['namespace'] = $decoded[1] ?? '/';
        if (isset($decoded[2]) && is_array($decoded[2]) && !empty($decoded[2])) {
            $eventArray = $decoded[2];
            if (is_string($eventArray[0])) {
                $packet['event'] = $eventArray[0];
                $packet['data'] = array_slice($eventArray, 1);
            }
        }
        if (isset($decoded[3]) && is_numeric($decoded[3])) { $packet['id'] = (int)$decoded[3]; }
        
        if (empty($packet['event'])) { $packet['event'] = 'unknown'; }
    }
    
    /**
     * 提取ACK数据包数据
     */
    private static function extractAckPacketData(array &$packet, array $decoded): void
    {
        // Socket.IO v4标准ACK包格式：[type, data, namespace?, id?]
        $packet['namespace'] = '/';
        $packet['data'] = [];
        
        // 处理数据部分
        if (isset($decoded[1])) {
            $packet['data'] = is_array($decoded[1]) ? $decoded[1] : [$decoded[1]];
        }
        
        // 处理命名空间 - 优化查找逻辑
        $total = count($decoded);
        // 从索引2开始检查，因为0是type，1是data
        for ($i = 2; $i < $total; $i++) {
            $element = $decoded[$i];
            if (is_string($element) && $element[0] === '/') {
                $packet['namespace'] = $element;
                break;
            }
        }
        
        // 处理ACK ID
        $lastElement = end($decoded);
        if (is_numeric($lastElement)) { $packet['id'] = (int)$lastElement; }
    }
    
    /**
     * 提取连接错误数据包数据
     */
    private static function extractConnectErrorPacketData(array &$packet, array $decoded): void
    {
        // Socket.IO v4标准CONNECT_ERROR包格式：[type, error, namespace?]
        $packet['namespace'] = '/';
        $packet['error'] = 'Unknown error';
        
        // 处理错误信息
        if (isset($decoded[1])) { $packet['error'] = $decoded[1]; }
        
        // 处理命名空间 - 优化查找逻辑
        $total = count($decoded);
        // 从索引2开始检查，因为0是type，1是error
        for ($i = 2; $i < $total; $i++) {
            $element = $decoded[$i];
            if (is_string($element) && $element[0] === '/') {
                $packet['namespace'] = $element;
                break;
            }
        }
    }
    
    /**
     * 解析文本格式的Socket.IO数据（兼容旧协议格式）
     */
    private static function parseTextWireFormat($data)
    {
        $len = strlen($data);
        // 单个数字格式（如0 - CONNECT包）
        if ($len === 1 && ctype_digit($data)) {
            return [(int)$data];
        }
        
        // 两个数字开头的格式（如40, 42等）
        if ($len >= 2 && ctype_digit($data[0]) && ctype_digit($data[1])) {
            $engineIoVersion = (int)$data[0];
            $type = (int)$data[1];
            $rest = substr($data, 2);
            
            $decoded = [$type];
            
            if ($rest !== '') {
                // 处理命名空间连接包
                if ($type === 0 && $rest[0] === '/') {
                    $commaPos = strpos($rest, ',');
                    if ($commaPos !== false) {
                        $namespace = substr($rest, 0, $commaPos);
                    } else {
                        $namespace = $rest;
                    }
                    $decoded[] = $namespace;
                // 尝试解析JSON格式
                } elseif (strpos($rest, '{') !== false || strpos($rest, '[') !== false) {
                    $jsonPart = json_decode($rest, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        if (is_array($jsonPart)) {
                            $decoded = array_merge($decoded, $jsonPart);
                        } else {
                            $decoded[] = $jsonPart;
                        }
                    } else {
                        $decoded[] = $rest;
                    }
                } else {
                    $decoded[] = $rest;
                }
            }
            
            return $decoded;
        }
        
        return null;
    }
    public static function parseSocketIOPacket($data)
    {
        // 空数据检查 - 特别注意：字符串"0"不是空数据！
        if ($data === null || $data === '' || $data === false) {
            return null;
        }
        
        $decoded = null;
        
        // Socket.IO v4协议解析：支持文本和二进制格式
        if (is_array($data)) {
            $decoded = $data;
        } elseif (is_string($data)) {
            // 检查是否为Engine.IO二进制包标识
            if ($data[0] === 'b') {
                $decoded = ['type' => 'binary', 'data' => $data];
            } else {
                // 标准Socket.IO v4协议格式解析
                
                if (preg_match('/^4(\d+)(.*)$/', $data, $matches)) {
                    $socketIoPart = $matches[1];
                    $rest = $matches[2];
                    
                    // 提取Socket.IO类型码和可能的二进制附件数量
                    $socketTypeCode = (int)$socketIoPart[0];
                    $binaryCount = strlen($socketIoPart) > 1 ? (int)substr($socketIoPart, 1) : 0;
                    
                    // 解析剩余部分（可能包含命名空间、ACK ID、JSON数据）
                    $namespace = '/';
                    $ackId = null;
                    $jsonData = null;
                    
                    // 处理命名空间、ACK ID和JSON数据
                    list($namespace, $ackId, $jsonData) = self::parseNamespaceAckAndJson($rest);
                    
                    // 构建解码关联数组
                    $decoded = [
                        'type' => $socketTypeCode,
                        'namespace' => $namespace,
                        'data' => $jsonData,
                        'ackId' => $ackId
                    ];
                    // 存储二进制附件数量（如果是二进制事件）
                    if ($socketTypeCode === 5 && $binaryCount > 0) { $decoded['binaryCount'] = $binaryCount; }
                
                } elseif (ctype_digit($data)) {
                    $packetType = (int)$data;
                    $decoded = [$packetType];
                
                } elseif (preg_match('/^(\d)(\d+)-?(.*)$/', $data, $matches)) {
                    $type = (int)$matches[1];
                    $binaryCount = (int)$matches[2];
                    $rest = $matches[3];
                    
                    $namespace = '/';
                    $jsonData = null;
                    
                    list($namespace, , $jsonData) = self::parseNamespaceAckAndJson($rest);
                    
                    // 构建解码关联数组
                    $decoded = [
                        'type' => $type,
                        'namespace' => $namespace,
                        'data' => $jsonData,
                        'binaryCount' => ($type === 5 && $binaryCount > 0) ? $binaryCount : null
                    ];
                
                } elseif (preg_match('/^(\d)\[(.*)\]$/', $data, $matches)) {
                    $packetType = (int)$matches[1];
                    $jsonStr = $matches[2];
                    
                    // 处理Socket.IO v4标准格式：2["message","123"]
                    if (empty($jsonStr)) {
                        $jsonData = [];
                    } else {
                        $jsonStr = trim($jsonStr);
                        $jsonData = json_decode('[' . $jsonStr . ']', true);
                        if (json_last_error() !== JSON_ERROR_NONE || !is_array($jsonData)) {
                            $jsonData = self::parseSocketIOData($jsonStr);
                        }
                    }
                    
                    // 构建解码关联数组
                    $decoded = ['type' => $packetType];
                    
                    if (is_array($jsonData) && !empty($jsonData)) {
                        if (is_string($jsonData[0] ?? '') && strpos($jsonData[0], '/') === 0) {
                            $decoded['namespace'] = $jsonData[0];
                            $decoded['data'] = array_slice($jsonData, 1);
                        } else {
                            $decoded['data'] = $jsonData;
                        }
                    }
                
                } elseif (preg_match('/^(\d)\/([^,]+)\,?(.*)?$/', $data, $matches)) {
                    // 处理命名空间格式
                    $type = (int)$matches[1];
                    $namespace = '/' . $matches[2];
                    $rest = isset($matches[3]) ? $matches[3] : '';
                    
                    $decoded = ['type' => $type, 'namespace' => $namespace];
                    
                    if (!empty($rest)) {
                        if (preg_match('/^(\d+)(\[.*\])$/', $rest, $ackMatches)) {
                            $jsonStr = $ackMatches[2];
                            $jsonData = json_decode($jsonStr, true);
                            $decoded['ackId'] = (int)$ackMatches[1];
                            if (is_array($jsonData)) { $decoded['data'] = $jsonData; }
                        } elseif (preg_match('/^(\[.*\])$/', $rest, $jsonMatches)) {
                            $jsonStr = $jsonMatches[1];
                            $jsonData = json_decode($jsonStr, true);
                            if (is_array($jsonData)) { $decoded['data'] = $jsonData; }
                        }
                    }
                
                } else {
                    $decoded = ['type' => 4, 'data' => $data];
                }
            }
        } else {
            return null;
        }
        
        // 验证解析结果
        if (!is_array($decoded) || (!isset($decoded[0]) && !isset($decoded['type']))) {
            return null;
        }
        
        // 处理解码后的数据
        try {
            $type = isset($decoded['type']) ? (int)$decoded['type'] : (int)$decoded[0];
            if (!isset(self::SOCKET_PACKET_TYPES[$type])) {
                return null;
            }
            
            $packet = ['type' => self::SOCKET_PACKET_TYPES[$type]];
            return self::extractPacketData($packet, $decoded);
            
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 构建Engine.IO数据包
     * @param string $type 包类型
     * @param mixed $data 数据
     * @return string 构建好的包
     */
    public static function buildEngineIOPacket($type, $data = '')
    {
        $typeMap = array_flip(self::ENGINE_PACKET_TYPES);
        $typeCode = isset($typeMap[$type]) ? $typeMap[$type] : 4; // 默认为message
        
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data);
        }
        
        return $typeCode . $data;
    }

    /**
     * 构建Socket.IO数据包
     * @param string $type 包类型
     * @param array $params 参数
     * @return string Socket.IO v4 标准格式的数据包
     */
    public static function buildSocketIOPacket($type, array $params = [])
    {
        $typeMap = array_flip(self::SOCKET_PACKET_TYPES);
        $typeCode = isset($typeMap[$type]) ? $typeMap[$type] : 2; // 默认为EVENT
        
        $packet = (string)$typeCode;
        
        // 根据类型构建参数结构
        switch ($type) {
            case 'CONNECT':
                $namespace = $params['namespace'] ?? '/';
                if ($namespace !== '/') { $packet .= $namespace . ','; }
                if (isset($params['data'])) { $packet .= json_encode($params['data']); }
                break;
                
            case 'DISCONNECT':
                $namespace = $params['namespace'] ?? '/';
                if ($namespace !== '/') { $packet .= $namespace; }
                break;
                
            case 'EVENT':
            case 'BINARY_EVENT':
                $namespace = $params['namespace'] ?? '/';
                if ($namespace !== '/') { $packet .= $namespace . ','; }
                
                if (isset($params['id'])) { $packet .= $params['id']; }
                
                $eventName = $params['event'] ?? 'unknown';
                $dataWrapper = self::createDataWrapper($params);
                
                $eventArray = [$eventName];
                foreach ($dataWrapper as $d) { $eventArray[] = $d; }
                $packet .= json_encode($eventArray);
                break;
                
            case 'ACK':
            case 'BINARY_ACK':
                $namespace = $params['namespace'] ?? '/';
                if ($namespace !== '/') { $packet .= $namespace . ','; }
                
                if (isset($params['id'])) { $packet .= $params['id']; }
                
                if (isset($params['data'])) {
                    $ackData = is_array($params['data']) ? $params['data'] : [$params['data']];
                    $packet .= json_encode($ackData);
                }
                break;
                
            case 'CONNECT_ERROR':
                $namespace = $params['namespace'] ?? '/';
                if ($namespace !== '/') { $packet .= $namespace . ','; }
                $packet .= json_encode(['message' => $params['error'] ?? 'Unknown error']);
                break;
        }
        
        return $packet;
    }

    /**
     * 验证和清理数据包
     * @param array $packet 数据包
     * @return bool 是否有效
     */
    public static function validatePacket($packet)
    {
        if (!is_array($packet) || !isset($packet['type'])) { return false; }
        
        if (in_array($packet['type'], self::ENGINE_PACKET_TYPES)) { return true; }
        if (in_array($packet['type'], self::SOCKET_PACKET_TYPES)) { return true; }
        if ($packet['type'] === 'binary') { return true; }
        
        return false;
    }

    /**
     * 心跳包识别
     * @param string $data 数据
     * @return bool 是否为心跳包
     */
    public static function isHeartbeat($data)
    {
        return $data === '2' || $data === '3';
    }

    /**
     * 握手响应包构建
     * @param array $sessionInfo 会话信息
     * @return string 握手包
     */
    public static function buildHandshakePacket($sessionInfo)
    {
        $handshake = [
            'sid' => $sessionInfo['sid'],
            'upgrades' => ['websocket'],
            'pingInterval' => 25000,
            'pingTimeout' => 5000
        ];
        
        return self::buildEngineIOPacket('open', $handshake);
    }

    /**
     * 解析命名空间、ACK ID和JSON数据
     */
    private static function parseNamespaceAckAndJson($rest)
    {
        $namespace = '/';
        $ackId = null;
        $jsonData = null;
        
        // 处理命名空间和数据
        if (preg_match('/^\/([^,]+)\,(.*)$/', $rest, $nsMatches)) {
            $namespace = '/' . $nsMatches[1];
            $afterNs = $nsMatches[2];
            
            if (preg_match('/^(\d+)(\[.*\])$/', $afterNs, $ackMatches)) {
                $ackId = (int)$ackMatches[1];
                $jsonStr = $ackMatches[2];
                $jsonData = json_decode($jsonStr, true);
            } elseif (preg_match('/^(\[.*\])$/', $afterNs, $jsonMatches)) {
                $jsonStr = $jsonMatches[1];
                $jsonData = json_decode($jsonStr, true);
            }
        } elseif (preg_match('/^(\[.*\])$/', $rest, $jsonMatches)) {
            $jsonStr = $jsonMatches[1];
            $jsonData = json_decode($jsonStr, true);
        } elseif (preg_match('/^(\d+)(\[.*\])$/', $rest, $ackMatches)) {
            $ackId = (int)$ackMatches[1];
            $jsonStr = $ackMatches[2];
            $jsonData = json_decode($jsonStr, true);
        }
        
        return [$namespace, $ackId, $jsonData];
    }
    
    /**
     * 创建数据包装数组
     */
    private static function createDataWrapper(array $params): array
    {
        $dataWrapper = [];
        if (isset($params['data']) && is_array($params['data'])) {
            $dataWrapper = $params['data'];
        } elseif (isset($params['args']) && is_array($params['args'])) {
            $dataWrapper = $params['args'];
        } elseif (isset($params['data'])) {
            $dataWrapper = [$params['data']];
        }
        return $dataWrapper;
    }
    
    /**
     * 添加命名空间和ACK ID到数据包
     */
    private static function addNamespaceAndAck(array &$packet, array $params): void
    {
        $namespace = $params['namespace'] ?? '/';
        if ($namespace !== '/') { $packet[] = $namespace; }
        
        if (isset($params['id'])) { $packet[] = $params['id']; }
    }
    
    /**
     * 智能解析Socket.IO数据格式
     * @param string $dataStr 数据字符串
     * @return array 解析后的数据数组
     */
    private static function parseSocketIOData($dataStr)
    {
        $result = [];
        $dataStr = trim($dataStr);
        
        if ($dataStr === '') { return $result; }
        
        $inQuote = false;
        $currentParam = '';
        $params = [];
        
        for ($i = 0; $i < strlen($dataStr); $i++) {
            $char = $dataStr[$i];
            
            if ($char === '"') { $inQuote = !$inQuote; }
            
            if ($char === ',' && !$inQuote) {
                $params[] = trim($currentParam);
                $currentParam = '';
            } else {
                $currentParam .= $char;
            }
        }
        
        if ($currentParam !== '') { $params[] = trim($currentParam); }
        
        foreach ($params as $param) {
            $decoded = json_decode($param);
            if (json_last_error() === JSON_ERROR_NONE) {
                $result[] = $decoded;
            } else {
                $len = strlen($param);
                if ($len >= 2 && $param[0] === '"' && $param[$len - 1] === '"') {
                    $result[] = substr($param, 1, $len - 2);
                } else {
                    $result[] = $param;
                }
            }
        }
        
        return $result;
    }

    /**
     * 替换二进制占位符为实际二进制数据
     * @param array $packet 解析后的数据包
     * @param array $binaryAttachments 二进制附件数组
     * @return array 替换占位符后的数据包
     */
    public static function replaceBinaryPlaceholders(array $packet, array $binaryAttachments)
    {
        if (empty($binaryAttachments)) { return $packet; }
        
        $replaceFn = function($data) use (&$replaceFn, $binaryAttachments) {
            if (is_array($data)) {
                if (isset($data['_placeholder']) && $data['_placeholder'] === true && isset($data['num'])) {
                    $num = $data['num'];
                    if (isset($binaryAttachments[$num])) {
                        return $binaryAttachments[$num];
                    }
                }
                foreach ($data as $key => $value) {
                    $data[$key] = $replaceFn($value);
                }
            }
            return $data;
        };
        
        if (isset($packet['data'])) {
            $packet['data'] = $replaceFn($packet['data']);
        }
        
        return $packet;
    }
}
