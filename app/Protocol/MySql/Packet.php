<?php

declare(strict_types=1);

namespace App\Protocol\MySql;

use App\Proxy\Client\ClientType;

class Packet
{
    private int $length;
    private int $sequenceId;
    private string $payload;

    public $toBytes;

    public function __construct(int $length, int $sequenceId, string $payload)
    {
        $this->length = $length;
        $this->sequenceId = $sequenceId;
        $this->payload = $payload;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function getSequenceId(): int
    {
        return $this->sequenceId;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    /**
     * 分析客户端能力标志来识别客户端类型
     */
    public function analyzeCapabilities(int $charset): ClientType
    {
        $capabilities = $this->getCapabilities();
        // Java Connector/J 的特征：
        // - 通常支持 PLUGIN_AUTH
        // - 字符集通常是 utf8mb4 (45) 或更高
        if (($capabilities & 0x00080000) && // CLIENT_PLUGIN_AUTH
            ($charset >= 45 && $charset <= 255)) {
            return ClientType::JAVA_CONNECTOR;
        }

        // PHP PDO 的特征：
        // - 支持 MULTI_STATEMENTS
        // - 字符集通常是 utf8 (33) 或 utf8mb4 (45)
        if (($capabilities & 0x00010000) && // CLIENT_MULTI_STATEMENTS
            ($charset >= 33 && $charset <= 45)) {
            return ClientType::PHP_PDO;
        }

        // MySQL 原生客户端的特征：
        // - 支持基础功能，但通常不设置高级能力标志
        if (($capabilities & 0x00000001) && // CLIENT_LONG_PASSWORD
            !($capabilities & 0x00080000)) { // 没有 CLIENT_PLUGIN_AUTH
            return ClientType::MYSQL_CLIENT;
        }

        return ClientType::UNKNOWN;
    }

    public function getCapabilities()
    {
        return unpack('V', substr($this->getPayload(), 0, 4))[1];
    }

    public static function fromString(string $data): self
    {
        if (strlen($data) < 4) {
            throw new \InvalidArgumentException('数据包头部至少4字节');
        }

        $length = unpack('V', substr($data, 0, 3) . "\x00")[1];
        $sequenceId = ord($data[3]);
        $payload = substr($data, 4);

        return new self($length, $sequenceId, $payload);
    }

    public function toBytes(): string
    {
        if ($this->toBytes) {
            return $this->toBytes;
        }

        $len = pack('V', $this->length);
        return substr($len, 0, 3) . chr($this->sequenceId) . $this->payload;
    }

    public function getCommand(): int
    {
        if (empty($this->payload)) {
            return 0;
        }
        return ord($this->payload[0]);
    }

    public function getPayloadWithoutCommand(): string
    {
        if (empty($this->payload)) {
            return '';
        }
        return substr($this->payload, 1);
    }

    public static function create(int $sequenceId, string $payload): self
    {
        return new self(strlen($payload), $sequenceId, $payload);
    }
}
