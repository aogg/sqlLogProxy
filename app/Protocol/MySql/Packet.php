<?php

declare(strict_types=1);

namespace App\Protocol\MySql;

class Packet
{
    private int $length;
    private int $sequenceId;
    private string $payload;

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
