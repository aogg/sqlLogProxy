<?php

declare(strict_types=1);

namespace Src\Protocol;

use Swoole\Coroutine\Socket;

class ConnectionContext
{
    private string $clientId;
    private string $clientIp;
    private int $clientPort;
    private ?Socket $mysqlSocket = null;
    private string $transactionId = '';
    private bool $inTransaction = false;
    private array $dsnParams = [];
    private ?string $targetHost = null;
    private ?int $targetPort = null;

    public function __construct(string $clientId, string $clientIp, int $clientPort)
    {
        $this->clientId = $clientId;
        $this->clientIp = $clientIp;
        $this->clientPort = $clientPort;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getClientIp(): string
    {
        return $this->clientIp;
    }

    public function getClientPort(): int
    {
        return $this->clientPort;
    }

    public function getMysqlSocket(): ?Socket
    {
        return $this->mysqlSocket;
    }

    public function setMysqlSocket(Socket $socket): void
    {
        $this->mysqlSocket = $socket;
    }

    public function isInTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function setInTransaction(bool $inTransaction): void
    {
        $this->inTransaction = $inTransaction;
    }

    public function getTransactionId(): string
    {
        if (!$this->transactionId) {
            $this->transactionId = uniqid('txn_', true);
        }
        return $this->transactionId;
    }

    public function resetTransaction(): void
    {
        $this->transactionId = '';
        $this->inTransaction = false;
    }

    public function getDsnParams(): array
    {
        return $this->dsnParams;
    }

    public function setDsnParams(array $params): void
    {
        $this->dsnParams = $params;
    }

    public function getTargetHost(): ?string
    {
        return $this->targetHost;
    }

    public function setTargetHost(string $host): void
    {
        $this->targetHost = $host;
    }

    public function getTargetPort(): ?int
    {
        return $this->targetPort;
    }

    public function setTargetPort(int $port): void
    {
        $this->targetPort = $port;
    }

    public function __toString(): string
    {
        return sprintf('%s:%d', $this->clientIp, $this->clientPort);
    }
}
