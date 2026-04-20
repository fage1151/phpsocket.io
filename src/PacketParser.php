<?php

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
            echo "[packet] empty data received, treating as noop\n";
            return ['type' => 'noop', 'data' => ''];
        }
        
        // 二进制数据包识别（'b'前缀）
        if ($data[0] === 'b') {
            echo "[packet] binary packet detected: data=" . substr($data, 0, 100) . "\n";
            return ['type' => 'binary', 'data' => substr($data, 1)];
        }
        
        // 文本消息解析
        $typeChar = $data[0];
        $payload = substr($data, 1);
        
        // 类型转换和验证
        $type = ctype_digit($typeChar) ? (int)$typeChar : -1;
        
        if (!isset(self::ENGINE_PACKET_TYPES[$type])) {
            // 特殊处理：检查是否是特殊错误代码或奇怪格式
            if (strlen($data) > 0 && is_numeric($data[0])) {
                $typeCode = (int)$data[0];
                echo "[packet] unknown engine type: {$typeChar} (code={$typeCode})\n";
            } else {
                echo "[packet] unknown engine type: {$typeChar}, raw data=\"" . substr($data, 0, 50) . "\" (len=" . strlen($data) . ")\n";
            }
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
                echo "[packet] JSON parse error: " . $e->getMessage() . "\n";
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
                    $packet['event'] = $decoded['data'][0] ?? 'unknown';
                    $packet['data'] = array_slice($decoded['data'], 1) ?? [];
                    if (isset($decoded['ackId'])) {
                        $packet['id'] = $decoded['ackId'];
                    }
                    if (isset($decoded['binaryCount'])) {
                        $packet['binaryCount'] = $decoded['binaryCount'];
                    }
                    break;
                    
                case 'ACK':
                case 'BINARY_ACK':
                    $packet['namespace'] = $decoded['namespace'] ?? '/';
                    $packet['data'] = $decoded['data'] ?? [];
                    if (isset($decoded['ackId'])) {
                        $packet['id'] = $decoded['ackId'];
                    }
                    break;
                    
                case 'CONNECT_ERROR':
                    $packet['data'] = $decoded['data'] ?? [];
                    break;
            }
        } else {
            // 处理旧的索引数组格式（向后兼容）
            switch ($packet['type']) {
                case 'CONNECT':
                    $packet['namespace'] = $decoded[1] ?? '/';
                    if (isset($decoded[2])) {
                        $packet['auth'] = $decoded[2];
                    }
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
        if (isset($decoded['binaryCount'])) {
            $packet['binaryCount'] = $decoded['binaryCount'];
            echo "[packet] 二进制附件数量: {$packet['binaryCount']}\n";
        }
        
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
        // 格式2：[type, namespace, event_name, data_args..., ack_id?]
        $packet['namespace'] = $decoded[1];
        
        if (!isset($decoded[2]) || !is_string($decoded[2])) {
            $packet['event'] = 'unknown';
            $packet['data'] = [];
            return;
        }
        
        $packet['event'] = $decoded[2];
        
        // 收集数据参数
        $dataArgs = [];
        
        // 遍历所有元素，将所有元素都作为数据参数，除了最后一个可能的ACK ID
        for ($i = 3; $i < count($decoded); $i++) {
            if (!isset($decoded[$i])) continue;
            
            $element = $decoded[$i];
            // 检查是否是最后一个元素且是数字（可能是ACK ID）
            if ($i === count($decoded) - 1 && is_numeric($element)) {
                $packet['id'] = (int)$element;
            } else {
                // 所有其他元素都作为数据参数
                $dataArgs[] = $element;
            }
        }
        
        $packet['data'] = $dataArgs;
        echo "[packet] 事件数据解析 (格式2): event={$packet['event']}, data=" . json_encode($packet['data']) . ", namespace={$packet['namespace']}" . "\n";
        if (isset($packet['id'])) {
            echo "[packet] 提取到ACK ID: {$packet['id']}\n";
        }
    }
    
    /**
     * 处理标准格式的事件数据包
     */
    private static function handleStandardEventFormat(array &$packet, array $decoded): void
    {
        $packet['event'] = $decoded[1];
        
        // Socket.IO v4协议标准解析算法
        $dataArgs = [];
        $foundNamespace = false;
        $totalElements = count($decoded);
        
        // 收集所有数据参数（除了最后一个可能的ACK ID）
        for ($i = 2; $i < $totalElements; $i++) {
            if (!isset($decoded[$i])) continue;
            
            $element = $decoded[$i];
            
            // 检查是否是最后一个元素且是数字（可能是ACK ID）
            if ($i === $totalElements - 1 && is_numeric($element)) {
                $packet['id'] = (int)$element;
            } 
            // 检查是否为命名空间格式（如"/namespace"）
            else if (is_string($element) && $element !== '/' && strpos($element, '/') === 0 && strlen($element) > 1) {
                // 命名空间标记：必须以"/"开头且不是根命名空间
                $packet['namespace'] = $element;
                $foundNamespace = true;
            } else {
                // 所有其他元素都作为数据参数
                $dataArgs[] = $element;
            }
        }
        
        // 设置默认命名空间
        if (!isset($packet['namespace'])) {
            $packet['namespace'] = '/';
        }
        
        // 协议标准：数据作为数组传递给事件处理器
        $packet['data'] = $dataArgs;
        
        echo "[packet] 事件数据解析 (格式1): event={$packet['event']}, data=" . json_encode($packet['data']) . ", namespace={$packet['namespace']}" . "\n";
        if (isset($packet['id'])) {
            echo "[packet] 提取到ACK ID: {$packet['id']}\n";
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
        if (isset($decoded[3]) && is_numeric($decoded[3])) {
            $packet['id'] = (int)$decoded[3];
        }
        
        if (empty($packet['event'])) {
            echo "[socketio] warning: event name not found in packet\n";
            $packet['event'] = 'unknown';
        }
        
        echo "[socketio] 事件包解析结果: " . json_encode($packet) . "\n";
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
        
        // 处理命名空间
        foreach ($decoded as $element) {
            if (is_string($element) && strpos($element, '/') === 0) {
                $packet['namespace'] = $element;
                break;
            }
        }
        
        // 处理ACK ID
        $lastElement = end($decoded);
        if (is_numeric($lastElement)) {
            $packet['id'] = (int)$lastElement;
        }
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
        if (isset($decoded[1])) {
            $packet['error'] = $decoded[1];
        }
        
        // 处理命名空间
        foreach ($decoded as $element) {
            if (is_string($element) && strpos($element, '/') === 0) {
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
        // 两个数字开头的格式（如40, 42等）
        if (preg_match('/^(\d)(\d)(.*)$/', $data, $matches)) {
            $engineIoVersion = (int)$matches[1];
            $type = (int)$matches[2];
            $rest = $matches[3];
            
            $decoded = [$type];
            
            if ($rest !== '') {
                // 处理命名空间连接包
                if ($type === 0 && preg_match('/^\/([^,]*),?/', $rest, $nsMatch)) {
                    $namespace = rtrim($nsMatch[0], ',');
                    $decoded[] = $namespace;
                // 处理格式：/namespace,ackId[jsonData]
                } elseif (preg_match('/^\/([^,]+)\,(\d+)(\[.*\])$/', $rest, $matches)) {
                    $namespace = '/' . $matches[1];
                    $ackId = (int)$matches[2];
                    $jsonPart = json_decode($matches[3], true);
                    
                    $decoded[] = $namespace;
                    if (is_array($jsonPart)) {
                        $decoded = array_merge($decoded, $jsonPart);
                        $decoded[] = $ackId;
                    } else {
                        $decoded[] = $jsonPart;
                    }
                // 处理格式：/namespace,[jsonData]
                } elseif (preg_match('/^\/([^,]+)\,(\[.*\])$/', $rest, $matches)) {
                    $namespace = '/' . $matches[1];
                    $jsonPart = json_decode($matches[2], true);
                    
                    $decoded[] = $namespace;
                    if (is_array($jsonPart)) {
                        $decoded = array_merge($decoded, $jsonPart);
                    } else {
                        $decoded[] = $jsonPart;
                    }
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
        // 单个数字格式（如0 - CONNECT包）
        } elseif (preg_match('/^(\d)$/', $data, $matches)) {
            return [(int)$matches[1]];
        }
        
        return null;
    }
    public static function parseSocketIOPacket($data)
    {
        // 空数据检查 - 特别注意：字符串"0"不是空数据！
        if ($data === null || $data === '' || $data === false) {
            echo "[packet] socketio empty data\n";
            return null;
        }
        
        // 字符串"0"是有效的Socket.IO连接包，不能视为空
        if ($data === '0') {
            echo "[packet] socketio CONNECT packet detected\n";
        }
        
        // 记录原始数据用于调试
        echo "[packet] socketio raw data: " . substr($data, 0, 200) . (strlen($data) > 200 ? "..." : "") . "\n";
        
        $decoded = null;
        
        // Socket.IO v4协议解析：支持文本和二进制格式
        if (is_array($data)) {
            // 已经是数组格式，直接使用
            echo "[packet] using existing array format\n";
            $decoded = $data;
        } elseif (is_string($data)) {
            // 检查是否为Engine.IO二进制包标识
            if ($data[0] === 'b') {
                // Engine.IO二进制包，将整个包作为二进制数据传递
                $decoded = ['type' => 'binary', 'data' => $data];
                echo "[packet] Engine.IO binary packet detected: data=" . substr($data, 0, 50) . "\n";
                
            } else {
                // 标准Socket.IO v4协议格式解析
                
                // 首先处理Engine.IO + Socket.IO组合格式（如42[...]、451-...、451/...）
                if (preg_match('/^4(\d+)(.*)$/', $data, $matches)) {
                    $socketIoPart = $matches[1];
                    $rest = $matches[2];
                    echo "[packet] detected Engine.IO format (4 prefix), socketIoPart={$socketIoPart}, rest=" . substr($rest, 0, 100) . "\n";
                    
                    // 提取Socket.IO类型码和可能的二进制附件数量
                    $socketTypeCode = (int)$socketIoPart[0];
                    $binaryCount = strlen($socketIoPart) > 1 ? (int)substr($socketIoPart, 1) : 0;
                    
                    echo "[protocol] socketTypeCode={$socketTypeCode}, binaryCount={$binaryCount}\n";
                    
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
                    echo "[protocol] Engine.IO format packet decoded (associative): " . json_encode($decoded) . "\n";
                    // 存储二进制附件数量（如果是二进制事件）
                    if ($socketTypeCode === 5 && $binaryCount > 0) {
                        $decoded['binaryCount'] = $binaryCount;
                    }
                    
                    echo "[protocol] Engine.IO format packet decoded: " . json_encode($decoded) . "\n";
                
                // 检查是否为简单Socket.IO包类型（如"0", "1", "2"等）
                } elseif (preg_match('/^\d+$/', $data)) {
                    $packetType = (int)$data;
                    echo "[packet] parsed as simple Socket.IO packet: type={$packetType}\n";
                    $decoded = [$packetType];
                
                // 处理二进制事件包格式：如 51-... 或 51/...
                } elseif (preg_match('/^(\d)(\d+)-?(.*)$/', $data, $matches)) {
                    $type = (int)$matches[1];
                    $binaryCount = (int)$matches[2];
                    $rest = $matches[3];
                    
                    echo "[packet] detected binary event packet: type={$type}, binaryCount={$binaryCount}, rest=" . substr($rest, 0, 100) . "\n";
                    
                    $namespace = '/';
                    $jsonData = null;
                    
                    // 解析命名空间和JSON数据
                    list($namespace, , $jsonData) = self::parseNamespaceAckAndJson($rest);
                    
                    // 构建解码关联数组
                    $decoded = [
                        'type' => $type,
                        'namespace' => $namespace,
                        'data' => $jsonData,
                        'binaryCount' => ($type === 5 && $binaryCount > 0) ? $binaryCount : null
                    ];
                    echo "[packet] binary event packet decoded (associative): " . json_encode($decoded) . "\n";
                
                // 否则检查标准Socket.IO格式：如 "2[\"message\",\"123\"]"
                } elseif (preg_match('/^(\\d)\\[(.*)\\]$/', $data, $matches)) {
                    $packetType = (int)$matches[1];
                    $jsonStr = $matches[2];
                    
                    echo "[packet] regex matched for standard format: type={$packetType}, jsonStr='{$jsonStr}'\n";
                    
                    // 处理Socket.IO v4标准格式：2["message","123"]
                    if (empty($jsonStr)) {
                        $jsonData = [];
                    } else {
                        $jsonStr = trim($jsonStr);
                        // 尝试直接解析JSON
                        $jsonData = json_decode('[' . $jsonStr . ']', true);
                        if (json_last_error() !== JSON_ERROR_NONE || !is_array($jsonData)) {
                            // JSON解析失败，尝试智能解析
                            $jsonData = self::parseSocketIOData($jsonStr);
                        }
                    }
                    
                    // 构建解码关联数组
                    $decoded = [
                        'type' => $packetType,
                        'data' => $jsonData
                    ];
                    echo "[packet] standard format decoded (associative): " . json_encode($decoded) . "\n";
                    
                } elseif (preg_match('/^(\d)\/([^,]+)\,?(.*)?$/', $data, $matches)) {
                    // 处理命名空间格式
                    $type = (int)$matches[1];
                    $namespace = '/' . $matches[2];
                    $rest = isset($matches[3]) ? $matches[3] : '';
                    
                    echo "[packet] detected namespace packet: type={$type}, namespace={$namespace}, rest={$rest}\n";
                    
                    // 构建解码关联数组
                    $decoded = [
                        'type' => $type,
                        'namespace' => $namespace,
                        'data' => null,
                        'ackId' => null
                    ];
                    
                    if (!empty($rest)) {
                        if (preg_match('/^(\d+)(\[.*\])$/', $rest, $ackMatches)) {
                            $decoded['ackId'] = (int)$ackMatches[1];
                            $jsonStr = $ackMatches[2];
                            $decoded['data'] = json_decode($jsonStr, true);
                        } elseif (preg_match('/^(\[.*\])$/', $rest, $jsonMatches)) {
                            $jsonStr = $jsonMatches[1];
                            $decoded['data'] = json_decode($jsonStr, true);
                        }
                    }
                    
                    echo "[packet] namespace packet decoded (associative): " . json_encode($decoded) . "\n";
                    
                } else {
                    // 降级处理
                    echo "[packet] treating as plain message: {$data}\n";
                    $decoded = [
                        'type' => 4,
                        'data' => $data
                    ];
                }
            }
        } else {
            echo "[packet] unsupported data type: " . gettype($data) . "\n";
            return null;
        }
        
        // 验证解析结果 - 注意：type=0 是有效的CONNECT包
        if (!is_array($decoded) || (!isset($decoded[0]) && !isset($decoded['type']))) {
            echo "[packet] invalid packet structure, decoded: " . json_encode($decoded) . "\n";
            return null;
        }
        
        // 处理解码后的数据
        try {
            // 获取类型码，支持关联数组和索引数组格式
            $type = isset($decoded['type']) ? (int)$decoded['type'] : (int)$decoded[0];
            if (!isset(self::SOCKET_PACKET_TYPES[$type])) {
                echo "[packet] unknown socketio type: {$type}, decoded: " . json_encode($decoded) . "\n";
                return null;
            }
            
            $packet = ['type' => self::SOCKET_PACKET_TYPES[$type]];
            
            // 根据Socket.IO v4协议提取数据
            return self::extractPacketData($packet, $decoded);
            
        } catch (Exception $e) {
            echo "[packet] socketio parse error: " . $e->getMessage() . "\n";
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
        
        // 处理各种数据类型
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
                // 格式：0 + [/namespace,] + data
                $namespace = $params['namespace'] ?? '/';
                if ($namespace !== '/') {
                    $packet .= $namespace . ',';
                }
                if (isset($params['data'])) {
                    $packet .= json_encode($params['data']);
                }
                break;
                
            case 'DISCONNECT':
                // 格式：1 + [/namespace]
                $namespace = $params['namespace'] ?? '/';
                if ($namespace !== '/') {
                    $packet .= $namespace;
                }
                break;
                
            case 'EVENT':
            case 'BINARY_EVENT':
                // 格式：[type] + [/namespace,] + [ackId] + [event name, data]
                $namespace = $params['namespace'] ?? '/';
                if ($namespace !== '/') {
                    $packet .= $namespace . ',';
                }
                
                if (isset($params['id'])) {
                    $packet .= $params['id'];
                }
                
                $eventName = $params['event'] ?? 'unknown';
                $dataWrapper = self::createDataWrapper($params);
                
                $eventArray = [$eventName];
                foreach ($dataWrapper as $d) {
                    $eventArray[] = $d;
                }
                $packet .= json_encode($eventArray);
                
                if ($type === 'BINARY_EVENT') {
                    echo "[protocol] BINARY_EVENT包构建完成: {$packet}\n";
                } else {
                    echo "[socketio_v4_protocol] 标准EVENT包构建: event={$eventName}, data:" . json_encode($dataWrapper) . "\n";
                    echo "[protocol] EVENT包构建完成: {$packet}\n";
                }
                break;
                
            case 'ACK':
            case 'BINARY_ACK':
                // 格式：[type] + [/namespace,] + ackId + [data]
                $namespace = $params['namespace'] ?? '/';
                if ($namespace !== '/') {
                    $packet .= $namespace . ',';
                }
                
                if (isset($params['id'])) {
                    $packet .= $params['id'];
                }
                
                if (isset($params['data'])) {
                    $ackData = is_array($params['data']) ? $params['data'] : [$params['data']];
                    $packet .= json_encode($ackData);
                }
                
                if ($type === 'BINARY_ACK') {
                    echo "[packet] built BINARY_ACK packet: {$packet}\n";
                }
                break;
                
            case 'CONNECT_ERROR':
                $namespace = $params['namespace'] ?? '/';
                if ($namespace !== '/') {
                    $packet .= $namespace . ',';
                }
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
        if (!is_array($packet) || !isset($packet['type'])) {
            return false;
        }
        
        // 验证Engine.IO包类型
        if (in_array($packet['type'], self::ENGINE_PACKET_TYPES)) {
            return true;
        }
        
        // 验证Socket.IO包类型
        if (in_array($packet['type'], self::SOCKET_PACKET_TYPES)) {
            return true;
        }
        
        // 允许binary类型
        if ($packet['type'] === 'binary') {
            return true;
        }
        
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
            echo "[packet] found namespace: {$namespace}, afterNs=" . substr($afterNs, 0, 100) . "\n";
            
            // 处理ACK ID和JSON数据
            if (preg_match('/^(\d+)(\[.*\])$/', $afterNs, $ackMatches)) {
                $ackId = (int)$ackMatches[1];
                $jsonStr = $ackMatches[2];
                $jsonData = json_decode($jsonStr, true);
            } elseif (preg_match('/^(\[.*\])$/', $afterNs, $jsonMatches)) {
                $jsonStr = $jsonMatches[1];
                $jsonData = json_decode($jsonStr, true);
            }
        } elseif (preg_match('/^(\[.*\])$/', $rest, $jsonMatches)) {
            // 没有命名空间，直接是JSON数据
            $jsonStr = $jsonMatches[1];
            $jsonData = json_decode($jsonStr, true);
        } elseif (preg_match('/^(\d+)(\[.*\])$/', $rest, $ackMatches)) {
            // 没有命名空间，有ACK ID和JSON数据
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
            // 兼容旧格式
            $dataWrapper = $params['args'];
        } elseif (isset($params['data'])) {
            // 单个数据值包装为数组
            $dataWrapper = [$params['data']];
        }
        return $dataWrapper;
    }
    
    /**
     * 添加命名空间和ACK ID到数据包
     */
    private static function addNamespaceAndAck(array &$packet, array $params): void
    {
        // 命名空间处理（仅当非主命名空间时）
        $namespace = $params['namespace'] ?? '/';
        if ($namespace !== '/') {
            $packet[] = $namespace;
        }
        
        // ACK ID处理
        if (isset($params['id'])) {
            $packet[] = $params['id'];
        }
    }
    
    /**
     * 智能解析Socket.IO数据格式
     * @param string $dataStr 数据字符串
     * @return array 解析后的数据数组
     */
    private static function parseSocketIOData($dataStr)
    {
        $result = [];
        
        // 去除前后空格
        $dataStr = trim($dataStr);
        
        // 如果是空字符串，返回空数组
        if ($dataStr === '') {
            return $result;
        }
        
        // 使用更智能的逗号分隔解析，处理JSON格式的参数
        $inQuote = false;
        $currentParam = '';
        $params = [];
        
        for ($i = 0; $i < strlen($dataStr); $i++) {
            $char = $dataStr[$i];
            
            if ($char === '"') {
                $inQuote = !$inQuote;
            }
            
            if ($char === ',' && !$inQuote) {
                // 发现逗号分隔符且不在引号内
                $params[] = trim($currentParam);
                $currentParam = '';
            } else {
                $currentParam .= $char;
            }
        }
        
        // 添加最后一个参数
        if ($currentParam !== '') {
            $params[] = trim($currentParam);
        }
        
        // 处理每个参数
        foreach ($params as $param) {
            // 尝试JSON解码
            $decoded = json_decode($param);
            if (json_last_error() === JSON_ERROR_NONE) {
                $result[] = $decoded;
            } else {
                // 如果不是合法的JSON，直接使用原始值（移除引号）
                if (preg_match('/^"(.*)"$/', $param, $matches)) {
                    $result[] = $matches[1];
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
        if (empty($binaryAttachments)) {
            return $packet;
        }
        
        echo "[packet] replaceBinaryPlaceholders - 开始替换 " . count($binaryAttachments) . " 个二进制附件\n";
        
        // 递归替换占位符
        $replaceFn = function($data) use (&$replaceFn, $binaryAttachments) {
            if (is_array($data)) {
                // 检查是否为占位符
                if (isset($data['_placeholder']) && $data['_placeholder'] === true && isset($data['num'])) {
                    $num = $data['num'];
                    if (isset($binaryAttachments[$num])) {
                        echo "[packet] 找到占位符 num={$num}，替换为二进制数据\n";
                        return $binaryAttachments[$num];
                    }
                }
                // 递归处理数组
                foreach ($data as $key => $value) {
                    $data[$key] = $replaceFn($value);
                }
            }
            return $data;
        };
        
        // 替换数据包中的占位符
        if (isset($packet['data'])) {
            $packet['data'] = $replaceFn($packet['data']);
        }
        
        echo "[packet] replaceBinaryPlaceholders - 替换完成\n";
        return $packet;
    }
}