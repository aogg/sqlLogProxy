<?php

declare(strict_types=1);

namespace App\Service\Pool;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;
use App\Service\TargetConnector;

/**
 * Per-Worker Connection Pool for MySQL Target Connections
 *
 * Maintains a fixed-size pool of connections to the target MySQL server.
 * Each Swoole Worker has its own pool instance for thread safety.
 */
class ConnectionPool
{
    private Channel $pool;
    private TargetConnector $connector;
    private int $size;
    private float $idleTimeout;
    private array $stats = [
        'created' => 0,
        'borrowed' => 0,
        'returned' => 0,
        'failed' => 0,
        'destroyed' => 0,
    ];

    public function __construct(TargetConnector $connector, int $size = 12, float $idleTimeout = 300.0)
    {
        $this->connector = $connector;
        $this->size = $size;
        $this->idleTimeout = $idleTimeout;
        $this->pool = new Channel($size);

        // Pre-populate the pool with connections
        $this->initializePool();
    }

    /**
     * Initialize the connection pool by creating initial connections
     */
    private function initializePool(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            try {
                $connection = $this->connector->connect();
                if ($connection) {
                    $this->pool->push([
                        'socket' => $connection,
                        'created_at' => microtime(true),
                        'last_used' => microtime(true),
                    ]);
                    $this->stats['created']++;
                }
            } catch (\Exception $e) {
                $this->stats['failed']++;
                // Log the error but continue creating other connections
                error_log("Failed to create initial connection in pool: " . $e->getMessage());
            }
        }
    }

    /**
     * Borrow a connection from the pool
     *
     * @param float $timeout Timeout in seconds to wait for a connection
     * @return Socket|null
     */
    public function borrow(float $timeout = 5.0): ?Socket
    {
        $startTime = microtime(true);

        // Try to get an existing connection from the pool
        $connection = $this->pool->pop($timeout);
        if ($connection !== false) {
            $this->stats['borrowed']++;

            // Check if connection is still healthy
            if ($this->isConnectionHealthy($connection['socket'])) {
                $connection['last_used'] = microtime(true);
                return $connection['socket'];
            } else {
                // Connection is unhealthy, destroy it and try to create a new one
                $this->destroyConnection($connection['socket']);
                $this->stats['destroyed']++;
            }
        }

        // No available connection, try to create a new one
        try {
            $socket = $this->connector->connect();
            if ($socket) {
                $this->stats['created']++;
                $this->stats['borrowed']++;
                return $socket;
            }
        } catch (\Exception $e) {
            $this->stats['failed']++;
            error_log("Failed to create new connection in pool: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Return a connection to the pool
     *
     * @param Socket $socket
     */
    public function release(Socket $socket): void
    {
        // Check if connection is still healthy before returning to pool
        if ($this->isConnectionHealthy($socket)) {
            $connection = [
                'socket' => $socket,
                'created_at' => microtime(true), // Reset timestamps
                'last_used' => microtime(true),
            ];

            // Try to return to pool, if pool is full, close the connection
            if ($this->pool->push($connection, 0.1) === false) {
                $this->destroyConnection($socket);
            } else {
                $this->stats['returned']++;
            }
        } else {
            // Connection is unhealthy, destroy it
            $this->destroyConnection($socket);
            $this->stats['destroyed']++;
        }
    }

    /**
     * Check if a connection is healthy
     *
     * @param Socket $socket
     * @return bool
     */
    private function isConnectionHealthy(Socket $socket): bool
    {
        // Basic health check - socket should be connected and not have errors
        if (!$socket->isConnected()) {
            return false;
        }

        // Check if connection has been idle for too long
        $connection = null;
        while ($this->pool->length() > 0) {
            $conn = $this->pool->pop(0.001);
            if ($conn !== false && $conn['socket'] === $socket) {
                $connection = $conn;
                break;
            } elseif ($conn !== false) {
                $this->pool->push($conn); // Put back other connections
            }
        }

        if ($connection && (microtime(true) - $connection['last_used']) > $this->idleTimeout) {
            return false;
        }

        // Put connection back if we found it
        if ($connection) {
            $this->pool->push($connection);
        }

        return true;
    }

    /**
     * Destroy a connection
     *
     * @param Socket $socket
     */
    private function destroyConnection(Socket $socket): void
    {
        try {
            if ($socket->isConnected()) {
                $socket->close();
            }
        } catch (\Exception $e) {
            // Ignore errors when closing unhealthy connections
        }
    }

    /**
     * Get pool statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'pool_size' => $this->size,
            'available' => $this->pool->length(),
            'idle_timeout' => $this->idleTimeout,
        ]);
    }

    /**
     * Close all connections in the pool
     */
    public function close(): void
    {
        while ($this->pool->length() > 0) {
            $connection = $this->pool->pop(0.001);
            if ($connection !== false) {
                $this->destroyConnection($connection['socket']);
            }
        }
    }

    /**
     * Get the current number of available connections
     *
     * @return int
     */
    public function getAvailableCount(): int
    {
        return $this->pool->length();
    }
}
