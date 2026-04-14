<?php

namespace PhpSocketIO;
/**
 * Socket.IO v4 协议解析器
 *
 * 支持:
 *  - Engine.IO 帧解析
 *  - Socket.IO 所有 7 种包类型
 *  - 命名空间解析
 *  - 二进制附件重组
 *  - ACK ID 提取
 *  - 嵌套占位符递归替换
 */
class SocketIOV4Parser
{
    // ====== Socket.IO 包类型常量 ======
    const SIO_CONNECT        = 0;
    const SIO_DISCONNECT     = 1;
    const SIO_EVENT          = 2;
    const SIO_ACK            = 3;
    const SIO_CONNECT_ERROR  = 4;
    const SIO_BINARY_EVENT   = 5;
    const SIO_BINARY_ACK     = 6;

    // ====== 状态属性 ======

    /** @var array 待组装的二进制包缓冲 */
    private $pendingPackets = [];

    /** @var array 事件回调 [eventName => callable] */
    private $eventHandlers = [];

    /** @var callable|null 通用消息回调 */
    private $onPacket = null;

    // ================================================================
    //  入口: 解析原始 WebSocket/HTTP 帧
    // ================================================================

    /**
     * 收到一帧数据时调用
     *
     * @param  string $raw  原始帧内容 (如 "42[\"hello\",{\"text\":\"hi\"}]" 或 "b<base64>")
     * @param  string $connId 连接标识, 用于区分不同客户端
     * @return array|null 完整解析后的包, 如果还在等待二进制附件则返回 null
     */
    public function feed(string $raw, string $connId = 'default'): ?array
    {
        if (empty($raw)) {
            return null;
        }

        $eioType = $raw[0];

        // ---- 二进制帧 (Engine.IO type b 或 4b) ----
        if ($eioType === 'b' || (strlen($raw) > 1 && $raw[0] === '4' && $raw[1] === 'b')) {
            return $this->handleBinaryFrame($raw, $connId);
        }

        // ---- 文本帧 ----
        if ($eioType !== '4') {
            return $this->handleEngineIOControl($raw, $connId);
        }

        // Engine.IO type 4 (message) → 解析内部的 Socket.IO 包
        $sioPayload = substr($raw, 1); // 去掉 "4"
        return $this->parseSocketIOPacket($sioPayload, $connId);
    }

    // ================================================================
    //  Engine.IO 层
    // ================================================================

    /**
     * 处理 Engine.IO 控制包 (非 message)
     */
    private function handleEngineIOControl(string $raw, string $connId): ?array
    {
        $type = (int) $raw[0];
        $data = substr($raw, 1);

        $types = [
            0 => 'OPEN',
            1 => 'CLOSE',
            2 => 'PING',
            3 => 'PONG',
            5 => 'UPGRADE',
            6 => 'NOOP',
        ];

        return [
            'layer'  => 'engine.io',
            'type'   => $types[$type] ?? 'UNKNOWN',
            'typeId' => $type,
            'data'   => $data,
            'connId' => $connId,
        ];
    }

    /**
     * 处理二进制帧
     * "b<base64>"  或  "4b<base64>"
     */
    private function handleBinaryFrame(string $raw, string $connId): ?array
    {
        // 提取 base64 数据
        if ($raw[0] === 'b') {
            $base64 = substr($raw, 1);
        } else {
            // "4b..."
            $base64 = substr($raw, 2);
        }

        $binaryData = base64_decode($base64, true);
        if ($binaryData === false) {
            // 不是 base64, 可能是原始二进制 (WebSocket binary frame)
            $binaryData = $base64;
        }

        // 是否有等待中的包?
        if (!isset($this->pendingPackets[$connId])) {
            return [
                'layer'  => 'engine.io',
                'type'   => 'BINARY',
                'binary' => $binaryData,
                'connId' => $connId,
            ];
        }

        // 追加到待组装包
        $pending = &$this->pendingPackets[$connId];
        $pending['attachments'][] = $binaryData;
        $pending['receivedCount']++;

        // 全部附件到齐?
        if ($pending['receivedCount'] >= $pending['expectedCount']) {
            return $this->resolvePendingPacket($connId);
        }

        return null; // 还在等更多附件
    }

    // ================================================================
    //  Socket.IO 层
    // ================================================================

