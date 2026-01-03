<?php


/**
 * 复制目录
 *
 * @param string $source 源目录
 * @param string $destination 目标目录
 * @return bool 成功返回 true，失败返回 false
 */
function copy_directory(string $source, string $destination): bool {
    // 检查源目录是否存在
    if (!is_dir($source)) {
        return false;
    }

    // 创建目标目录（如果不存在）
    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }

    // 打开目录句柄
    $dir = opendir($source);

    // 遍历目录内容
    while (($file = readdir($dir)) !== false) {
        // 跳过 . 和 ..
        if ($file == '.' || $file == '..') {
            continue;
        }

        $srcPath = $source . DIRECTORY_SEPARATOR . $file;
        $dstPath = $destination . DIRECTORY_SEPARATOR . $file;

        // 如果是子目录，递归复制
        if (is_dir($srcPath)) {
            copy_directory($srcPath, $dstPath);
        } else {
            // 复制文件
            try{

                copy($srcPath, $dstPath);
            }catch (\Throwable $e){
                closedir($dir);
                return false;
            }
        }
    }

    closedir($dir);
    return true;
}

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