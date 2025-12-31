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

        // 获取预编译语句的参数数量
        $paramCount = Parser::getPreparedStatementParamCount($statementId);

        $parameters = [];
        if ($paramCount > 0) {
            // Null bitmap - (param_count + 7) / 8 bytes
            $nullBitmapSize = (int)(($paramCount + 7) / 8);
            if ($offset + $nullBitmapSize > strlen($payload)) {
                throw new \RuntimeException('数据包不完整，无法解析null bitmap');
            }
            $nullBitmap = substr($payload, $offset, $nullBitmapSize);
            $offset += $nullBitmapSize;

            // New parameter bound flag (1 byte)
            if ($offset >= strlen($payload)) {
                throw new \RuntimeException('数据包不完整，无法解析new parameter bound flag');
            }
            $newParamsBound = ord($payload[$offset]);
            $offset += 1;

            if ($newParamsBound) {
                // Parameter types
                $paramTypes = [];
                for ($i = 0; $i < $paramCount; $i++) {
                    if ($offset >= strlen($payload)) {
                        throw new \RuntimeException('数据包不完整，无法解析参数类型');
                    }
                    $type = ord($payload[$offset]);
                    $offset += 1;

                    $paramTypes[] = [
                        'type' => $type,
                        'unsigned' => false, // 简化处理，可以根据需要扩展
                    ];
                }

                // Parameter values
                for ($i = 0; $i < $paramCount; $i++) {
                    $isNull = self::checkBit($nullBitmap, $i);

                    if ($isNull) {
                        $parameters[] = null;
                    } else {
                        if ($offset >= strlen($payload)) {
                            throw new \RuntimeException('数据包不完整，无法解析参数值');
                        }
                        $value = self::parseParameterValue($payload, $offset, $paramTypes[$i]);
                        $parameters[] = $value;
                    }
                }
            }
        }

        return [
            'statement_id' => $statementId,
            'flags' => $flags,
            'iteration_count' => $iterationCount,
            'parameters' => $parameters,
        ];
    }

    private static function checkBit(string $bitmap, int $bit): bool
    {
        $byteIndex = (int)($bit / 8);
        $bitIndex = $bit % 8;
        if ($byteIndex >= strlen($bitmap)) {
            return false;
        }
        $byte = ord($bitmap[$byteIndex]);
        return ($byte & (1 << $bitIndex)) != 0;
    }

    private static function parseParameterValue(string &$payload, int &$offset, array $paramType): mixed
    {
        $type = $paramType['type'];

        switch ($type) {
            case 0x01: // MYSQL_TYPE_TINY
                if ($offset >= strlen($payload)) {
                    throw new \RuntimeException('数据包不完整，无法解析TINY参数');
                }
                $value = ord($payload[$offset]);
                $offset += 1;
                break;
            case 0x02: // MYSQL_TYPE_SHORT
                if ($offset + 1 >= strlen($payload)) {
                    throw new \RuntimeException('数据包不完整，无法解析SHORT参数');
                }
                $value = unpack('v', substr($payload, $offset, 2))[1];
                $offset += 2;
                break;
            case 0x03: // MYSQL_TYPE_LONG
                if ($offset + 3 >= strlen($payload)) {
                    throw new \RuntimeException('数据包不完整，无法解析LONG参数');
                }
                $value = unpack('V', substr($payload, $offset, 4))[1];
                $offset += 4;
                break;
            case 0x04: // MYSQL_TYPE_FLOAT
                if ($offset + 3 >= strlen($payload)) {
                    throw new \RuntimeException('数据包不完整，无法解析FLOAT参数');
                }
                $value = unpack('f', substr($payload, $offset, 4))[1];
                $offset += 4;
                break;
            case 0x05: // MYSQL_TYPE_DOUBLE
                if ($offset + 7 >= strlen($payload)) {
                    throw new \RuntimeException('数据包不完整，无法解析DOUBLE参数');
                }
                $value = unpack('d', substr($payload, $offset, 8))[1];
                $offset += 8;
                break;
            case 0x0f: // MYSQL_TYPE_VARCHAR
            case 0xfd: // MYSQL_TYPE_VAR_STRING
                // Length-encoded string
                list($length, $bytes) = self::decodeLengthEncodedInteger($payload, $offset);
                if ($offset + $length > strlen($payload)) {
                    throw new \RuntimeException('数据包不完整，无法解析字符串参数');
                }
                $value = substr($payload, $offset, $length);
                $offset += $length;
                break;
            case 0xfc: // MYSQL_TYPE_BLOB
                // Length-encoded blob
                list($length, $bytes) = self::decodeLengthEncodedInteger($payload, $offset);
                if ($offset + $length > strlen($payload)) {
                    throw new \RuntimeException('数据包不完整，无法解析BLOB参数');
                }
                $value = substr($payload, $offset, $length);
                $offset += $length;
                break;
            default:
                // 对于未支持的类型，返回null或者抛出异常
                // 这里暂时返回null，避免中断执行
                $value = null;
                break;
        }

        return $value;
    }

    private static function decodeLengthEncodedInteger(string $payload, int &$offset): array
    {
        if ($offset >= strlen($payload)) {
            throw new \RuntimeException('数据包不完整，无法解码长度');
        }

        $firstByte = ord($payload[$offset]);
        $offset += 1;

        if ($firstByte < 0xfb) {
            return [$firstByte, 1];
        } elseif ($firstByte == 0xfc) {
            if ($offset + 1 >= strlen($payload)) {
                throw new \RuntimeException('数据包不完整，无法解码2字节长度');
            }
            $value = unpack('v', substr($payload, $offset, 2))[1];
            $offset += 2;
            return [$value, 3];
        } elseif ($firstByte == 0xfd) {
            if ($offset + 2 >= strlen($payload)) {
                throw new \RuntimeException('数据包不完整，无法解码3字节长度');
            }
            $value = unpack('V', substr($payload, $offset, 3))[1] & 0x00ffffff;
            $offset += 3;
            return [$value, 4];
        } elseif ($firstByte == 0xfe) {
            if ($offset + 7 >= strlen($payload)) {
                throw new \RuntimeException('数据包不完整，无法解码8字节长度');
            }
            $value = unpack('P', substr($payload, $offset, 8))[1];
            $offset += 8;
            return [$value, 9];
        } else {
            throw new \RuntimeException("无效的长度编码整数: {$firstByte}");
        }
    }
}