    /**
     * 解析 Socket.IO 包
     *
     * @param  string $payload  去掉 "4" 之后的部分
     *         例: "2[\"hello\",{\"text\":\"hi\"}]"
     *         例: "0/chat,"
     *         例: "51-[\"upload\",{\"_placeholder\":true,\"num\":0}]"
     *         例: "51-/chat,[\"upload\",{\"_placeholder\":true,\"num\":0}]"
     * @param  string $connId
     * @return array|null
     */
    private function parseSocketIOPacket(string $payload, string $connId): ?array
    {
        $sioType = (int) $payload[0];
        $rest = substr($payload, 1);

        switch ($sioType) {
            case self::SIO_CONNECT:
                return $this->parseConnect($rest, $connId);

            case self::SIO_DISCONNECT:
                return $this->parseDisconnect($rest, $connId);

            case self::SIO_EVENT:
                return $this->parseEvent($rest, $connId);

            case self::SIO_ACK:
                return $this->parseAck($rest, $connId);

            case self::SIO_CONNECT_ERROR:
                return $this->parseConnectError($rest, $connId);

            case self::SIO_BINARY_EVENT:
                return $this->parseBinaryEvent($rest, $connId);

            case self::SIO_BINARY_ACK:
                return $this->parseBinaryAck($rest, $connId);

            default:
                return [
                    'layer'    => 'socket.io',
                    'type'     => 'UNKNOWN',
                    'typeId'   => $sioType,
                    'raw'      => $payload,
                    'connId'   => $connId,
                ];
        }
    }

    // --------------------------------------------------------
    //  40 - CONNECT
    //  格式: 0/ns,  或  0/ns?query
    //  示例: "0/chat,"  "0/,"
    // --------------------------------------------------------
    private function parseConnect(string $rest, string $connId): array
    {
        $namespace = '/';
        $query = '';
        $payload = null;

        if (!empty($rest)) {
            // 找逗号分隔 namespace 和 payload
            $commaPos = strpos($rest, ',');
            if ($commaPos !== false) {
                $nsPart = substr($rest, 0, $commaPos);
                $jsonPart = substr($rest, $commaPos + 1);
                if (!empty($jsonPart)) {
                    $payload = json_decode($jsonPart, true);
                }
            } else {
                $nsPart = $rest;
            }

            // 分离 namespace 和 query string
            $qPos = strpos($nsPart, '?');
            if ($qPos !== false) {
                $namespace = substr($nsPart, 0, $qPos) ?: '/';
                $query = substr($nsPart, $qPos + 1);
            } else {
                $namespace = $nsPart ?: '/';
            }
        }

        return [
            'layer'     => 'socket.io',
            'type'      => 'CONNECT',
            'typeId'    => self::SIO_CONNECT,
            'namespace' => $namespace,
            'query'     => $query,
            'payload'   => $payload,
            'connId'    => $connId,
        ];
    }

    // --------------------------------------------------------
    //  41 - DISCONNECT
    //  格式: 1/ns,
    // --------------------------------------------------------
    private function parseDisconnect(string $rest, string $connId): array
    {
        $namespace = '/';
        if (!empty($rest)) {
            $commaPos = strpos($rest, ',');
            $namespace = $commaPos !== false
                ? substr($rest, 0, $commaPos) ?: '/'
                : ($rest ?: '/');
        }

        return [
            'layer'     => 'socket.io',
            'type'      => 'DISCONNECT',
            'typeId'    => self::SIO_DISCONNECT,
            'namespace' => $namespace,
            'connId'    => $connId,
        ];
    }

    // --------------------------------------------------------
    //  42 - EVENT
    //  格式: 2["event",arg1,arg2]
    //        2/ns,["event",arg1,arg2]
    // --------------------------------------------------------
    private function parseEvent(string $rest, string $connId): array
    {
        $namespace = '/';
        $jsonStr = $rest;
        $ackId = null;

        // 尝试提取 ack id (格式: 2123["event",...] 其中 123 是 ack id)
        // 先检查是否有 namespace
        $parsed = $this->extractNamespaceAndJson($rest);
        $namespace = $parsed['namespace'];
        $jsonStr = $parsed['json'];
        $ackId = $parsed['ackId'];

        $data = json_decode($jsonStr, true);
        $eventName = $data[0] ?? 'unknown';
        $args = array_slice($data ?? [], 1);

        return [
            'layer'     => 'socket.io',
            'type'      => 'EVENT',
            'typeId'    => self::SIO_EVENT,
            'namespace' => $namespace,
            'event'     => $eventName,
            'args'      => $args,
            'ackId'     => $ackId,
            'connId'    => $connId,
        ];
    }

