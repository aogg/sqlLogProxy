<?php


/**
 * 获取bytes 数组.
 *
 * @param $data
 *
 * @return array
 */
function getBytes(string $data)
{
    $bytes = [];
    $count = strlen($data);
    for ($i = 0; $i < $count; ++$i) {
        $byte = ord($data[$i]);
        $bytes[] = $byte;
    }

    return $bytes;
}

/**
 * 获取 string.
 *
 * @param array $bytes
 *
 * @return string
 */
function getString(array $bytes)
{
    return implode(array_map('chr', $bytes));
}
