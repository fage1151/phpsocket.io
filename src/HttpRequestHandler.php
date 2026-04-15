<?php

namespace PhpSocketIO;

/**
 * HTTP请求处理器 - 解析和分发HTTP请求到相应的处理器
 */
class HttpRequestHandler
{
    private $serverManager;
    private $pollingHandler;
    private $engineIoHandler;

    public function __construct(ServerManager $serverManager, PollingHandler $pollingHandler, EngineIOHandler $engineIoHandler)
    {
        $this->serverManager = $serverManager;
        $this->pollingHandler = $pollingHandler;
        $this->engineIoHandler = $engineIoHandler;
    }

    /**
     * 处理HTTP消息请求
     * 直接使用Workerman自动解析的$req对象，无需手动解析
     */
    public function handleMessage(\Workerman\Connection\TcpConnection $connection, $req): void
    {
        // 检查是否为WebSocket直连握手请求（包含/socket.io/路径且transport=websocket）
        if (!isset($connection->isWs) && $this->isDirectWebSocketHandshake($req)) {
            $this->handleDirectWebSocketHandshake($connection, $req);
            return;
        }

        // WebSocket消息处理（已建立的WebSocket连接）
        if (isset($connection->isWs) && $connection->isWs) {
            $this->handleWebSocketMessage($connection, $req);
            return;
        }

        // HTTP升级到WebSocket
        if ($this->isWebSocketUpgradeRequest($req)) {
            $this->handleUpgrade($connection, $req);
            return;
        }

        // 普通HTTP轮询请求
        $this->pollingHandler->handlePolling($connection, $req);
    }

    /**
     * 处理WebSocket消息
     * Workerman的onMessage回调已经自动解析了WebSocket帧，$data参数就是解析后的消息内容
     */
    private function handleWebSocketMessage(\Workerman\Connection\TcpConnection $connection, $data): void
    {
        // 从connection获取session
        if (!isset($connection->sid)) {
            // WebSocket需要SID
            echo "[error] Failed to create session for direct WebSocket connection\n";
            $connection->close();
            return;
        }

        $session = Session::get($connection->sid);
        if (!$session) {
            echo "[error] Session not found for sid: {$connection->sid}\n";
            return;
        }
        
        // 调试connection状态
        echo "[debug] WebSocket消息处理 - Session状态: ";
        echo "sid={$session->sid}, ";
        echo "isWs=" . ($session->isWs ? 'true' : 'false') . ", ";
        echo "connection=" . ($session->connection ? 'exists' : 'null') . "\n";
        
        // 如果session中的connection为空或无效，更新为当前有效的connection
        if (!$session->connection || !method_exists($session->connection, 'send')) {
            echo "[fix] 重新绑定有效connection到Session\n";
            $session->connection = $connection;
            $session->isWs = true;
            $session->transport = 'websocket';
        }
        
        // 检查数据内容
        if (empty($data)) {
            echo "[websocket] 接收到空数据包，可能是二进制数据帧的一部分\n";
            return;
        }
        
        // 检查是否为二进制数据
        $isBinary = $this->isBinaryFrame($data);
        if (!$isBinary) {
            // 首先检查是否是Engine.IO二进制包（b开头的包）
            if ($data[0] === 'b') {
                echo "[websocket] received Engine.IO binary packet\n";
                $this->processWebSocketData($session, $data);
                return;
            }
            
            // 检查数据内容中是否包含Socket.IO的二进制占位符
            if (strpos($data, '"_placeholder"') !== false && strpos($data, '"num"') !== false) {
                echo "[websocket] 检测到二进制数据占位符包：" . substr($data, 0, 200) . "\n";
                
                // 存储占位符包信息
                $this->storePendingPlaceholder($session, $data);
                
                // 不立即处理，等待所有二进制附件到达后再处理
                return;
            }
            
            // 检查是否是二进制事件包（格式如451-或451/）
            if (preg_match('/^4(\d)(\d+)/', $data, $matches) || preg_match('/^(\d)(\d+)-/', $data, $matches)) {
                echo "[websocket] 检测到二进制事件包：" . substr($data, 0, 200) . "\n";
                $this->storePendingPlaceholder($session, $data);
                return;
            }
            
            // 普通文本数据处理
            echo "[websocket] 处理文本数据包：" . substr($data, 0, 200) . "\n";
            $this->processWebSocketData($session, $data);
        } else {
            // 处理二进制数据
            $binaryData = is_string($data) ? $data : (string)$data;
            echo "[websocket] 收到二进制数据, 大小: " . strlen($binaryData) . " 字节\n";
            echo "[websocket] 二进制数据内容（前100字节）: " . bin2hex(substr($binaryData, 0, min(100, strlen($binaryData)))) . "\n";
            
            // 真正的二进制数据处理
            $this->handleBinaryData($session, $binaryData);
        }
    }
    