    // --------------------------------------------------------
    //  43 - ACK
    //  格式: 3123["response"]    (123 是 ack id)
    //        3123/ns,["response"]
    // --------------------------------------------------------
    private function parseAck(string $rest, string $connId): array
    {
        // 提取 ack id (数字)
        preg_match('/^(\d+)/', $rest, $matches);
        $ackId = (int) ($matches[1] ?? 0);
        $afterId = substr($rest, strlen($matches[1] ?? ''));

        $namespace = '/';
        $jsonStr = $afterId;

        if (!empty($afterId)) {
            $parsed = $this->extractNamespaceAndJson($afterId);
            $namespace = $parsed['namespace'];
            $jsonStr = $parsed['json'];
        }

        $data = json_decode($jsonStr, true);

        return [
            'layer'     => 'socket.io',
            'type'      => 'ACK',
            'typeId'    => self::SIO_ACK,
            'namespace' => $namespace,
            'ackId'     => $ackId,
            'args'      => $data ?? [],
            'connId'    => $connId,
        ];
    }

    // --------------------------------------------------------
    //  44 - CONNECT_ERROR
    //  格式: 4{"message":"Not authorized"}
    // --------------------------------------------------------
    private function parseConnectError(string $rest, string $connId): array
    {
        $data = json_decode($rest, true);

        return [
            'layer'   => 'socket.io',
            'type'    => 'CONNECT_ERROR',
            'typeId'  => self::SIO_CONNECT_ERROR,
            'message' => $data['message'] ?? $rest,
            'data'    => $data,
            'connId'  => $connId,
        ];
    }

    // --------------------------------------------------------
    //  45 - BINARY_EVENT
    //  格式: 5<attCount>-["event",{"_placeholder":true,"num":0}]
    //        5<attCount>-/chat,["event",{"_placeholder":true,"num":0}]
    //
    //  后续跟随 <attCount> 个 "b<base64>" 帧
    // --------------------------------------------------------
    private function parseBinaryEvent(string $rest, string $connId): ?array
    {
        // 提取附件数量
        // rest = "1-[\"upload\",{...}]"  或  "2-/chat,[\"upload\",{...}]"
        $dashPos = strpos($rest, '-');
        if ($dashPos === false) {
            return [
                'layer'  => 'socket.io',
                'type'   => 'BINARY_EVENT',
                'typeId' => self::SIO_BINARY_EVENT,
                'error'  => 'Invalid BINARY_EVENT format: missing "-"',
                'raw'    => $rest,
                'connId' => $connId,
            ];
        }

        $attachmentCount = (int) substr($rest, 0, $dashPos);
        $afterDash = substr($rest, $dashPos + 1);

        $parsed = $this->extractNamespaceAndJson($afterDash);
        $namespace = $parsed['namespace'];
        $jsonStr = $parsed['json'];

        $data = json_decode($jsonStr, true);
        $eventName = $data[0] ?? 'unknown';
        $args = array_slice($data ?? [], 1);

        // 没有附件 → 直接返回
        if ($attachmentCount === 0) {
            return [
                'layer'     => 'socket.io',
                'type'      => 'BINARY_EVENT',
                'typeId'    => self::SIO_BINARY_EVENT,
                'namespace' => $namespace,
                'event'     => $eventName,
                'args'      => $args,
                'connId'    => $connId,
            ];
        }

        // 有待组装附件 → 存入 pending
        $this->pendingPackets[$connId] = [
            'layer'         => 'socket.io',
            'type'          => 'BINARY_EVENT',
            'typeId'        => self::SIO_BINARY_EVENT,
            'namespace'     => $namespace,
            'event'         => $eventName,
            'args'          => $args,
            'expectedCount' => $attachmentCount,
            'receivedCount' => 0,
            'attachments'   => [],
            'connId'        => $connId,
        ];

        return null; // 等待二进制附件
    }

