<?php

declare(strict_types=1);

namespace App\Service;

use Swoole\Coroutine\Socket;
use Psr\Log\LoggerInterface;

/**
 * Handle TLS termination for client connections
 *
 * This class manages the server-side TLS handshake for incoming client connections,
 * allowing the proxy to terminate TLS and work with plaintext MySQL protocol.
 */
class ProxyTlsHandler
{
    private LoggerInterface $logger;
    private string $certFile;
    private string $keyFile;
    private ?string $caFile;
    private bool $requireClientCert;

    public function __construct(
        LoggerInterface $logger,
        string $certFile,
        string $keyFile,
        ?string $caFile = null,
        bool $requireClientCert = false
    ) {
        $this->logger = $logger;
        $this->certFile = $certFile;
        $this->keyFile = $keyFile;
        $this->caFile = $caFile;
        $this->requireClientCert = $requireClientCert;
    }

    /**
     * Perform TLS handshake on a client socket
     *
     * @param Socket $socket The client socket to perform TLS handshake on
     * @return bool True if handshake succeeded, false otherwise
     */
    public function performTlsHandshake(Socket $socket): bool
    {
        try {
            $this->logger->debug('Starting TLS handshake on client socket', [
                'socket_fd' => $socket->fd,
                'cert_file' => $this->certFile,
                'key_file' => $this->keyFile,
                'require_client_cert' => $this->requireClientCert,
            ]);

            // Configure SSL options
            $sslOptions = [
                'ssl_cert_file' => $this->certFile,
                'ssl_key_file' => $this->keyFile,
                'ssl_verify_peer' => $this->requireClientCert,
                'ssl_allow_self_signed' => true,
                'ssl_verify_depth' => 10,
            ];

            if ($this->caFile && $this->requireClientCert) {
                $sslOptions['ssl_cafile'] = $this->caFile;
                $sslOptions['ssl_verify_peer'] = true;
            }

            // Enable SSL on the socket
            $socket->enableSSL($sslOptions);

            // Perform SSL handshake
            $result = $socket->sslHandshake();

            if (!$result) {
                $this->logger->warning('TLS handshake failed on client socket', [
                    'socket_fd' => $socket->fd,
                    'ssl_error' => $this->getSslError($socket),
                ]);
                return false;
            }

            // Log successful handshake details
            $peerCert = $this->getPeerCertificate($socket);
            $this->logger->info('TLS handshake completed successfully', [
                'socket_fd' => $socket->fd,
                'client_cert_provided' => $peerCert !== null,
                'cipher' => $this->getCipherInfo($socket),
            ]);

            if ($peerCert) {
                $this->logger->debug('Client certificate details', [
                    'subject' => $peerCert['subject'] ?? 'N/A',
                    'issuer' => $peerCert['issuer'] ?? 'N/A',
                    'valid_from' => $peerCert['validFrom'] ?? 'N/A',
                    'valid_to' => $peerCert['validTo'] ?? 'N/A',
                ]);
            }

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Exception during TLS handshake', [
                'socket_fd' => $socket->fd,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * Check if a socket has TLS enabled
     *
     * @param Socket $socket
     * @return bool
     */
    public function isTlsEnabled(Socket $socket): bool
    {
        // In Swoole, we can check if SSL is enabled by attempting to get SSL state
        // This is a simple check - in production you might want to track this in connection context
        try {
            $socket->getSSLState();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get SSL error information
     *
     * @param Socket $socket
     * @return string
     */
    private function getSslError(Socket $socket): string
    {
        try {
            // Swoole doesn't provide direct SSL error access, but we can get general socket error
            return 'Socket error code: ' . $socket->errCode;
        } catch (\Exception $e) {
            return 'Unable to get error info: ' . $e->getMessage();
        }
    }

    /**
     * Get peer certificate information
     *
     * @param Socket $socket
     * @return array|null
     */
    private function getPeerCertificate(Socket $socket): ?array
    {
        try {
            // Swoole doesn't provide direct certificate access via socket
            // In a real implementation, you might need to use stream_socket_get_meta_data
            // or implement custom certificate extraction
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get cipher information
     *
     * @param Socket $socket
     * @return string
     */
    private function getCipherInfo(Socket $socket): string
    {
        try {
            // Swoole doesn't provide direct cipher info access
            return 'N/A';
        } catch (\Exception $e) {
            return 'Unable to get cipher info: ' . $e->getMessage();
        }
    }

    /**
     * Create TLS handler from configuration
     *
     * @param array $config
     * @param LoggerInterface $logger
     * @return static
     */
    public static function fromConfig(array $config, LoggerInterface $logger): self
    {
        return new self(
            $logger,
            $config['server_cert'] ?? '',
            $config['server_key'] ?? '',
            $config['ca_cert'] ?? null,
            $config['require_client_cert'] ?? false
        );
    }
}
