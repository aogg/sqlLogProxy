<?php

declare(strict_types=1);

namespace App\Protocol\MySql;

class Handshake
{
    public const PROTOCOL_VERSION = 10;
    private const SERVER_VERSION = '5.7.44-sqlLogProxy';
    private const CHARSET = 33; // utf8mb4_general_ci

    // MySQL Connector/J 8.2.0 需要的 capabilities
    // CLIENT_LONG_PASSWORD(1), CLIENT_FOUND_ROWS(2), CLIENT_LONG_FLAG(4), CLIENT_CONNECT_WITH_DB(8),
    // CLIENT_NO_SCHEMA(16), CLIENT_COMPRESS(32), CLIENT_ODBC(64), CLIENT_LOCAL_FILES(128),
    // CLIENT_IGNORE_SPACE(256), CLIENT_PROTOCOL_41(512), CLIENT_INTERACTIVE(1024),
    // CLIENT_SSL(2048), CLIENT_IGNORE_SIGPIPE(4096), CLIENT_TRANSACTIONS(8192),
    // CLIENT_RESERVED(16384), CLIENT_SECURE_CONNECTION(32768), CLIENT_MULTI_STATEMENTS(65536),
    // CLIENT_MULTI_RESULTS(131072), CLIENT_PS_MULTI_RESULTS(262144),
    // CLIENT_PLUGIN_AUTH(524288), CLIENT_CONNECT_ATTRS(1048576), CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA(2097152)
    private const CAPABILITIES = 0x00aff7df;

    public static function parseServerHandshake(Packet $packet): array
    {
        $payload = $packet->getPayload();
        $offset = 0;

        // Protocol Version (1 byte)
        $protocolVersion = ord($payload[$offset]);
        $offset += 1;

        // Server Version (NUL terminated string)
        $serverVersion = '';
        while (isset($payload[$offset]) && $payload[$offset] !== "\x00") {
            $serverVersion .= $payload[$offset];
            $offset++;
        }
        $offset++; // skip NUL

        // Thread ID (4 bytes)
        $threadId = unpack('V', substr($payload, $offset, 4))[1];
        $offset += 4;

        // Auth Plugin Data Part 1 (8 bytes)
        $authPluginData1 = substr($payload, $offset, 8);
        $offset += 8;

        // Filler (1 byte)
        $filler = ord($payload[$offset]);
        $offset += 1;

        // Capability Flags Lower 2 bytes
        $capabilityFlagsLower = unpack('v', substr($payload, $offset, 2))[1];
        $offset += 2;

        // Character Set (1 byte)
        $characterSet = ord($payload[$offset]);
        $offset += 1;

        // Status Flags (2 bytes)
        $statusFlags = unpack('v', substr($payload, $offset, 2))[1];
        $offset += 2;

        // Capability Flags Upper 2 bytes
        $capabilityFlagsUpper = unpack('v', substr($payload, $offset, 2))[1];
        $offset += 2;

        $capabilityFlags = ($capabilityFlagsUpper << 16) | $capabilityFlagsLower;

        // Auth Plugin Data Length (1 byte) - only if CLIENT_PLUGIN_AUTH
        $authPluginDataLen = 0;
        if ($capabilityFlags & 0x00080000) {
            $authPluginDataLen = ord($payload[$offset]);
            $offset += 1;
        }

        // Reserved (10 bytes)
        $offset += 10;

        // Auth Plugin Data Part 2 (max(13, authPluginDataLen - 8) bytes)
        $authPluginData2Len = max(13, $authPluginDataLen - 8);
        $authPluginData2 = substr($payload, $offset, $authPluginData2Len);
        $offset += $authPluginData2Len;

        // Auth Plugin Name (NUL terminated string) - only if CLIENT_PLUGIN_AUTH
        $authPluginName = '';
        if ($capabilityFlags & 0x00080000 && $offset < strlen($payload)) {
            while (isset($payload[$offset]) && $payload[$offset] !== "\x00") {
                $authPluginName .= $payload[$offset];
                $offset++;
            }
        }

        return [
            'protocol_version' => $protocolVersion,
            'server_version' => $serverVersion,
            'thread_id' => $threadId,
            'auth_plugin_data' => $authPluginData1 . $authPluginData2,
            'charset' => $characterSet,
            'capabilities' => $capabilityFlags,
            'auth_plugin_name' => $authPluginName,
        ];
    }

    public static function createHandshakeV10(int $threadId, string $authPluginData, string $authPluginName = 'mysql_native_password'): Packet
    {
        $authPluginData1 = substr($authPluginData, 0, 8);
        $authPluginDataLen = strlen($authPluginData);
        $authPluginData2 = substr($authPluginData, 8);

        $payload = chr(self::PROTOCOL_VERSION); // Protocol Version
        $payload .= self::SERVER_VERSION . "\x00"; // Server Version
        $payload .= pack('V', $threadId); // Thread ID
        $payload .= $authPluginData1; // Auth Plugin Data Part 1
        $payload .= "\x00"; // Filler
        $payload .= pack('v', self::CAPABILITIES & 0xffff); // Capability Flags Lower
        $payload .= chr(self::CHARSET); // Character Set
        $payload .= pack('v', 0); // Status Flags
        $payload .= pack('v', (self::CAPABILITIES >> 16) & 0xffff); // Capability Flags Upper
        $payload .= chr($authPluginDataLen); // Auth Plugin Data Length
        $payload .= str_repeat("\x00", 10); // Reserved
        $payload .= $authPluginData2; // Auth Plugin Data Part 2
        $payload .= $authPluginName . "\x00"; // Auth Plugin Name

        return Packet::create(0, $payload);
    }
}