    // --------------------------------------------------------
    //  46 - BINARY_ACK
    //  格式: 6<attCount>-<ackId>ns,[{_placeholder}]
    //  示例: 61-123["response",{"_placeholder":true,"num":0}]
    // --------------------------------------------------------
    private function parseBinaryAck(string $rest, string $connId): ?array
    {
        $dashPos = strpos($rest, '-');
        if ($dashPos === false) {
            return [
                'layer'  => 'socket.io',
                'type'   => 'BINARY_ACK',
                'typeId' => self::SIO_BINARY_ACK,
                'error'  => 'Invalid BINARY_ACK format',
                'raw'    => $rest,
                'connId' => $connId,
            ];
        }

        $attachmentCount = (int) substr($rest, 0, $dashPos);
        $afterDash = substr($rest, $dashPos + 1);

        // 提取 ack id
        preg_match('/^(\d+)/', $afterDash, $matches);
        $ackId = (int) ($matches[1] ?? 0);
        $afterAckId = substr($afterDash, strlen($matches[1] ?? ''));

        $parsed = $this->extractNamespaceAndJson($afterAckId);
        $namespace = $parsed['namespace'];
        $jsonStr = $parsed['json'];

        $data = json_decode($jsonStr, true);

        if ($attachmentCount === 0) {
            return [
                'layer'     => 'socket.io',
                'type'      => 'BINARY_ACK',
                'typeId'    => self::SIO_BINARY_ACK,
                'namespace' => $namespace,
                'ackId'     => $ackId,
                'args'      => $data ?? [],
                'connId'    => $connId,
            ];
        }

        $this->pendingPackets[$connId] = [
            'layer'         => 'socket.io',
            'type'          => 'BINARY_ACK',
            'typeId'        => self::SIO_BINARY_ACK,
            'namespace'     => $namespace,
            'ackId'         => $ackId,
            'args'          => $data ?? [],
            'expectedCount' => $attachmentCount,
            'receivedCount' => 0,
            'attachments'   => [],
            'connId'        => $connId,
        ];

        return null;
    }

    // ================================================================
    //  二进制重组
    // ================================================================

    /**
     * 所有附件到齐, 重组包
     */
    private function resolvePendingPacket(string $connId): array
    {
        $pending = $this->pendingPackets[$connId];
        unset($this->pendingPackets[$connId]);

        // 递归替换 _placeholder
        $resolvedArgs = [];
        foreach ($pending['args'] as $arg) {
            $resolvedArgs[] = $this->replacePlaceholders(
                $arg,
                $pending['attachments']
            );
        }
        $pending['args'] = $resolvedArgs;
        $pending['attachments'] = $pending['attachments']; // 保留原始附件

        return $pending;
    }

    /**
     * 递归替换 _placeholder 为实际二进制数据
     */
    private function replacePlaceholders($data, array $attachments)
    {
        if ($this->isPlaceholder($data)) {
            $num = $data['num'];
            return $attachments[$num] ?? $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->replacePlaceholders($value, $attachments);
            }
        }

