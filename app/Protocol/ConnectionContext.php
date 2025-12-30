<?php

declare(strict_types=1);

namespace App\Protocol;

use Swoole\Coroutine\Socket;
use Swoole\Lock;
use App\Proxy\Client\ClientType;

class ConnectionContext
{
    private int $clientId;
    private string $clientIp;
    private int $clientPort;
    private ?Socket $mysqlSocket = null;
    private string $transactionId = '';
    private bool $inTransaction = false;
    private array $dsnParams = [];
    private ?string $targetHost = null;
    private ?int $targetPort = null;
    private bool $tlsEnabled = false;
    private ?string $clientTlsPeerCN = null;
    private ?Lock $socketLock = null;
    private bool $authenticated = false;
    private ?string $username = null;
    private ?string $database = null;
    private bool $sslRequested = false;
    private string $authPluginData = '';
    private ClientType $clientType = ClientType::UNKNOWN;
    private ?string $clientVersion = null;
    private array $clientCapabilities = [];

    public function __construct(int $clientId, string $clientIp, int $clientPort)
    {
        $this->clientId = $clientId;
        $this->clientIp = $clientIp;
        $this->clientPort = $clientPort;
    }

    public function getClientId(): string
    {
        return (string) $this->clientId;
    }

    public function getThreadId(): int
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
        if (!$this->inTransaction) {
            return '';
        }
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

    public function getSocketLock(): Lock
    {
        if ($this->socketLock === null) {
            $this->socketLock = new Lock(SWOOLE_MUTEX);
        }
        return $this->socketLock;
    }

    public function isTlsEnabled(): bool
    {
        return $this->tlsEnabled;
    }

    public function setTlsEnabled(bool $tlsEnabled): void
    {
        $this->tlsEnabled = $tlsEnabled;
    }

    public function getClientTlsPeerCN(): ?string
    {
        return $this->clientTlsPeerCN;
    }

    public function setClientTlsPeerCN(?string $clientTlsPeerCN): void
    {
        $this->clientTlsPeerCN = $clientTlsPeerCN;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function setAuthenticated(bool $authenticated): void
    {
        $this->authenticated = $authenticated;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): void
    {
        $this->username = $username;
    }

    public function getDatabase(): ?string
    {
        return $this->database;
    }

    public function setDatabase(?string $database): void
    {
        $this->database = $database;
    }

    public function isSslRequested(): bool
    {
        return $this->sslRequested;
    }

    public function setSslRequested(bool $sslRequested): void
    {
        $this->sslRequested = $sslRequested;
    }

    public function getAuthPluginData(): string
    {
        return $this->authPluginData;
    }

    public function setAuthPluginData(string $authPluginData): void
    {
        $this->authPluginData = $authPluginData;
    }

    public function getClientType(): ClientType
    {
        return $this->clientType;
    }

    public function setClientType(ClientType $clientType): void
    {
        $this->clientType = $clientType;
    }

    public function getClientVersion(): ?string
    {
        return $this->clientVersion;
    }

    public function setClientVersion(?string $clientVersion): void
    {
        $this->clientVersion = $clientVersion;
    }

    public function getClientCapabilities(): array
    {
        return $this->clientCapabilities;
    }

    public function setClientCapabilities(array $capabilities): void
    {
        $this->clientCapabilities = $capabilities;
    }

    public function __toString(): string
    {
        return sprintf('%s:%d', $this->clientIp, $this->clientPort);
    }
}
