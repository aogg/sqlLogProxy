<?php

declare(strict_types=1);

namespace App\Protocol\MySql;

class Prepare
{
    public static function parsePreparePacket(Packet $packet): string
    {
        if ($packet->getCommand() !== Command::COM_STMT_PREPARE) {
            throw new \InvalidArgumentException('不是PREPARE命令包');
        }
        return $packet->getPayloadWithoutCommand();
    }

    public static function parsePrepareResponse(Packet $packet): array
    {
        $payload = $packet->getPayload();
        $offset = 0;

        // Statement ID (4 bytes)
        $statementId = unpack('V', substr($payload, $offset, 4))[1];
        $offset += 4;

        // Number of columns (2 bytes)
        $numColumns = unpack('v', substr($payload, $offset, 2))[1];
        $offset += 2;

        // Number of parameters (2 bytes)
        $numParams = unpack('v', substr($payload, $offset, 2))[1];
        $offset += 2;

        // Reserved (1 byte)
        $offset += 1;

        // Warning count (2 bytes)
        $warningCount = unpack('v', substr($payload, $offset, 2))[1];

        return [
            'statement_id' => $statementId,
            'num_columns' => $numColumns,
            'num_params' => $numParams,
            'warning_count' => $warningCount,
        ];
    }
}
