<?php

declare(strict_types=1);

namespace PhpSocketIO\Transport;

use PhpSocketIO\Session;
use PhpSocketIO\Support\ServerManager;
use Psr\Log\LoggerInterface;

abstract class AbstractTransportHandler
{
    protected ServerManager $serverManager;
    protected ?LoggerInterface $logger = null;

    public function __construct(ServerManager $serverManager)
    {
        $this->serverManager = $serverManager;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function extractClientIp(\Workerman\Connection\TcpConnection $connection, mixed $req): ?string
    {
        if ($req && method_exists($req, 'header')) {
            $xRealIp = $req->header('x-real-ip');
            if ($xRealIp) {
                return $xRealIp;
            }
            $xForwardedFor = $req->header('x-forwarded-for');
            if ($xForwardedFor) {
                $ips = explode(',', $xForwardedFor);
                return trim($ips[0]);
            }
        }

        if (method_exists($connection, 'getRemoteIp')) {
            return $connection->getRemoteIp();
        }

        return null;
    }

    protected function buildHandshakeData(\Workerman\Connection\TcpConnection $connection, mixed $req): array
    {
        $headers = [];
        if ($req && method_exists($req, 'header')) {
            $headerNames = [
                'host', 'user-agent', 'accept', 'accept-language', 'accept-encoding',
                'origin', 'referer', 'cookie', 'authorization', 'x-requested-with',
                'sec-websocket-version', 'sec-websocket-key', 'sec-websocket-extensions',
                'sec-websocket-protocol',
            ];
            foreach ($headerNames as $name) {
                $value = $req->header($name);
                if ($value !== null) {
                    $headers[$name] = $value;
                }
            }
        }

        $query = [];
        if ($req && method_exists($req, 'get')) {
            $queryParams = ['transport', 'sid', 'EIO', 't'];
            foreach ($queryParams as $param) {
                $value = $req->get($param);
                if ($value !== null) {
                    $query[$param] = $value;
                }
            }
        }

        $origin = $headers['origin'] ?? $headers['referer'] ?? null;
        $host = $headers['host'] ?? '';
        $xdomain = false;
        if ($origin && $host) {
            $originHost = parse_url($origin, PHP_URL_HOST);
            $xdomain = $originHost !== $host;
        }

        $secure = false;
        if (property_exists($connection, 'transport')) {
            $secure = $connection->transport === 'ssl';
        }

        $url = null;
        if ($req && method_exists($req, 'path')) {
            $url = $req->path();
            if (method_exists($req, 'queryString') && $req->queryString()) {
                $url .= '?' . $req->queryString();
            }
        }

        return [
            'headers' => $headers,
            'address' => $this->extractClientIp($connection, $req),
            'xdomain' => $xdomain,
            'secure' => $secure,
            'url' => $url,
            'query' => $query,
        ];
    }

    protected function getCorsHeaders(): array
    {
        $headers = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET,POST,OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type,Authorization',
        ];

        $corsConfig = $this->serverManager->getCors();
        if ($corsConfig === null) {
            return $headers;
        }

        if (isset($corsConfig['origin'])) {
            $headers['Access-Control-Allow-Origin'] = $corsConfig['origin'];
        }

        if (isset($corsConfig['methods'])) {
            $headers['Access-Control-Allow-Methods'] = is_array($corsConfig['methods'])
                ? implode(',', $corsConfig['methods'])
                : $corsConfig['methods'];
        }

        if (isset($corsConfig['allowedHeaders'])) {
            $headers['Access-Control-Allow-Headers'] = is_array($corsConfig['allowedHeaders'])
                ? implode(',', $corsConfig['allowedHeaders'])
                : $corsConfig['allowedHeaders'];
        }

        if (isset($corsConfig['credentials']) && $corsConfig['credentials']) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $headers;
    }
}

