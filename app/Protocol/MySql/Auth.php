<?php

declare(strict_types=1);

namespace App\Protocol\MySql;

use App\Exception\ProxyException;

class Auth
{
    // 与 Handshake 类保持一致的 capabilities
    public const CAPABILITIES = 0x00aff7df;
    private const MAX_PACKET_SIZE = 0xffffff;

    public static $OK = [7, 0, 0, 1, 0, 0, 0, 2, 0, 0, 0];

    public static function generateAuthData(int $length = 20): string
    {
        $data = '';
        for ($i = 0; $i < $length; $i++) {
            $data .= chr(mt_rand(33, 126));
        }
        return $data;
    }

    public static function calculateAuthResponse(string $password, string $authPluginData): string
    {
        if ($password === '') {
            return '';
        }

        $hash1 = sha1($password, true);
        $hash2 = sha1($hash1, true);
        $hash3 = sha1($authPluginData . $hash2, true);
        $response = $hash1 ^ $hash3;

        return $response;
    }

    public static function parseHandshakeResponse(Packet $packet): array
    {
        $payload = $packet->getPayload();
        $offset = 0;
        $payloadLength = strlen($payload);

        // Capability Flags (4 bytes)
        if ($offset + 4 > $payloadLength) {
            throw new \RuntimeException('解析握手响应失败：数据包长度不足，无法读取 Capability Flags');
        }
        $capabilities = unpack('V', substr($payload, $offset, 4))[1];
        $offset += 4;

        // Max Packet Size (4 bytes)
        if ($offset + 4 > $payloadLength) {
            throw new \RuntimeException('解析握手响应失败：数据包长度不足，无法读取 Max Packet Size');
        }
        $maxPacketSize = unpack('V', substr($payload, $offset, 4))[1];
        $offset += 4;

        // Character Set (1 byte)
        if ($offset + 1 > $payloadLength) {
            throw new \RuntimeException('解析握手响应失败：数据包长度不足，无法读取 Character Set');
        }
        $charset = ord($payload[$offset]);
        $offset += 1;

        // Reserved (23 bytes)
        if ($offset + 23 > $payloadLength) {
            throw new \RuntimeException('解析握手响应失败：数据包长度不足，无法读取 Reserved');
        }
        $offset += 23;

        // Username (NUL terminated string)
        $username = '';
        while (isset($payload[$offset]) && $payload[$offset] !== "\x00") {
            $username .= $payload[$offset];
            $offset++;
        }
        $offset++; // skip NUL

        // Auth Response (length encoded integer)
        if ($offset + 1 > $payloadLength) {
            throw new \RuntimeException('解析握手响应失败：数据包长度不足，无法读取 Auth Response 长度');
        }
        $authResponseLength = ord($payload[$offset]);
        $offset += 1;

        if ($offset + $authResponseLength > $payloadLength) {
            throw new \RuntimeException('解析握手响应失败：数据包长度不足，无法读取完整的 Auth Response');
        }
        $authResponse = substr($payload, $offset, $authResponseLength);
        $offset += $authResponseLength;

        // Database (NUL terminated string) - only if CLIENT_CONNECT_WITH_DB
        $database = '';
        if ($capabilities & 0x00000008 && $offset < $payloadLength) {
            while (isset($payload[$offset]) && $payload[$offset] !== "\x00") {
                $database .= $payload[$offset];
                $offset++;
            }
            $offset++; // skip NUL
        }

        // Auth Plugin Name (NUL terminated string) - only if CLIENT_PLUGIN_AUTH
        $authPluginName = '';
        if ($capabilities & 0x00080000 && $offset < $payloadLength) {
            while (isset($payload[$offset]) && $payload[$offset] !== "\x00") {
                $authPluginName .= $payload[$offset];
                $offset++;
            }
        }

        return [
            'capabilities' => $capabilities,
            'max_packet_size' => $maxPacketSize,
            'charset' => $charset,
            'username' => $username,
            'auth_response' => $authResponse,
            'database' => $database,
            'auth_plugin_name' => $authPluginName,
        ];
    }

    public static function createHandshakeResponse(
        string $username,
        string $password,
        string $authPluginData,
        string $database = '',
        string $authPluginName = 'mysql_native_password'
    ): Packet {
        $capabilities = self::CAPABILITIES;
        $authResponse = self::calculateAuthResponse($password, $authPluginData);

        $payload = pack('V', $capabilities); // Capability Flags
        $payload .= pack('V', self::MAX_PACKET_SIZE); // Max Packet Size
        $payload .= chr(Handshake::CHARSET); // Character Set
        $payload .= str_repeat("\x00", 23); // Reserved
        $payload .= $username . "\x00"; // Username
        $payload .= chr(strlen($authResponse)) . $authResponse; // Auth Response

        if ($database !== '') {
            $capabilities |= 0x00000008; // CLIENT_CONNECT_WITH_DB
            $payload .= $database . "\x00"; // Database
        }

        if ($authPluginName !== '') {
            $capabilities |= 0x00080000; // CLIENT_PLUGIN_AUTH
            $payload .= $authPluginName . "\x00"; // Auth Plugin Name
        }

        // Update capabilities in payload
        $payload[0] = chr($capabilities & 0xff);
        $payload[1] = chr(($capabilities >> 8) & 0xff);
        $payload[2] = chr(($capabilities >> 16) & 0xff);
        $payload[3] = chr(($capabilities >> 24) & 0xff);

        return Packet::create(1, $payload);
    }

    public static function createOkPacket(int $affectedRows = 0, int $lastInsertId = 0, int $statusFlags = 0, int $warnings = 0): Packet
    {
        $payload = chr(Command::OK_PACKET);
        $payload .= self::encodeLength($affectedRows);
        $payload .= self::encodeLength($lastInsertId);
        $payload .= pack('v', $statusFlags);
        $payload .= pack('v', $warnings);
        return Packet::create(0, $payload);
    }

    public static function createErrPacket(int $errorCode, string $sqlState, string $errorMessage): Packet
    {
        $payload = chr(Command::ERR_PACKET);
        $payload .= pack('v', $errorCode);
        $payload .= '#';
        $payload .= $sqlState;
        $payload .= $errorMessage;
        return Packet::create(0, $payload);
    }

    private static function encodeLength(int $value): string
    {
        if ($value < 251) {
            return chr($value);
        } elseif ($value < 65536) {
            return "\xfc" . pack('v', $value);
        } elseif ($value < 16777216) {
            return "\xfd" . substr(pack('V', $value), 0, 3);
        } else {
            return "\xfe" . pack('V', $value);
        }
    }
}