    /**
     * 判断是否为二进制帧
     * Workerman WebSocket 中, 二进制帧数据可能不含可打印 ASCII 开头
     */
    private function isBinaryFrame(string $data): bool
    {
        if (empty($data)) return false;

        $first = $data[0];

        // Socket.IO/Engine.IO 文本帧以数字或 'b' 开头
        // 如果第一个字节不是可识别的文本帧前缀, 视为二进制
        if ($first === '0' || $first === '1' || $first === '2' ||
            $first === '3' || $first === '4' || $first === '5' ||
            $first === '6' || $first === 'b') {
            return false;
        }

        return true;
    }
    /**
     * 处理WebSocket数据（文本消息）
     */
    private function processWebSocketData(Session $session, string $data): void
    {
        // 将解析后的消息传递给Engine.IO处理器
        if ($this->engineIoHandler) {
            $this->engineIoHandler->processWebSocketData($session, $data);
        } else {
            echo "[error] Engine.IO handler not available for WebSocket data processing\n";
        }
    }
    
    /**
     * 处理二进制数据
     * Socket.IO v4协议中，二进制数据需要配合占位符包一起处理
     */
    private function handleBinaryData(Session $session, string $data): void
    {
        // 检查session是否有等待的占位符包
        if ($session->pendingBinaryPlaceholder !== null && $session->pendingBinaryCount > 0) {
            // 收集二进制附件
            $attachmentIndex = count($session->pendingBinaryAttachments);
            $session->pendingBinaryAttachments[$attachmentIndex] = $data;
            
            // 检查是否所有附件都已收到
            if (count($session->pendingBinaryAttachments) >= $session->pendingBinaryCount) {
                // 合并处理所有附件
                $this->combineAllAttachments($session);
                
                // 清理
                $session->pendingBinaryAttachments = [];
                $session->pendingBinaryPlaceholder = null;
                $session->pendingBinaryCount = 0;
            }
        } else {
            // 没有占位符包，作为独立二进制数据处理
            // 存储二进制数据并设置超时清理
            $session->pendingBinaryAttachments[0] = $data;
            
            // 设置超时清理，避免内存泄漏
            \Workerman\Timer::add(5, function() use ($session) {
                if (!empty($session->pendingBinaryAttachments) && $session->pendingBinaryCount === 0) {
                    $session->pendingBinaryAttachments = [];
                }
            }, [], false);
        }
    }
    
    /**
     * 合并所有二进制附件并处理
     */
    private function combineAllAttachments(Session $session): void
    {
        $placeholderInfo = $session->pendingBinaryPlaceholder;
        $originalPacket = $placeholderInfo['packet'] ?? null;
        
        if (!$originalPacket) {
            echo "[binary] no original packet found for placeholder\n";
            return;
        }
        
        echo "[binary] combining all " . count($session->pendingBinaryAttachments) . " attachments\n";
        
        if ($this->engineIoHandler) {
            // 传递所有附件给 EngineIOHandler 处理
            $this->engineIoHandler->processAllBinaryAttachments($session, $originalPacket, $session->pendingBinaryAttachments);
        }
    }
    
    /**
     * 临时存储占位符包用于后续二进制数据处理
     */
    private function storePendingPlaceholder(Session $session, string $data): void
    {
        // 提取二进制附件数量 - 格式如 "51-" 中的 "1" 表示1个二进制附件
        $binaryCount = $this->extractBinaryAttachmentCount($data);
        
        $session->pendingBinaryPlaceholder = [
            'packet' => $data,
            'timestamp' => time()
        ];
        $session->pendingBinaryCount = $binaryCount;
        
        echo "[binary] stored placeholder packet for sid: {$session->sid}, expecting {$binaryCount} binary attachments\n";
        echo "[binary] current pending attachments: " . count($session->pendingBinaryAttachments) . "\n";
        
        // 检查是否已经有二进制附件到达（处理时序问题）
        if (count($session->pendingBinaryAttachments) >= $binaryCount) {
            echo "[binary] binary attachments already received, processing immediately\n";
            $this->combineAllAttachments($session);
            // 清理
            $session->pendingBinaryAttachments = [];
            $session->pendingBinaryPlaceholder = null;
            $session->pendingBinaryCount = 0;
        }
    }
    
