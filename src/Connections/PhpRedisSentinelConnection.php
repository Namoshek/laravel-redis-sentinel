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
    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function scan($cursor, $options = [])
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
    public function zscan($key, $cursor, $options = [])
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
    public function hscan($key, $cursor, $options = [])
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
    public function sscan($key, $cursor, $options = [])
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
    public function pipeline(callable $callback = null)
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
    public function transaction(callable $callback = null)
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
    public function evalsha($script, $numkeys, ...$arguments)
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
    public function subscribe($channels, Closure $callback)
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
    public function psubscribe($channels, Closure $callback)
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
    public function flushdb()
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
    public function command($method, array $parameters = [])
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
    public function __call($method, $parameters)
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
    private function reconnectIfRedisIsUnavailableOrReadonly(RedisException $exception)
    {
        // Reconnect through Redis Sentinel if we lost connection to the server. We may actually reconnect to the same,
        // broken server. But after a failover occured, we should be ok.
        if (Str::contains($exception->getMessage(), 'went away')) {
            $this->reconnect();

            return;
        }

        // Force a reconnect through the Sentinel in case the Redis instance became readonly due to a failover.
        // It may take a moment until the Sentinel returns the new master, so this may be triggered multiple times.
        if (Str::contains($exception->getMessage(), ['READONLY', "You can't write against a read only replica"])) {
            $this->reconnect();
        }
    }

    /**
     * Reconnects to the Redis server by overriding the current connection.
     */
    private function reconnect()
    {
        $this->client = $this->connector ? call_user_func($this->connector) : $this->client;
    }
}
