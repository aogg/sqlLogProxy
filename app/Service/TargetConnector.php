<?php

declare(strict_types=1);

namespace App\Service;

use Swoole\Coroutine\Socket;

/**
 * Connector for establishing connections to target MySQL server
 *
 * Supports both plain TCP and TLS connections to the target MySQL server.
 */
class TargetConnector
{
    private string $host;
    private int $port;
    private ?float $timeout;
    private bool $useTls;
    private ?string $tlsCaFile;
    private ?string $tlsCertFile;
    private ?string $tlsKeyFile;

    public function __construct(
        string $host,
        int $port,
        ?float $timeout = 5.0,
        bool $useTls = true,
        ?string $tlsCaFile = null,
        ?string $tlsCertFile = null,
        ?string $tlsKeyFile = null
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->useTls = $useTls;
        $this->tlsCaFile = $tlsCaFile;
        $this->tlsCertFile = $tlsCertFile;
        $this->tlsKeyFile = $tlsKeyFile;
    }

    /**
     * Establish a connection to the target MySQL server
     *
     * @return Socket|null
     */
    public function connect(): ?Socket
    {
        try {
            // Create TCP socket
            $socket = new Socket(AF_INET, SOCK_STREAM, SOL_TCP);
            $socket->setProtocol([
                'open_tcp_keepalive' => true,
                'tcp_keepidle' => 60,
                'tcp_keepinterval' => 30,
                'tcp_keepcount' => 3,
            ]);

            // Connect to target
            if (!$socket->connect($this->host, $this->port, $this->timeout)) {
                error_log("Failed to connect to {$this->host}:{$this->port}");
                return null;
            }

            // Note: TLS support is disabled for now due to Swoole API limitations
            // TODO: Implement TLS support when Swoole API is available
            if ($this->useTls) {
                error_log("TLS connections are not supported in current Swoole version");
                $socket->close();
                return null;
            }

            return $socket;

        } catch (\Exception $e) {
            error_log("Exception during connection to {$this->host}:{$this->port}: " . $e->getMessage());
            return null;
        }
    }


    /**
     * Create a connector from configuration
     *
     * @param array $config
     * @return static
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            $config['host'] ?? 'mysql57.common-all',
            $config['port'] ?? 3306,
            $config['timeout'] ?? 5.0,
            $config['tls'] ?? true,
            $config['tls_ca_file'] ?? null,
            $config['tls_cert_file'] ?? null,
            $config['tls_key_file'] ?? null
        );
    }
}
