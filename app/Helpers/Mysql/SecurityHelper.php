<?php

namespace App\Helpers\Mysql;


/**
 * @see SMProxy
 */
class SecurityHelper
{

    /**
     * 计算 MySQL native password 认证响应
     * 注意：MySQL客户端只使用auth_plugin_data的前20字节进行认证
     */
    public static function calculateAuthResponse(string $password, string $authPluginData): string
    {
        if ($password === '') {
            return '';
        }

        // MySQL客户端只使用前20字节的auth_plugin_data进行认证
        $authDataForClient = substr($authPluginData, 0, 20);

        // SHA1(password)
        $hash1 = sha1($password, true);

        // SHA1(SHA1(password))
        $hash2 = sha1($hash1, true);

        // SHA1(auth_plugin_data[0..19] + SHA1(SHA1(password)))
        $hash3 = sha1($authDataForClient . $hash2, true);

        // XOR: SHA1(password) ^ SHA1(auth_plugin_data[0..19] + SHA1(SHA1(password)))
        $response = $hash1 ^ $hash3;

        return $response;
    }

    public static function scramble411_new(string $pass, $seed)
    {
        $pass1 = getBytes(sha1($pass, true));
        $pass2 = getBytes(sha1(getString($pass1), true));
        $pass3 = getBytes(sha1(($seed) . getString($pass2), true));
        for ($i = 0, $count = count($pass3); $i < $count; ++$i) {
            $pass3[$i] = ($pass3[$i] ^ $pass1[$i]);
        }

        return getString($pass3);
    }

    public static function scramble411(string $pass, array $seed)
    {
        $pass1 = getBytes(sha1($pass, true));
        $pass2 = getBytes(sha1(getString($pass1), true));
        $pass3 = getBytes(sha1(getString($seed) . getString($pass2), true));
        for ($i = 0, $count = count($pass3); $i < $count; ++$i) {
            $pass3[$i] = ($pass3[$i] ^ $pass1[$i]);
        }

        return $pass3;
    }

    public static function scrambleSha256(string $pass, array $seed)
    {
        $pass1 = getBytes(hash('sha256', $pass, true));
        $pass2 = getBytes(hash('sha256', getString($pass1), true));
        $pass3 = getBytes(hash('sha256', getString($pass2) . getString($seed), true));
        for ($i = 0, $count = count($pass3); $i < $count; ++$i) {
            $pass1[$i] ^= $pass3[$i];
        }
        return $pass1;
    }

    private static function xorPassword($password, $salt)
    {
        $password_bytes = getBytes($password);
        $password_bytes[] = 0;
        $salt_len = count($salt);
        for ($i = 0, $count = count($password_bytes); $i < $count; ++$i) {
            $password_bytes[$i] ^= $salt[$i % $salt_len];
        }
        return getString($password_bytes);
    }


    public static function sha2RsaEncrypt($password, $salt, $publicKey)
    {
        /*Encrypt password with salt and public_key.

        Used for sha256_password and caching_sha2_password.
        */
        $message = self::xorPassword($password, $salt);
        openssl_public_encrypt($message, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);
        return $encrypted;
    }
}
