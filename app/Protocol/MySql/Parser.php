<?php

declare(strict_types=1);

namespace App\Protocol\MySql;

class Parser
{
    private static array $preparedStatements = [];

    public static function parsePackets(string $data): array
    {
        $packets = [];
        $offset = 0;
        $totalLength = strlen($data);

        while ($offset < $totalLength) {
            // 检查是否有足够的字节来读取包头
            if ($offset + 4 > $totalLength) {
                break;
            }

            $length = unpack('V', substr($data, $offset, 3) . "\x00")[1];
            $sequenceId = ord($data[$offset + 3]);

            // 验证包长度是否合理
            if ($length < 0 || $length > 0xffffff) {
                throw new \RuntimeException("无效的包长度: {$length}");
            }

            // 检查是否有足够的字节来读取payload
            if ($offset + 4 + $length > $totalLength) {
                break;
            }

            $payload = substr($data, $offset + 4, $length);

            $packets[] = new Packet($length, $sequenceId, $payload);
            $offset += 4 + $length;
        }

        return $packets;
    }

    public static function parseCommand(Packet $packet): ?array
    {
        $command = $packet->getCommand();

        switch ($command) {
            case Command::COM_QUERY:
                return [
                    'type' => 'query',
                    'sql' => Query::parseQueryPacket($packet),
                ];

            case Command::COM_STMT_PREPARE:
                return [
                    'type' => 'prepare',
                    'sql' => Prepare::parsePreparePacket($packet),
                ];

            case Command::COM_STMT_EXECUTE:
                return [
                    'type' => 'execute',
                    'data' => Execute::parseExecutePacket($packet),
                ];

            case Command::COM_QUIT:
                return ['type' => 'quit'];

            case Command::COM_INIT_DB:
                return [
                    'type' => 'use',
                    'database' => $packet->getPayloadWithoutCommand(),
                ];

            case Command::COM_PING:
                return ['type' => 'ping'];

            default:
                return [
                    'type' => 'unknown',
                    'command' => $command,
                    'name' => Command::getCommandName($command),
                ];
        }
    }

    public static function registerPreparedStatement(int $statementId, string $sql): void
    {
        self::$preparedStatements[$statementId] = $sql;
    }

    public static function getPreparedStatement(int $statementId): ?string
    {
        return isset(self::$preparedStatements[$statementId]) ? self::$preparedStatements[$statementId] : null;
    }

    public static function removePreparedStatement(int $statementId): void
    {
        unset(self::$preparedStatements[$statementId]);
    }

    public static function clearPreparedStatements(): void
    {
        self::$preparedStatements = [];
    }
}
