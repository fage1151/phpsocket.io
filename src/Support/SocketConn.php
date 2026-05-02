<?php

declare(strict_types=1);

namespace PhpSocketIO\Support;

use PhpSocketIO\Session;

class SocketConn
{
    public readonly string $id;
    public readonly string $transport;
    public readonly string $remoteAddress;
    public readonly array $headers;
    public readonly int $createdAt;
    public readonly bool $secure;

    private ?Session $session = null;

    public function __construct(Session $session)
    {
        $this->session = $session;
        $this->id = $session->sid;
        $this->transport = $session->transport;
        $this->remoteAddress = is_array($session->handshake) ? ($session->handshake['address'] ?? '') : '';
        $this->headers = is_array($session->handshake) ? ($session->handshake['headers'] ?? []) : [];
        $this->createdAt = $session->createdAt;
        $this->secure = is_array($session->handshake) ? ($session->handshake['secure'] ?? false) : false;
    }

    public function getTransport(): string
    {
        return $this->session?->transport ?? $this->transport;
    }

    public function getRemoteAddress(): string
    {
        if ($this->session && is_array($this->session->handshake)) {
            return $this->session->handshake['address'] ?? $this->remoteAddress;
        }
        return $this->remoteAddress;
    }

    public function close(): void
    {
        if ($this->session) {
            $this->session->close();
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'transport' => $this->getTransport(),
            'remoteAddress' => $this->getRemoteAddress(),
            'headers' => $this->headers,
            'createdAt' => $this->createdAt,
            'secure' => $this->secure,
        ];
    }
}