        return $data;
    }

    private function isPlaceholder($value): bool
    {
        return is_array($value)
            && isset($value['_placeholder'])
            && $value['_placeholder'] === true
            && isset($value['num']);
    }

    // ================================================================
    //  辅助: 提取 namespace 和 JSON
    // ================================================================

    /**
     * 从字符串中分离 namespace 和 JSON 数组
     *
     * 输入示例:
     *   "/chat,[\"event\",{...}]"  → ns="/chat", json="[\"event\",{...}]"
     *   "[\"event\",{...}]"        → ns="/",      json="[\"event\",{...}]"
     *   "/,"                       → ns="/",      json=""
     *   "/admin?token=abc,[...]"   → ns="/admin", json="[...]"  (query 已在 CONNECT 处理)
     *
     * 注意: namespace 一定以 "/" 开头, JSON 一定以 "[" 开头
     */
    private function extractNamespaceAndJson(string $input): array
    {
        $namespace = '/';
        $json = $input;
        $ackId = null;

        if (empty($input)) {
            return ['namespace' => '/', 'json' => '', 'ackId' => null];
        }

        // 如果以 "[" 开头, 没有 namespace
        if ($input[0] === '[') {
            return ['namespace' => '/', 'json' => $input, 'ackId' => null];
        }

        // 以 "/" 开头 → 有 namespace
        // 找到 "[" 的位置
        $bracketPos = strpos($input, '[');
        if ($bracketPos !== false) {
            $nsPart = substr($input, 0, $bracketPos);
            $json = substr($input, $bracketPos);

            // nsPart = "/chat," → 去掉末尾逗号
            $namespace = rtrim($nsPart, ',') ?: '/';
        } else {
            // 没有 "[", 只有 namespace
            $namespace = rtrim($input, ',') ?: '/';
            $json = '';
        }

        return [
            'namespace' => $namespace,
            'json'      => $json,
            'ackId'     => $ackId,
        ];
    }

    // ================================================================
    //  编码: 发送 Socket.IO 包
    // ================================================================

    /**
     * 编码为 Socket.IO v4 文本帧
     */
    public function encodeEvent(
        string $event,
        array $args = [],
        string $namespace = '/'
    ): string {
        $data = json_encode(array_merge([$event], $args));
        $ns = ($namespace !== '/') ? $namespace . ',' : '';
        return "42{$ns}{$data}";
    }

    /**
     * 编码为 BINARY_EVENT 帧 + 附件帧
     *
     * @return array [主帧, 附件帧1, 附件帧2, ...]
     */
    public function encodeBinaryEvent(
        string $event,
        array $args = [],
        string $namespace = '/'
    ): array {
        $attachments = [];
        $processed = $this->extractBinaryFromArgs($args, $attachments);

        $data = json_encode(array_merge([$event], $processed));
        $ns = ($namespace !== '/') ? $namespace . ',' : '';
        $count = count($attachments);

        $frames = ["45{$count}-{$ns}{$data}"];

        foreach ($attachments as $binary) {
            $frames[] = 'b' . base64_encode($binary);
        }

        return $frames;
    }

    /**
     * 编码 CONNECT 包
     */
    public function encodeConnect(string $namespace = '/', array $query = []): string
    {
        $ns = ($namespace !== '/') ? $namespace : '';
        if (!empty($query)) {
            $ns .= '?' . http_build_query($query);
        }
        return "40{$ns},";
    }

    /**
     * 编码 ACK 包
     */
    public function encodeAck(int $ackId, array $args = [], string $namespace = '/'): string
    {
        $data = json_encode($args);
        $ns = ($namespace !== '/') ? $namespace . ',' : '';
        return "43{$ackId}{$ns}{$data}";
    }

    /**
     * 从参数中提取二进制数据, 替换为占位符
     */
    private function extractBinaryFromArgs(array $args, array &$attachments): array
    {
        foreach ($args as $i => $arg) {
            if (is_string($arg) && !mb_check_encoding($arg, 'UTF-8')) {
                $num = count($attachments);
                $attachments[] = $arg;
                $args[$i] = ['_placeholder' => true, 'num' => $num];
            } elseif (is_array($arg)) {
                $args[$i] = $this->extractBinaryRecursive($arg, $attachments);
            }
        }
        return $args;
    }

    private function extractBinaryRecursive(array $data, array &$attachments): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                $num = count($attachments);
                $attachments[] = $value;
                $data[$key] = ['_placeholder' => true, 'num' => $num];
            } elseif (is_array($value)) {
                $data[$key] = $this->extractBinaryRecursive($value, $attachments);
            }
        }
        return $data;
    }

    // ================================================================
    //  调试工具
    // ================================================================

    /**
     * 格式化输出解析结果
     */
    public static function dump(?array $packet): string
    {
        if ($packet === null) {
            return "[null - waiting for more data]\n";
        }

        $out = "┌─── " . ($packet['type'] ?? 'UNKNOWN') . " ───\n";

        if (isset($packet['layer'])) {
            $out .= "│ Layer:     {$packet['layer']}\n";
        }
        if (isset($packet['namespace']) && $packet['namespace'] !== '/') {
            $out .= "│ Namespace: {$packet['namespace']}\n";
        }
        if (isset($packet['event'])) {
            $out .= "│ Event:     {$packet['event']}\n";
        }
        if (isset($packet['ackId']) && $packet['ackId'] !== null) {
            $out .= "│ Ack ID:    {$packet['ackId']}\n";
        }
        if (isset($packet['args'])) {
            $out .= "│ Args:\n";
            foreach ($packet['args'] as $i => $arg) {
                if (is_string($arg) && !mb_check_encoding($arg, 'UTF-8')) {
                    $out .= "│   [{$i}] BINARY " . strlen($arg) . " bytes"
                         . " (hex: " . bin2hex(substr($arg, 0, 16)) . "...)\n";
                } else {
                    $out .= "│   [{$i}] " . json_encode($arg, JSON_UNESCAPED_UNICODE) . "\n";
                }
            }
        }
        if (isset($packet['payload'])) {
            $out .= "│ Payload:   " . json_encode($packet['payload']) . "\n";
        }
        if (isset($packet['message'])) {
            $out .= "│ Message:   {$packet['message']}\n";
        }
        if (isset($packet['data'])) {
            $out .= "│ Data:      " . json_encode($packet['data']) . "\n";
        }
        if (isset($packet['error'])) {
            $out .= "│ ERROR:     {$packet['error']}\n";
        }

        $out .= "└────────────────\n";
        return $out;
    }
}
