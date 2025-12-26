<?php

declare(strict_types=1);

namespace Src\Protocol\MySql;

class Response
{
    public static function isOkPacket(Packet $packet): bool
    {
        $payload = $packet->getPayload();
        return isset($payload[0]) && ord($payload[0]) === Command::OK_PACKET;
    }

    public static function isErrorPacket(Packet $packet): bool
    {
        $payload = $packet->getPayload();
        return isset($payload[0]) && ord($payload[0]) === Command::ERR_PACKET;
    }

    public static function isEofPacket(Packet $packet): bool
    {
        $payload = $packet->getPayload();
        return isset($payload[0]) && ord($payload[0]) === Command::EOF_PACKET;
    }

    public static function isResultSetPacket(Packet $packet): bool
    {
        $payload = $packet->getPayload();
        return !self::isOkPacket($packet) && !self::isErrorPacket($packet) && !self::isEofPacket($packet);
    }

    public static function parseOkPacket(Packet $packet): array
    {
        if (!self::isOkPacket($packet)) {
            throw new \InvalidArgumentException('不是OK包');
        }

        $payload = $packet->getPayload();
        $offset = 1; // skip OK byte

        $affectedRows = self::decodeLength($payload, $offset);
        $lastInsertId = self::decodeLength($payload, $offset);
        $statusFlags = unpack('v', substr($payload, $offset, 2))[1];
        $offset += 2;
        $warnings = unpack('v', substr($payload, $offset, 2))[1];

        return [
            'affected_rows' => $affectedRows,
            'last_insert_id' => $lastInsertId,
            'status_flags' => $statusFlags,
            'warnings' => $warnings,
        ];
    }

    public static function parseErrorPacket(Packet $packet): array
    {
        if (!self::isErrorPacket($packet)) {
            throw new \InvalidArgumentException('不是ERROR包');
        }

        $payload = $packet->getPayload();
        $offset = 1; // skip ERR byte

        $errorCode = unpack('v', substr($payload, $offset, 2))[1];
        $offset += 2;

        $sqlState = '';
        if (isset($payload[$offset]) && $payload[$offset] === '#') {
            $offset++; // skip '#'
            $sqlState = substr($payload, $offset, 5);
            $offset += 5;
        }

        $errorMessage = substr($payload, $offset);

        return [
            'error_code' => $errorCode,
            'sql_state' => $sqlState,
            'error_message' => $errorMessage,
        ];
    }

    public static function parseResultSetHeader(Packet $packet): array
    {
        $payload = $packet->getPayload();
        $offset = 0;

        $numColumns = self::decodeLength($payload, $offset);

        return [
            'num_columns' => $numColumns,
        ];
    }

    private static function decodeLength(string $data, int &$offset): int
    {
        $firstByte = ord($data[$offset]);
        $offset++;

        if ($firstByte < 251) {
            return $firstByte;
        } elseif ($firstByte === 0xfc) {
            $value = unpack('v', substr($data, $offset, 2))[1];
            $offset += 2;
            return $value;
        } elseif ($firstByte === 0xfd) {
            $value = unpack('V', substr($data, $offset, 3) . "\x00")[1];
            $offset += 3;
            return $value;
        } elseif ($firstByte === 0xfe) {
            $value = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;
            return $value;
        } else {
            return $firstByte;
        }
    }

    public static function decodeLengthEncoded(string $data): int
    {
        $offset = 0;
        return self::decodeLength($data, $offset);
    }
}
