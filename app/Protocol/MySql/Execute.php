<?php

declare(strict_types=1);

namespace App\Protocol\MySql;

class Execute
{
    public static function parseExecutePacket(Packet $packet): array
    {
        if ($packet->getCommand() !== Command::COM_STMT_EXECUTE) {
            throw new \InvalidArgumentException('不是EXECUTE命令包');
        }

        $payload = $packet->getPayloadWithoutCommand();
        $offset = 0;

        // Statement ID (4 bytes)
        $statementId = unpack('V', substr($payload, $offset, 4))[1];
        $offset += 4;

        // Flags (1 byte)
        $flags = ord($payload[$offset]);
        $offset += 1;

        // Iteration count (4 bytes)
        $iterationCount = unpack('V', substr($payload, $offset, 4))[1];
        $offset += 4;

        return [
            'statement_id' => $statementId,
            'flags' => $flags,
            'iteration_count' => $iterationCount,
        ];
    }
}
