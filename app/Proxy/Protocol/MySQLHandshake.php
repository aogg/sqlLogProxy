<?php

declare(strict_types=1);

namespace App\Proxy\Protocol;

use App\Protocol\MySql\Packet;
use App\Protocol\ConnectionContext;
use App\Proxy\Client\ClientDetector;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * MySQL 协议握手处理器（代理作为服务端）
 * 处理客户端连接时的握手和 SSL 协商
 */
class MySQLHandshake
{
    private const PROTOCOL_VERSION = 10;
    private const SERVER_VERSION = '5.7.44-sqlLogProxy';
    private const CHARSET = 33; // utf8mb4_general_ci

    // MySQL 客户端能力标志（服务端侧）
    // 基本标志：CLIENT_PROTOCOL_41 | CLIENT_SECURE_CONNECTION | CLIENT_LONG_FLAG
    private const CAPABILITIES = 0x00aff7df;

    private LoggerInterface $logger;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('handshake');
    }

    /**
     * 创建服务器握手包
     *
     * @param int $threadId 线程/连接 id
     * @param string|null $authPluginData 如果传入则使用该 auth 插件数据（保证握手与验证使用相同的 salt）
     */
    public function createServerHandshake(int $threadId, ?string $authPluginData = null): Packet
    {
        // 使用外部传入的 authPluginData（如果有），否则生成新的
        // MySQL 5.7.44 实际使用 21 字节的 auth_plugin_data（8 + 13，包含 NUL）
        if ($authPluginData === null) {
            $authPluginData = $this->generateAuthPluginData(21);
        }

        $authPluginDataLen = strlen($authPluginData);

        // 分割 auth_plugin_data
        // 前8字节作为 auth_plugin_data1，后13字节作为 auth_plugin_data2
        $authPluginData1 = substr($authPluginData, 0, 8);
        $authPluginData2 = substr($authPluginData, 8);

        $payload = chr(self::PROTOCOL_VERSION); // Protocol Version
        $payload .= self::SERVER_VERSION . "\x00"; // Server Version
        $payload .= pack('V', $threadId); // Thread ID
        $payload .= $authPluginData1; // Auth Plugin Data Part 1 (8 bytes)
        $payload .= "\x00"; // Filler (1 byte)
        $payload .= pack('v', self::CAPABILITIES & 0xffff); // Capability Flags Lower
        $payload .= chr(self::CHARSET); // Character Set
        $payload .= pack('v', 0); // Status Flags
        $payload .= pack('v', self::CAPABILITIES >> 16); // Capability Flags Upper (full upper 16 bits)

        // 根据 CLIENT_PLUGIN_AUTH 标志决定使用哪种格式
        if (self::CAPABILITIES & 0x00080000) {
            // 启用 CLIENT_PLUGIN_AUTH：发送 authDataLength 和 10 字节保留区
            $payload .= chr($authPluginDataLen); // Auth Plugin Data Length (1 byte)
            $payload .= str_repeat("\x00", 10); // Reserved (10 bytes)
        } else {
            // 不启用 CLIENT_PLUGIN_AUTH：发送 13 字节 FILLER
            $payload .= str_repeat("\x00", 13); // Filler (13 bytes)
        }

        $payload .= $authPluginData2; // Auth Plugin Data Part 2 (remaining bytes)

        // 只有启用 CLIENT_PLUGIN_AUTH 时才发送 pluginName
        if (self::CAPABILITIES & 0x00080000) {
            $payload .= 'mysql_native_password' . "\x00"; // Auth Plugin Name
        }

        $this->logger->debug('创建服务器握手包', [
            'thread_id' => $threadId,
            'server_version' => self::SERVER_VERSION,
            'capabilities' => sprintf('0x%08x', self::CAPABILITIES),
            'charset' => self::CHARSET,
            'auth_plugin_data_total_length' => $authPluginDataLen,
            'auth_plugin_data1_length' => strlen($authPluginData1),
            'auth_plugin_data2_length' => strlen($authPluginData2),
            'payload_length' => strlen($payload),
            'auth_data1_hex' => bin2hex($authPluginData1),
            'auth_data2_hex' => bin2hex($authPluginData2),
        ]);

        return Packet::create(0, $payload);
    }

    /**
     * 处理客户端握手响应
     */
    public function handleClientHandshakeResponse(Packet $packet, ?ConnectionContext $context = null, ?ClientDetector $clientDetector = null): array
    {
        $payload = $packet->getPayload();
        $offset = 0;
        $payloadLength = strlen($payload);

        // 记录客户端发送的原始数据包信息
        $this->logger->info('收到客户端握手响应数据包', [
            'packet_length' => strlen($payload),
            'sequence_id' => $packet->getSequenceId(),
            'payload_hex' => bin2hex($payload),
            'payload_length' => $payloadLength,
        ]);

        // Capability Flags (4 bytes)
        if ($offset + 4 > $payloadLength) {
            throw new \RuntimeException('解析客户端握手响应失败：数据包长度不足，无法读取 Capability Flags');
        }
        $capabilities = unpack('V', substr($payload, $offset, 4))[1];
        $offset += 4;

        // 检查客户端是否请求 SSL
        $clientRequestsSsl = ($capabilities & 0x00000800) !== 0; // CLIENT_SSL

        $this->logger->info('解析客户端握手响应 - 基本信息', [
            'capabilities' => sprintf('0x%08x', $capabilities),
            'capabilities_binary' => str_pad(decbin($capabilities), 32, '0', STR_PAD_LEFT),
            'client_requests_ssl' => $clientRequestsSsl,
            'sequence_id' => $packet->getSequenceId(),
            'packet_size' => $payloadLength,
        ]);

        // 解析客户端能力标志的详细信息
        $this->logClientCapabilities($capabilities);

        // 如果客户端请求 SSL，返回特殊响应要求客户端切换到 SSL
        if ($clientRequestsSsl && $packet->getSequenceId() === 1) {
            // 即使是SSL请求，也尝试检测客户端类型
            if ($context && $clientDetector) {
                $clientDetector->detectFromHandshake($context, [
                    'capabilities' => $capabilities,
                    'charset' => 0, // SSL请求阶段还没有字符集信息
                ]);
            }

            return [
                'type' => 'ssl_request',
                'capabilities' => $capabilities,
                'ssl_requested' => true,
            ];
        }

        // 解析完整的认证信息
        $authData = $this->parseFullHandshakeResponse($packet);

        // 如果提供了上下文和检测器，进行客户端类型检测
        if ($context && $clientDetector && isset($authData['capabilities'])) {
            $clientDetector->detectFromHandshake($context, [
                'capabilities' => $authData['capabilities'],
                'charset' => $authData['charset'] ?? 0,
            ]);
        }

        return $authData;
    }

    /**
     * 详细记录客户端能力标志
     */
    private function logClientCapabilities(int $capabilities): void
    {
        $capabilityDetails = [
            'CLIENT_LONG_PASSWORD' => ($capabilities & 0x00000001) !== 0,
            'CLIENT_FOUND_ROWS' => ($capabilities & 0x00000002) !== 0,
            'CLIENT_LONG_FLAG' => ($capabilities & 0x00000004) !== 0,
            'CLIENT_CONNECT_WITH_DB' => ($capabilities & 0x00000008) !== 0,
            'CLIENT_NO_SCHEMA' => ($capabilities & 0x00000010) !== 0,
            'CLIENT_COMPRESS' => ($capabilities & 0x00000020) !== 0,
            'CLIENT_ODBC' => ($capabilities & 0x00000040) !== 0,
            'CLIENT_LOCAL_FILES' => ($capabilities & 0x00000080) !== 0,
            'CLIENT_IGNORE_SPACE' => ($capabilities & 0x00000100) !== 0,
            'CLIENT_PROTOCOL_41' => ($capabilities & 0x00000200) !== 0,
            'CLIENT_INTERACTIVE' => ($capabilities & 0x00000400) !== 0,
            'CLIENT_SSL' => ($capabilities & 0x00000800) !== 0,
            'CLIENT_IGNORE_SIGPIPE' => ($capabilities & 0x00001000) !== 0,
            'CLIENT_TRANSACTIONS' => ($capabilities & 0x00002000) !== 0,
            'CLIENT_RESERVED' => ($capabilities & 0x00004000) !== 0,
            'CLIENT_SECURE_CONNECTION' => ($capabilities & 0x00008000) !== 0,
            'CLIENT_MULTI_STATEMENTS' => ($capabilities & 0x00010000) !== 0,
            'CLIENT_MULTI_RESULTS' => ($capabilities & 0x00020000) !== 0,
            'CLIENT_PS_MULTI_RESULTS' => ($capabilities & 0x00040000) !== 0,
            'CLIENT_PLUGIN_AUTH' => ($capabilities & 0x00080000) !== 0,
            'CLIENT_CONNECT_ATTRS' => ($capabilities & 0x00100000) !== 0,
            'CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA' => ($capabilities & 0x00200000) !== 0,
            'CLIENT_CAN_HANDLE_EXPIRED_PASSWORDS' => ($capabilities & 0x00400000) !== 0,
            'CLIENT_SESSION_TRACK' => ($capabilities & 0x00800000) !== 0,
            'CLIENT_DEPRECATE_EOF' => ($capabilities & 0x01000000) !== 0,
        ];

        $enabledCapabilities = array_filter($capabilityDetails, fn($enabled) => $enabled);

        $this->logger->info('客户端能力标志详情', [
            'enabled_capabilities' => array_keys($enabledCapabilities),
            'capability_count' => count($enabledCapabilities),
            'notable_features' => [
                'supports_ssl' => $capabilityDetails['CLIENT_SSL'],
                'supports_compression' => $capabilityDetails['CLIENT_COMPRESS'],
                'supports_transactions' => $capabilityDetails['CLIENT_TRANSACTIONS'],
                'supports_multi_statements' => $capabilityDetails['CLIENT_MULTI_STATEMENTS'],
                'supports_plugin_auth' => $capabilityDetails['CLIENT_PLUGIN_AUTH'],
                'protocol_41' => $capabilityDetails['CLIENT_PROTOCOL_41'],
                'deprecate_eof' => $capabilityDetails['CLIENT_DEPRECATE_EOF'],
            ],
        ]);
    }

    /**
     * 解析完整的客户端握手响应（认证信息）
     */
    private function parseFullHandshakeResponse(Packet $packet): array
    {
        $payload = $packet->getPayload();
        $offset = 0;
        $payloadLength = strlen($payload);

        // Capability Flags (4 bytes)
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

        $this->logger->info('解析完整客户端认证信息', [
            'username' => $username,
            'database' => $database,
            'auth_plugin_name' => $authPluginName,
            'charset' => $charset,
            'charset_name' => $this->getCharsetName($charset),
            'max_packet_size' => $maxPacketSize,
            'auth_response_length' => strlen($authResponse),
            'auth_response_hex' => bin2hex($authResponse),
            'has_database' => !empty($database),
            'auth_plugin_specified' => !empty($authPluginName),
        ]);

        // 记录客户端连接属性（如果支持）
        if ($capabilities & 0x00100000) { // CLIENT_CONNECT_ATTRS
            $this->logClientConnectionAttributes($payload, $offset);
        }

        return [
            'type' => 'auth_response',
            'capabilities' => $capabilities,
            'max_packet_size' => $maxPacketSize,
            'charset' => $charset,
            'username' => $username,
            'auth_response' => $authResponse,
            'database' => $database,
            'auth_plugin_name' => $authPluginName,
        ];
    }

    /**
     * 生成认证插件数据
     * MySQL 5.7.44 要求 auth_plugin_data 长度为 21 字节（8 + 13）
     */
    public function generateAuthPluginData(int $length = 21): string
    {
        $data = '';
        for ($i = 0; $i < $length; $i++) {
            // 使用安全的ASCII字符（不包括控制字符）
            // 范围：0x21-0x7e (33-126)
            $data .= chr(mt_rand(33, 126));
        }
        return $data;
    }

    /**
     * 获取字符集名称
     */
    private function getCharsetName(int $charset): string
    {
        $charsetMap = [
            1 => 'big5_chinese_ci',
            2 => 'latin2_czech_cs',
            3 => 'dec8_swedish_ci',
            4 => 'cp850_general_ci',
            5 => 'latin1_german1_ci',
            6 => 'hp8_english_ci',
            7 => 'koi8r_general_ci',
            8 => 'latin1_swedish_ci',
            9 => 'latin2_general_ci',
            10 => 'swe7_swedish_ci',
            11 => 'ascii_general_ci',
            12 => 'ujis_japanese_ci',
            13 => 'sjis_japanese_ci',
            14 => 'cp1251_bulgarian_ci',
            15 => 'latin1_danish_ci',
            16 => 'hebrew_general_ci',
            18 => 'tis620_thai_ci',
            19 => 'euckr_korean_ci',
            20 => 'latin7_estonian_cs',
            21 => 'latin2_hungarian_ci',
            22 => 'koi8u_general_ci',
            23 => 'cp1251_ukrainian_ci',
            24 => 'gb2312_chinese_ci',
            25 => 'greek_general_ci',
            26 => 'cp1250_general_ci',
            27 => 'latin2_croatian_ci',
            28 => 'gbk_chinese_ci',
            29 => 'cp1257_lithuanian_ci',
            30 => 'latin5_turkish_ci',
            31 => 'latin1_german2_ci',
            32 => 'armscii8_general_ci',
            33 => 'utf8_general_ci',
            34 => 'cp1250_czech_cs',
            35 => 'ucs2_general_ci',
            36 => 'cp866_general_ci',
            37 => 'keybcs2_general_ci',
            38 => 'macce_general_ci',
            39 => 'macroman_general_ci',
            40 => 'cp852_general_ci',
            41 => 'latin7_general_ci',
            42 => 'latin7_general_cs',
            43 => 'macce_bin',
            44 => 'cp1250_bin',
            45 => 'cp1256_general_ci',
            46 => 'cp1257_bin',
            47 => 'latin1_bin',
            48 => 'latin1_general_ci',
            49 => 'latin1_general_cs',
            50 => 'cp1251_bin',
            51 => 'cp1251_general_ci',
            52 => 'cp1251_general_cs',
            53 => 'macroman_bin',
            54 => 'utf16_general_ci',
            55 => 'utf16_bin',
            56 => 'utf16le_general_ci',
            57 => 'cp1256_bin',
            58 => 'cp1257_general_ci',
            59 => 'cp1257_bin',
            60 => 'utf32_general_ci',
            61 => 'utf32_bin',
            62 => 'utf16le_bin',
            63 => 'binary',
            64 => 'armscii8_bin',
            65 => 'ascii_bin',
            66 => 'cp1250_bin',
            67 => 'cp1256_bin',
            68 => 'cp866_bin',
            69 => 'dec8_bin',
            70 => 'greek_bin',
            71 => 'hebrew_bin',
            72 => 'hp8_bin',
            73 => 'keybcs2_bin',
            74 => 'koi8r_bin',
            75 => 'koi8u_bin',
            76 => 'latin2_bin',
            77 => 'latin5_bin',
            78 => 'latin7_bin',
            79 => 'cp850_bin',
            80 => 'cp852_bin',
            81 => 'swe7_bin',
            82 => 'utf8_bin',
            83 => 'big5_bin',
            84 => 'euckr_bin',
            85 => 'gb2312_bin',
            86 => 'gbk_bin',
            87 => 'sjis_bin',
            88 => 'tis620_bin',
            89 => 'ucs2_bin',
            90 => 'ujis_bin',
            91 => 'geostd8_general_ci',
            92 => 'geostd8_bin',
            93 => 'latin1_spanish_ci',
            94 => 'cp1250_polish_ci',
            95 => 'utf8_unicode_ci',
            96 => 'utf8_icelandic_ci',
            97 => 'utf8_latvian_ci',
            98 => 'utf8_romanian_ci',
            99 => 'utf8_slovenian_ci',
            100 => 'utf8_polish_ci',
            101 => 'utf8_estonian_ci',
            102 => 'utf8_spanish_ci',
            103 => 'utf8_swedish_ci',
            104 => 'utf8_turkish_ci',
            105 => 'utf8_czech_ci',
            106 => 'utf8_danish_ci',
            107 => 'utf8_lithuanian_ci',
            108 => 'utf8_slovak_ci',
            109 => 'utf8_spanish2_ci',
            110 => 'utf8_roman_ci',
            111 => 'utf8_persian_ci',
            112 => 'utf8_esperanto_ci',
            113 => 'utf8_hungarian_ci',
            114 => 'utf8_sinhala_ci',
            115 => 'utf8_german2_ci',
            116 => 'utf8_croatian_ci',
            117 => 'utf8_unicode_520_ci',
            118 => 'utf8_vietnamese_ci',
            119 => 'utf8_general_mysql500_ci',
            120 => 'utf8mb4_unicode_ci',
            121 => 'utf8mb4_icelandic_ci',
            122 => 'utf8mb4_latvian_ci',
            123 => 'utf8mb4_romanian_ci',
            124 => 'utf8mb4_slovenian_ci',
            125 => 'utf8mb4_polish_ci',
            126 => 'utf8mb4_estonian_ci',
            127 => 'utf8mb4_spanish_ci',
            128 => 'utf8mb4_swedish_ci',
            129 => 'utf8mb4_turkish_ci',
            130 => 'utf8mb4_czech_ci',
            131 => 'utf8mb4_danish_ci',
            132 => 'utf8mb4_lithuanian_ci',
            133 => 'utf8mb4_slovak_ci',
            134 => 'utf8mb4_spanish2_ci',
            135 => 'utf8mb4_roman_ci',
            136 => 'utf8mb4_persian_ci',
            137 => 'utf8mb4_esperanto_ci',
            138 => 'utf8mb4_hungarian_ci',
            139 => 'utf8mb4_sinhala_ci',
            140 => 'utf8mb4_german2_ci',
            141 => 'utf8mb4_croatian_ci',
            142 => 'utf8mb4_unicode_520_ci',
            143 => 'utf8mb4_vietnamese_ci',
            144 => 'utf8mb4_0900_ai_ci',
            145 => 'utf8mb4_de_pb_0900_ai_ci',
            146 => 'utf8mb4_is_0900_ai_ci',
            147 => 'utf8mb4_lv_0900_ai_ci',
            148 => 'utf8mb4_ro_0900_ai_ci',
            149 => 'utf8mb4_sl_0900_ai_ci',
            150 => 'utf8mb4_pl_0900_ai_ci',
            151 => 'utf8mb4_et_0900_ai_ci',
            152 => 'utf8mb4_es_0900_ai_ci',
            153 => 'utf8mb4_sv_0900_ai_ci',
            154 => 'utf8mb4_tr_0900_ai_ci',
            155 => 'utf8mb4_cs_0900_ai_ci',
            156 => 'utf8mb4_da_0900_ai_ci',
            157 => 'utf8mb4_lt_0900_ai_ci',
            158 => 'utf8mb4_sk_0900_ai_ci',
            159 => 'utf8mb4_es_trad_0900_ai_ci',
            160 => 'utf8mb4_la_0900_ai_ci',
            161 => 'utf8mb4_eo_0900_ai_ci',
            162 => 'utf8mb4_hu_0900_asz_ci',
            163 => 'utf8mb4_hr_0900_ai_ci',
            164 => 'utf8mb4_vi_0900_ai_ci',
            165 => 'utf8mb4_0900_as_cs',
            166 => 'utf8mb4_de_pb_0900_as_cs',
            167 => 'utf8mb4_is_0900_as_cs',
            168 => 'utf8mb4_lv_0900_as_cs',
            169 => 'utf8mb4_ro_0900_as_cs',
            170 => 'utf8mb4_sl_0900_as_cs',
            171 => 'utf8mb4_pl_0900_as_cs',
            172 => 'utf8mb4_et_0900_as_cs',
            173 => 'utf8mb4_es_0900_as_cs',
            174 => 'utf8mb4_sv_0900_as_cs',
            175 => 'utf8mb4_tr_0900_as_cs',
            176 => 'utf8mb4_cs_0900_as_cs',
            177 => 'utf8mb4_da_0900_as_cs',
            178 => 'utf8mb4_lt_0900_as_cs',
            179 => 'utf8mb4_sk_0900_as_cs',
            180 => 'utf8mb4_es_trad_0900_as_cs',
            181 => 'utf8mb4_la_0900_as_cs',
            182 => 'utf8mb4_eo_0900_as_cs',
            183 => 'utf8mb4_hu_0900_asz_cs',
            184 => 'utf8mb4_hr_0900_as_cs',
            185 => 'utf8mb4_vi_0900_as_cs',
            186 => 'utf8mb4_ja_0900_as_cs',
            187 => 'utf8mb4_ja_0900_as_cs_ks',
            188 => 'utf8mb4_0900_as_ci',
            189 => 'utf8mb4_ru_0900_ai_ci',
            190 => 'utf8mb4_ru_0900_as_cs',
            191 => 'utf8mb4_zh_0900_as_cs',
            192 => 'utf8mb4_0900_bin',
            193 => 'utf8mb4_nb_0900_ai_ci',
            194 => 'utf8mb4_nb_0900_as_cs',
            195 => 'utf8mb4_nn_0900_ai_ci',
            196 => 'utf8mb4_nn_0900_as_cs',
            197 => 'utf8mb4_sr_latn_0900_ai_ci',
            198 => 'utf8mb4_sr_latn_0900_as_cs',
            199 => 'utf8mb4_bs_0900_ai_ci',
            200 => 'utf8mb4_bs_0900_as_cs',
            201 => 'utf8mb4_sr_cyrl_0900_ai_ci',
            202 => 'utf8mb4_sr_cyrl_0900_as_cs',
            203 => 'utf8mb4_sr_latn_0900_bin',
            204 => 'utf8mb4_sr_cyrl_0900_bin',
            205 => 'utf8mb4_nb_bin',
            206 => 'utf8mb4_nn_bin',
            207 => 'utf8mb4_bs_bin',
            208 => 'utf8mb4_sr_latn_bin',
            209 => 'utf8mb4_sr_cyrl_bin',
            210 => 'utf8mb4_zh_0900_as_cs',
            211 => 'utf8mb4_ja_0900_as_cs',
            212 => 'utf8mb4_ja_0900_as_cs_ks',
            213 => 'utf8mb4_0900_as_ci',
            214 => 'utf8mb4_ru_0900_ai_ci',
            215 => 'utf8mb4_ru_0900_as_cs',
            216 => 'utf8mb4_zh_0900_as_cs',
            217 => 'utf8mb4_0900_bin',
            218 => 'utf8mb4_nb_0900_ai_ci',
            219 => 'utf8mb4_nb_0900_as_cs',
            220 => 'utf8mb4_nn_0900_ai_ci',
            221 => 'utf8mb4_nn_0900_as_cs',
            222 => 'utf8mb4_sr_latn_0900_ai_ci',
            223 => 'utf8mb4_sr_latn_0900_as_cs',
            224 => 'utf8mb4_bs_0900_ai_ci',
            225 => 'utf8mb4_bs_0900_as_cs',
            226 => 'utf8mb4_sr_cyrl_0900_ai_ci',
            227 => 'utf8mb4_sr_cyrl_0900_as_cs',
            228 => 'utf8mb4_sr_latn_0900_bin',
            229 => 'utf8mb4_sr_cyrl_0900_bin',
            230 => 'utf8mb4_nb_bin',
            231 => 'utf8mb4_nn_bin',
            232 => 'utf8mb4_bs_bin',
            233 => 'utf8mb4_sr_latn_bin',
            234 => 'utf8mb4_sr_cyrl_bin',
            235 => 'gb18030_chinese_ci',
            236 => 'gb18030_bin',
            237 => 'gb18030_unicode_520_ci',
            238 => 'utf8mb4_zh_pinyin_tidb_ci',
            239 => 'utf8mb4_zh_pinyin_tidb_cs',
            240 => 'utf8mb4_zh_0900_as_cs',
            241 => 'utf8mb4_ja_0900_as_cs',
            242 => 'utf8mb4_ja_0900_as_cs_ks',
            243 => 'utf8mb4_0900_as_ci',
            244 => 'utf8mb4_ru_0900_ai_ci',
            245 => 'utf8mb4_ru_0900_as_cs',
            246 => 'utf8mb4_zh_0900_as_cs',
            247 => 'utf8mb4_0900_bin',
            248 => 'utf8mb4_nb_0900_ai_ci',
            249 => 'utf8mb4_nb_0900_as_cs',
            250 => 'utf8mb4_nn_0900_ai_ci',
            251 => 'utf8mb4_nn_0900_as_cs',
            252 => 'utf8mb4_sr_latn_0900_ai_ci',
            253 => 'utf8mb4_sr_latn_0900_as_cs',
            254 => 'utf8mb4_bs_0900_ai_ci',
            255 => 'utf8mb4_bs_0900_as_cs',
        ];

        return $charsetMap[$charset] ?? "charset_{$charset}";
    }

    /**
     * 记录客户端连接属性
     */
    private function logClientConnectionAttributes(string $payload, int &$offset): void
    {
        try {
            $attributes = [];
            $payloadLength = strlen($payload);

            while ($offset < $payloadLength) {
                // 读取属性名长度
                $nameLen = ord($payload[$offset]);
                $offset++;

                if ($offset + $nameLen > $payloadLength) {
                    break;
                }

                // 读取属性名
                $name = substr($payload, $offset, $nameLen);
                $offset += $nameLen;

                // 读取属性值长度
                $valueLen = ord($payload[$offset]);
                $offset++;

                if ($offset + $valueLen > $payloadLength) {
                    break;
                }

                // 读取属性值
                $value = substr($payload, $offset, $valueLen);
                $offset += $valueLen;

                $attributes[$name] = $value;
            }

            $this->logger->info('客户端连接属性', [
                'attributes' => $attributes,
                'attribute_count' => count($attributes),
                'notable_attributes' => [
                    'program_name' => $attributes['_client_name'] ?? $attributes['program_name'] ?? null,
                    'client_version' => $attributes['_client_version'] ?? $attributes['client_version'] ?? null,
                    'os' => $attributes['_os'] ?? $attributes['os'] ?? null,
                    'platform' => $attributes['_platform'] ?? $attributes['platform'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('解析客户端连接属性失败', [
                'error' => $e->getMessage(),
                'offset' => $offset,
                'payload_length' => $payloadLength,
            ]);
        }
    }

    /**
     * 检查客户端是否支持 SSL
     */
    public function clientSupportsSsl(int $capabilities): bool
    {
        return ($capabilities & 0x00000800) !== 0; // CLIENT_SSL
    }

    /**
     * 创建 SSL 切换响应包
     */
    public function createSslSwitchPacket(): Packet
    {
        // MySQL SSL 切换包是一个特殊的握手响应，只包含能力标志
        $payload = pack('V', self::CAPABILITIES); // Capability Flags
        $payload .= pack('V', 0x00ffffff); // Max Packet Size
        $payload .= chr(self::CHARSET); // Character Set
        $payload .= str_repeat("\x00", 23); // Reserved

        $this->logger->debug('创建 SSL 切换包');

        return Packet::create(1, $payload);
    }
}