    /**
     * 从包中提取二进制附件数量
     * 格式如: 51-/chat,["blob",{"_placeholder":true,"num":0}] 中的 "51" 表示 BINARY_EVENT, 1个附件
     */
    private function extractBinaryAttachmentCount(string $data): int
    {
        // 先尝试匹配 Engine.IO + Socket.IO 格式：451-... 或 451/...
        if (preg_match('/^4(\d)(\d+)/', $data, $matches)) {
            // 第一个数字是Socket.IO类型，第二个数字是附件数量
            return (int)$matches[2];
        }
        
        // 再尝试匹配纯Socket.IO格式：51-... 或 51/...
        if (preg_match('/^(\d)(\d+)/', $data, $matches)) {
            // 第一个数字是Socket.IO类型，第二个数字是附件数量
            return (int)$matches[2];
        }
        
        // 如果没有找到，尝试从占位符中推断（取最大的num+1）
        if (preg_match_all('/"num":\s*(\d+)/', $data, $matches)) {
            $nums = array_map('intval', $matches[1]);
            return max($nums) + 1;
        }
        
        return 1; // 默认至少有一个附件
    }
    
    /**
     * 从数据中提取占位符编号
     */
    private function extractPlaceholderNum(string $data): int
    {
        if (preg_match('/"num":\s*(\d+)/', $data, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }
    
    /**
     * 处理WebSocket直连连接（无SID）
     */
    private function handleDirectWebSocketConnection(\Workerman\Connection\TcpConnection $connection): ?string
    {
        try {
            // 生成新的会话ID
            $sid = Session::generateSid();
            
            // 创建新的会话
            $session = new Session($sid);
            $session->transport = 'websocket';
            $session->isWs = true;
            $session->connection = $connection;
            
            // 保存会话到全局缓存
            $session->save();
            
            // 设置连接属性
            $connection->sid = $sid;
            $connection->isWs = true;
            
            // 发送Engine.IO握手响应
            $this->engineIoHandler->sendHandshake($connection, $session);
            
            echo "[websocket] direct connection established, sid={$sid}\n";
            return $sid;
            
        } catch (Exception $e) {
            echo "[error] Failed to handle direct WebSocket connection: " . $e->getMessage() . "\n";
            return null;
        }
    }
    
    /**
     * 检查是否为WebSocket直连握手请求
     */
    private function isDirectWebSocketHandshake($req): bool
    {
        return strpos($req->path(), '/socket.io/') !== false
               && $req->get('transport') === 'websocket'
               && !($req->get('sid'));
    }
    
    /**
     * 处理WebSocket直接握手（无SID的直连）
     */
    private function handleDirectWebSocketHandshake(\Workerman\Connection\TcpConnection $connection, $req): void
    {
        try {
            echo "[websocket] handling direct WebSocket handshake without SID\n";
            
            // 生成新的会话ID
            $sid = Session::generateSid();
            
            // 创建新的会话（构造函数会自动保存）
            $session = new Session($sid);
            $session->transport = 'websocket';
            $session->isWs = true;
            $session->connection = $connection;
            
            // 执行WebSocket握手
            $this->performWebSocketHandshake($connection, $req, $sid);
            
            // 延迟发送Engine.IO握手响应（确保WebSocket协议升级完成）
            $self = $this;
            \Workerman\Timer::add(0.1, function() use ($self, $connection, $session) {
                $self->engineIoHandler->sendHandshake($connection, $session);
            }, [], false);
            
            echo "[websocket] direct WebSocket connection established, sid={$sid}\n";
            
        } catch (Exception $e) {
            echo "[error] Failed to handle direct WebSocket handshake: " . $e->getMessage() . "\n";
            $connection->close();
        }
    }
    
    /**
     * 执行WebSocket握手
     */
    private function performWebSocketHandshake(\Workerman\Connection\TcpConnection $connection, $req, string $sid): void
    {
        // 发送HTTP WebSocket升级握手响应
        $handshakeResponse = $this->generateWebSocketHandshakeResponse($req->header('sec-websocket-key'));
        $connection->send($handshakeResponse, true);
        // blob or arraybuffer
        if (empty($connection->websocketType)) {
            $connection->websocketType = "\x81";
        }
        // 设置workerman协议为WebSocket
        $connection->protocol = \Workerman\Protocols\Websocket::class;
        $connection->context->websocketHandshake = true;
                    // Websocket data buffer.
        $connection->context->websocketDataBuffer = '';
        // Current websocket frame length.
        $connection->context->websocketCurrentFrameLength = 0;
        // Current websocket frame data.
        $connection->context->websocketCurrentFrameBuffer = '';
        // 设置连接属性
        $connection->sid = $sid;
        $connection->isWs = true;
    }



    /**
     * 检查是否为WebSocket升级请求
     * 直接检查Workerman的$req对象
     */
    private function isWebSocketUpgradeRequest($req): bool
    {
        $headers = $req->header();
        $isUpgrade = isset($headers['upgrade']) 
                  && strtolower($headers['upgrade']) === 'websocket';
        
        // 检查WebSocket协议握手必需的头信息
        $hasKey = isset($headers['sec-websocket-key']);
        $hasVersion = isset($headers['sec-websocket-version']);
        
        return $isUpgrade && $hasKey && $hasVersion;
    }

    /**
     * 处理HTTP升级到WebSocket
     */
    private function handleUpgrade(\Workerman\Connection\TcpConnection $connection, $req): void
    {
        // 使用Workerman的原生WebSocket升级
        $connection->isWs = true;
        

        $this->upgradeToWebSocket($connection, $req);
    }

    /**
     * 执行WebSocket升级
     */
    public function upgradeToWebSocket(\Workerman\Connection\TcpConnection $connection, $req): bool
    {
        $sid = $req->get('sid');
        if (!$sid) {
            echo "[error] Missing session ID in WebSocket upgrade\n";
            return false;
        }

        $session = Session::get($sid);
        if (!$session) {
            echo "[error] Session not found for upgrade: {$sid}\n";
            return false;
        }

        // 标记为WebSocket连接
        $connection->isWs = true;
        $connection->sid = $sid;
        $session->connection = $connection;
        $session->transport = 'websocket';

        // 执行WebSocket握手
        $this->performWebSocketHandshake($connection, $req, $sid);
        
        // 清除轮询队列并升级到WebSocket
        $session->flush();
        echo "[upgrade] session upgraded to WebSocket: {$sid}\n";

        return true;
    }

    /**
     * 生成WebSocket握手响应
     */
    private function generateWebSocketHandshakeResponse(string $key): string
    {
        $newKey = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
            // Handshake response data.
        $handshakeMessage = "HTTP/1.1 101 Switching Protocol\r\n"
                . "Upgrade: websocket\r\n"
                . "Sec-WebSocket-Version: 13\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: " . $newKey . "\r\n"
                . "Access-Control-Allow-Origin: *\r\n";
        $handshakeMessage .= "\r\n";

        return $handshakeMessage;
    }



    /**
     * 发送WebSocket数据帧
     */
    public static function sendWsFrame(\Workerman\Connection\TcpConnection $connection, string $data, bool $isBinary = false): bool
    {
        if (!isset($connection->isWs) || !$connection->isWs) {
            return false;
        }

        try {
            if ($isBinary) {
                // 二进制数据帧发送
                $connection->websocketType = "\x82";
                $connection->send($data); // 发送二进制数据
            } else {

                $connection->websocketType = "\x81";
                // 文本数据帧发送
                $connection->send($data);
            }
            return true;
        } catch (\Exception $e) {
            echo "[error] Failed to send WebSocket frame: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * 检查连接是否正常
     */
    public static function isConnectionValid(\Workerman\Connection\TcpConnection $connection): bool
    {
        try {
            $status = $connection->getStatus();
            return $status !== null && $status !== 'closed' && $status !== 'closing';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 关闭连接时清理相关资源
     */
    public function handleConnectionClose(\Workerman\Connection\TcpConnection $connection): void
    {
        if (isset($connection->sid)) {
            $sid = $connection->sid;
            $session = Session::get($sid);
            
            if ($session) {
        // 离开所有房间
        if ($this->engineIoHandler && method_exists($this->engineIoHandler, 'getRoomManager')) {
            $roomManager = $this->engineIoHandler->getRoomManager();
            if ($roomManager) {
                $roomManager->removeSession($sid);
            }
        }
        
        // 触发disconnect事件
        $socket = ['nsp' => '/', 'id' => $sid];
        if ($this->engineIoHandler && method_exists($this->engineIoHandler, 'getEventHandler')) {
            $eventHandler = $this->engineIoHandler->getEventHandler();
            if ($eventHandler) {
                $eventHandler->dispatchEvent($session, 'disconnect', null, $socket);
            }
        }
                
                // 删除session
                Session::remove($sid);
                echo "[disconnect] Connection closed, sid: {$sid}\n";
            }
        }
    }

    /**
     * 将Workerman请求对象转换为数组格式
     */
    private function parseRequestToArray($req): array
    {
        return [
            'method' => $req->method ?? 'GET',
            'path' => $req->path ?? '/',
            'query' => $req->get ?? [],
            'headers' => $req->header ?? [],
            'body' => $req->rawBody ?? '',
            'connection' => $req->connection ?? null,
            'server' => $req->server ?? []
        ];
    }
}