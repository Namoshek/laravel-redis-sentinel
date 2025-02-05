<?php

/* @noinspection PhpRedundantCatchClauseInspection */

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Connections;

use Closure;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Str;
use Redis;
use RedisException;

/**
 * The connection to Redis after connecting through a Sentinel using the PhpRedis extension.
 */
class PhpRedisSentinelConnection extends PhpRedisConnection
{
    // The following array contains all exception message parts which are interpreted as a connection loss or
    // another unavailability of Redis.
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
     *
     * @throws RedisException
     */
    public function scan($cursor, $options = []): mixed
    {
        try {
            return parent::scan($cursor, $options);
        } catch (RedisException $e) {
            $this->reconnectIfRedisIsUnavailableOrReadonly($e);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function zscan($key, $cursor, $options = []): mixed
    {
        try {
            return parent::zscan($key, $cursor, $options);
        } catch (RedisException $e) {
            $this->reconnectIfRedisIsUnavailableOrReadonly($e);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function hscan($key, $cursor, $options = []): mixed
    {
        try {
            return parent::hscan($key, $cursor, $options);
        } catch (RedisException $e) {
            $this->reconnectIfRedisIsUnavailableOrReadonly($e);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function sscan($key, $cursor, $options = []): mixed
    {
        try {
            return parent::sscan($key, $cursor, $options);
        } catch (RedisException $e) {
            $this->reconnectIfRedisIsUnavailableOrReadonly($e);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function pipeline(?callable $callback = null): Redis|array
    {
        try {
            return parent::pipeline($callback);
        } catch (RedisException $e) {
            $this->reconnectIfRedisIsUnavailableOrReadonly($e);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function transaction(?callable $callback = null): Redis|array
    {
        try {
            return parent::transaction($callback);
        } catch (RedisException $e) {
            $this->reconnectIfRedisIsUnavailableOrReadonly($e);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function evalsha($script, $numkeys, ...$arguments): mixed
    {
        try {
            return parent::evalsha($script, $numkeys, $arguments);
        } catch (RedisException $e) {
            $this->reconnectIfRedisIsUnavailableOrReadonly($e);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function subscribe($channels, Closure $callback): void
    {
        try {
            parent::subscribe($channels, $callback);
        } catch (RedisException $e) {
            $this->reconnectIfRedisIsUnavailableOrReadonly($e);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function psubscribe($channels, Closure $callback): void
    {
        try {
            parent::psubscribe($channels, $callback);
        } catch (RedisException $e) {
            $this->reconnectIfRedisIsUnavailableOrReadonly($e);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function flushdb(): void
    {
        try {
            parent::flushdb();
        } catch (RedisException $e) {
            $this->reconnectIfRedisIsUnavailableOrReadonly($e);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function command($method, array $parameters = []): mixed
    {
        try {
            return parent::command($method, $parameters);
        } catch (RedisException $e) {
            $this->reconnectIfRedisIsUnavailableOrReadonly($e);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function __call($method, $parameters): mixed
    {
        try {
            return parent::__call(strtolower($method), $parameters);
        } catch (RedisException $e) {
            $this->reconnectIfRedisIsUnavailableOrReadonly($e);

            throw $e;
        }
    }

    /**
     * Inspects the given exception and reconnects the client if the reported error indicates that the server
     * went away or is in readonly mode, which may happen in case of a Redis Sentinel failover.
     */
    private function reconnectIfRedisIsUnavailableOrReadonly(RedisException $exception): void
    {
        // We convert the exception message to lower-case in order to perform case-insensitive comparison.
        $exceptionMessage = strtolower($exception->getMessage());

        // Because we also match only partial exception messages, we cannot use in_array() at this point.
        foreach (self::ERROR_MESSAGES_INDICATING_UNAVAILABILITY as $errorMessage) {
            if (str_contains($exceptionMessage, $errorMessage)) {
                // Here we reconnect through Redis Sentinel if we lost connection to the server or if another unavailability occurred.
                // We may actually reconnect to the same, broken server. But after a failover occured, we should be ok.
                // It may take a moment until the Sentinel returns the new master, so this may be triggered multiple times.
                $this->reconnect();

                return;
            }
        }
    }

    /**
     * Reconnects to the Redis server by overriding the current connection.
     */
    private function reconnect(): void
    {
        $this->client = $this->connector ? call_user_func($this->connector) : $this->client;
    }
}
