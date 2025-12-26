<?php

declare(strict_types=1);

namespace App\Protocol\MySql;

class Command
{
    // 客户端命令类型
    public const COM_SLEEP = 0x00;
    public const COM_QUIT = 0x01;
    public const COM_INIT_DB = 0x02;
    public const COM_QUERY = 0x03;
    public const COM_FIELD_LIST = 0x04;
    public const COM_CREATE_DB = 0x05;
    public const COM_DROP_DB = 0x06;
    public const COM_REFRESH = 0x07;
    public const COM_SHUTDOWN = 0x08;
    public const COM_STATISTICS = 0x09;
    public const COM_PROCESS_INFO = 0x0a;
    public const COM_CONNECT = 0x0b;
    public const COM_PROCESS_KILL = 0x0c;
    public const COM_DEBUG = 0x0d;
    public const COM_PING = 0x0e;
    public const COM_TIME = 0x0f;
    public const COM_DELAYED_INSERT = 0x10;
    public const COM_CHANGE_USER = 0x11;
    public const COM_BINLOG_DUMP = 0x12;
    public const COM_TABLE_DUMP = 0x13;
    public const COM_CONNECT_OUT = 0x14;
    public const COM_REGISTER_SLAVE = 0x15;
    public const COM_STMT_PREPARE = 0x16;
    public const COM_STMT_EXECUTE = 0x17;
    public const COM_STMT_SEND_LONG_DATA = 0x18;
    public const COM_STMT_CLOSE = 0x19;
    public const COM_STMT_RESET = 0x1a;
    public const COM_SET_OPTION = 0x1b;
    public const COM_STMT_FETCH = 0x1c;
    public const COM_DAEMON = 0x1d;
    public const COM_END = 0x1e;

    // 响应类型
    public const OK_PACKET = 0x00;
    public const ERR_PACKET = 0xff;
    public const EOF_PACKET = 0xfe;
    public const LOCAL_INFILE_REQUEST = 0xfb;

    /**
     * 获取命令名称
     */
    public static function getCommandName(int $command): string
    {
        $names = [
            self::COM_SLEEP => 'COM_SLEEP',
            self::COM_QUIT => 'COM_QUIT',
            self::COM_INIT_DB => 'COM_INIT_DB',
            self::COM_QUERY => 'COM_QUERY',
            self::COM_FIELD_LIST => 'COM_FIELD_LIST',
            self::COM_CREATE_DB => 'COM_CREATE_DB',
            self::COM_DROP_DB => 'COM_DROP_DB',
            self::COM_REFRESH => 'COM_REFRESH',
            self::COM_SHUTDOWN => 'COM_SHUTDOWN',
            self::COM_STATISTICS => 'COM_STATISTICS',
            self::COM_PROCESS_INFO => 'COM_PROCESS_INFO',
            self::COM_CONNECT => 'COM_CONNECT',
            self::COM_PROCESS_KILL => 'COM_PROCESS_KILL',
            self::COM_DEBUG => 'COM_DEBUG',
            self::COM_PING => 'COM_PING',
            self::COM_TIME => 'COM_TIME',
            self::COM_DELAYED_INSERT => 'COM_DELAYED_INSERT',
            self::COM_CHANGE_USER => 'COM_CHANGE_USER',
            self::COM_BINLOG_DUMP => 'COM_BINLOG_DUMP',
            self::COM_TABLE_DUMP => 'COM_TABLE_DUMP',
            self::COM_CONNECT_OUT => 'COM_CONNECT_OUT',
            self::COM_REGISTER_SLAVE => 'COM_REGISTER_SLAVE',
            self::COM_STMT_PREPARE => 'COM_STMT_PREPARE',
            self::COM_STMT_EXECUTE => 'COM_STMT_EXECUTE',
            self::COM_STMT_SEND_LONG_DATA => 'COM_STMT_SEND_LONG_DATA',
            self::COM_STMT_CLOSE => 'COM_STMT_CLOSE',
            self::COM_STMT_RESET => 'COM_STMT_RESET',
            self::COM_SET_OPTION => 'COM_SET_OPTION',
            self::COM_STMT_FETCH => 'COM_STMT_FETCH',
            self::COM_DAEMON => 'COM_DAEMON',
            self::COM_END => 'COM_END',
        ];

        return isset($names[$command]) ? $names[$command] : sprintf('UNKNOWN(0x%02x)', $command);
    }
}
