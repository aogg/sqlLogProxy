<?php

namespace App\Enums;

use Hyperf\Context\ApplicationContext;
use Hyperf\Logger\LoggerFactory;

enum LogEnum: string
{
    case base64DataByClientSendProxy = 'base64_data_by_client_send_proxy';

    public function getLogger($name)
    {
        return ApplicationContext::getContainer()->get(LoggerFactory::class)->get($name, $this->value);
    }
}
