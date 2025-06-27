<?php

/* @noinspection PhpRedundantCatchClauseInspection */

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Connections;

use Closure;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Namoshek\Redis\Sentinel\Exceptions\RetryRedisException;
use Redis;
use RedisException;

/**
 * The connection to Redis after connecting through a Sentinel using the PhpRedis extension.
 */
class PhpRedisSentinelConnection extends PhpRedisConnection
{
    /**
     * The number of times the client attempts to retry a command when it fails
     * to connect to a Redis instance behind Sentinel.
     */
    protected int $retryAttempts = 20;

    /**
     * The time in milliseconds to wait before the client retries a failed
     * command.
     */
    protected int $retryDelay = 1000;

    /**
     * Create a new PhpRedis connection.
     *
     * @param  \Redis  $client
     * @param  callable|null  $connector
     * @param  array  $config
     */
    public function __construct($client, ?callable $connector = null, array $config = [])
    {
        parent::__construct($client, $connector, $config);

        // Set the retry limit.
        if (isset($config['retry_attempts']) && is_numeric($config['retry_attempts'])) {
            $this->retryAttempts = (int) $config['retry_attempts'];
        }

        // Set the retry wait.
        if (isset($config['retry_delay']) && is_numeric($config['retry_delay'])) {
            $this->retryDelay = (int) $config['retry_delay'];
        }
    }

    /**
     * The following array contains all exception message parts which are interpreted as a connection loss or
     * another unavailability of Redis.
     */
    private const ERROR_MESSAGES_INDICATING_UNAVAILABILITY = [
        'connection closed',
        'connection refused',
        'connection lost',
        'failed while reconnecting',
        'is loading the dataset in memory',
        'php_network_getaddresses',
        'read error on connection',
        'socket',
        'went away',
        'loading',
        'readonly',
        "can't write against a read only replica",
    ];

    /**
     * {@inheritdoc}
     */
    public function scan($cursor, $options = []): mixed
    {
        return $this->retryOnFailure(fn () => parent::scan($cursor, $options));
    }

    /**
     * {@inheritdoc}
     */
    public function zscan($key, $cursor, $options = []): mixed
    {
        return $this->retryOnFailure(fn () => parent::zscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     */
    public function hscan($key, $cursor, $options = []): mixed
    {
        return $this->retryOnFailure(fn () => parent::hscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     */
    public function sscan($key, $cursor, $options = []): mixed
    {
        return $this->retryOnFailure(fn () => parent::sscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     */
    public function pipeline(
        ?callable $callback = null,
        ?int $retryAttempts = null,
    ): Redis|array {
        return $this->retryOnFailure(
            fn () => parent::pipeline($callback),
            $retryAttempts ?? $this->retryAttempts,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function transaction(
        ?callable $callback = null,
        ?int $retryAttempts = null,
    ): Redis|array {
        return $this->retryOnFailure(
            fn () => parent::transaction($callback),
            $retryAttempts ?? $this->retryAttempts,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function evalsha($script, $numkeys, ...$arguments): mixed
    {
        return $this->retryOnFailure(fn () => parent::evalsha($script, $numkeys, ...$arguments));
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe($channels, Closure $callback): void
    {
        $this->retryOnFailure(fn () => parent::subscribe($channels, $callback));
    }

    /**
     * {@inheritdoc}
     */
    public function psubscribe($channels, Closure $callback): void
    {
        $this->retryOnFailure(fn () => parent::psubscribe($channels, $callback));
    }

    /**
     * {@inheritdoc}
     */
    public function flushdb(): void
    {
        $this->retryOnFailure(fn () => parent::flushdb());
    }

    /**
     * {@inheritdoc}
     */
    public function command($method, array $parameters = []): mixed
    {
        return $this->retryOnFailure(fn () => parent::command($method, $parameters));
    }

    /**
     * {@inheritdoc}
     */
    public function __call($method, $parameters): mixed
    {
        return $this->retryOnFailure(fn () => parent::__call(strtolower($method), $parameters));
    }

    /**
     * Attempt to retry the provided operation when the client fails to connect
     * to a Redis server.
     *
     * @param callable $callback The operation to execute.
     * @param ?int $retryAttempts The number of times the retry is performed.
     * @param ?int $retryDelay The time in milliseconds to wait before retrying again.
     * @return mixed The result of the first successful attempt.
     * @throws RetryRedisException|RedisException
     */
    protected function retryOnFailure(
        callable $callback,
        ?int $retryAttempts = null,
        ?int $retryDelay = null,
    ): mixed {
        $retryAttempts ??= $this->retryAttempts;
        $retryDelay ??= $this->retryDelay;

        $attempts = 0;
        $lastException = null;
        while ($attempts <= $retryAttempts) {
            try {
                return $callback();
            } catch (RedisException $exception) {
                if (! $this->shouldRetryRedisException($exception)) {
                    throw $exception;
                }

                if ($retryAttempts !== 0) {
                    usleep($retryDelay * 1000);
                }

                $this->disconnect();

                // Here we reconnect through Redis Sentinel if we lost connection to the server or if another unavailability occurred.
                // We may actually reconnect to the same, broken server. But after a failover occured, we should be ok.
                // It may take a moment until the Sentinel returns the new master, so this may be triggered multiple times.
                try {
                    $this->reconnect();
                } catch (RedisException $exception) {
                    // Ignore when the creation of a new client gets an exception.
                    // If this exception isn't caught the retry will stop.
                }

                $lastException = $exception;
                $attempts++;
            }
        }

        throw new RetryRedisException(sprintf('Reached the (re)connect limit of %d attempts.', $attempts), 0, $lastException);
    }

    /**
     * Inspects the given exception and reconnects the client if the reported error indicates that the server
     * went away or is in readonly mode, which may happen in case of a Redis Sentinel failover.
     */
    private function shouldRetryRedisException(RedisException $exception): bool
    {
        // We convert the exception message to lower-case in order to perform case-insensitive comparison.
        $exceptionMessage = strtolower($exception->getMessage());

        // Because we also match only partial exception messages, we cannot use in_array() at this point.
        foreach (self::ERROR_MESSAGES_INDICATING_UNAVAILABILITY as $errorMessage) {
            if (str_contains($exceptionMessage, $errorMessage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reconnects to the Redis server by overriding the current connection.
     */
    private function reconnect(): void
    {
        $this->client = $this->connector ? call_user_func($this->connector) : $this->client;
    }
}
