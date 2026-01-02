<?php

/**
 * 获取 string.
 *
 * @param array $bytes
 *
 * @return string
 */
function bytes_to_string(array $bytes)
{
    return implode(array_map('chr', $bytes));
}

if (!function_exists('get_exception_hyperf_array')) {

    /**
     * 转为可存储的数组
     *
     * @param \Throwable $exception
     * @return array
     */
    function get_exception_hyperf_array($exception)
    {
        $exception_json = [
            'exception_class_name' => get_class($exception),
            'getMessage' => $exception->getMessage(),
            'getFile' => $exception->getFile(),
            'getCode' => $exception->getCode(),
            'getTraceAsStringArr' => explode("\n", $exception->getTraceAsString()),
        ];


        if ($exception instanceof \Hyperf\Database\Exception\QueryException) {
            $exception_json['getSql'] = $exception->getSql();
        }

        return $exception_json;
    }
}