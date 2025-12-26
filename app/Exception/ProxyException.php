<?php

declare(strict_types=1);

namespace App\Exception;

use Exception;

class ProxyException extends Exception
{
    public const HANDSHAKE_ERROR = 1;
    public const AUTH_ERROR = 2;
    public const PARSE_ERROR = 3;
    public const CONNECT_ERROR = 4;

    public static function handshakeError(string $message): self
    {
        return new self($message, self::HANDSHAKE_ERROR);
    }

    public static function authError(string $message): self
    {
        return new self($message, self::AUTH_ERROR);
    }

    public static function parseError(string $message): self
    {
        return new self($message, self::PARSE_ERROR);
    }

    public static function connectError(string $message): self
    {
        return new self($message, self::CONNECT_ERROR);
    }
}
