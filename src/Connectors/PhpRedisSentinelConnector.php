<?php

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Connectors;

use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Arr;
use Namoshek\Redis\Sentinel\Connections\PhpRedisSentinelConnection;
use Namoshek\Redis\Sentinel\Exceptions\ConfigurationException;
use Namoshek\Redis\Sentinel\Exceptions\RetryRedisException;
use Redis;
use RedisException;
use RedisSentinel;
use Throwable;

/**
 * Allows to connect to a Sentinel driven Redis master using the PhpRedis extension.
 */
class PhpRedisSentinelConnector extends PhpRedisConnector
{
    /**
     * The default of times the client attempts to retry a command when it fails
     * to connect to a Redis instance behind Sentinel.
     */
    public const DEFAULT_CONNECTOR_RETRY_ATTEMPTS = 20;

    /**
     * The default time in milliseconds to wait before the client retries a failed
     * command.
     */
    public const DEFAULT_CONNECTOR_RETRY_DELAY = 1000;

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
     * The following array contains all exception message parts which are interpreted as an issue with name
     * resolving.
     */
    private const MESSAGES_INDICATING_NAME_RESOLUTION_ERRORS = [
        'getaddrinfo',
        'name or service not known',
    ];

    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function connect(array $config, array $options): PhpRedisSentinelConnection
    {
        $connector = function () use ($config, $options) {
            return $this->createClient(array_merge(
                $config,
                $options,
                Arr::pull($config, 'options', [])
            ));
        };

        $retryAttempts = is_numeric($config['connector_retry_attempts'] ?? null)
            ? (int) $config['connector_retry_attempts']
            : self::DEFAULT_CONNECTOR_RETRY_ATTEMPTS;

        $retryDelay = is_numeric($config['connector_retry_delay'] ?? null)
            ? (int) $config['connector_retry_delay']
            : self::DEFAULT_CONNECTOR_RETRY_DELAY;

        $connection = self::retryOnFailure(
            fn () => $connector(),
            $retryAttempts,
            $retryDelay,
        );

        return new PhpRedisSentinelConnection($connection, $connector, $config);
    }

    /**
     * Create the PhpRedis client instance which connects to Redis Sentinel.
     *
     * @throws ConfigurationException
     * @throws RedisException
     */
    protected function createClient(array $config): Redis
    {
        $service = $config['sentinel_service'] ?? 'mymaster';

        $sentinel = $this->connectToSentinel($config);

        $master = $sentinel->master($service);

        if (! $this->isValidMaster($master)) {
            throw new RedisException(sprintf("No master found for service '%s'.", $service));
        }

        return parent::createClient(array_merge($config, [
            'host' => $master['ip'],
            'port' => $master['port'],
        ]));
    }

    /**
     * Check whether master is valid or not.
     */
    protected function isValidMaster(mixed $master): bool
    {
        return is_array($master) && isset($master['ip']) && isset($master['port']);
    }

    /**
     * Connect to the configured Redis Sentinel instance.
     *
     * @throws ConfigurationException
     */
    private function connectToSentinel(array $config): RedisSentinel
    {
        $host = $config['sentinel_host'] ?? '';
        $port = $config['sentinel_port'] ?? 26379;
        $timeout = $config['sentinel_timeout'] ?? 0.2;
        $persistent = $config['sentinel_persistent'] ?? null;
        $retryInterval = $config['sentinel_retry_interval'] ?? 0;
        $readTimeout = $config['sentinel_read_timeout'] ?? 0;
        $username = $config['sentinel_username'] ?? '';
        $password = $config['sentinel_password'] ?? '';
        $ssl = $config['sentinel_ssl'] ?? null;

        if (strlen(trim($host)) === 0) {
            throw new ConfigurationException('No host has been specified for the Redis Sentinel connection.');
        }

        $auth = null;
        if (strlen(trim($username)) !== 0 && strlen(trim($password)) !== 0) {
            $auth = [$username, $password];
        } elseif (strlen(trim($password)) !== 0) {
            $auth = $password;
        }

        if (version_compare(phpversion('redis'), '6.0', '>=')) {
            $options = [
                'host' => $host,
                'port' => $port,
                'connectTimeout' => $timeout,
                'persistent' => $persistent,
                'retryInterval' => $retryInterval,
                'readTimeout' => $readTimeout,
            ];

            if ($auth !== null) {
                $options['auth'] = $auth;
            }

            if (version_compare(phpversion('redis'), '6.1', '>=') && $ssl !== null) {
                $options['ssl'] = $ssl;
            }

            return new RedisSentinel($options);
        }

        if ($auth !== null) {
            /** @noinspection PhpMethodParametersCountMismatchInspection */
            return new RedisSentinel($host, $port, $timeout, $persistent, $retryInterval, $readTimeout, $auth);
        }

        return new RedisSentinel($host, $port, $timeout, $persistent, $retryInterval, $readTimeout);
    }

    /**
     * Attempt to retry the provided operation when the client fails to connect
     * to a Redis server.
     *
     * @param  callable  $callback  The operation to execute.
     * @param  int  $retryAttempts  The number of times the retry is performed.
     * @param  int  $retryDelay  The time in milliseconds to wait before retrying again.
     * @param  callable|null  $failureCallback  The callback to execute when failure occours.
     * @return mixed The result of the first successful attempt.
     *
     * @throws RetryRedisException|RedisException
     */
    public static function retryOnFailure(
        callable $callback,
        int $retryAttempts,
        int $retryDelay,
        ?callable $failureCallback = null,
    ): mixed {
        $attempts = 0;
        $lastException = null;
        while ($attempts <= $retryAttempts) {
            try {
                return $callback();
            } catch (RedisException|Throwable $exception) {
                // We check if the Exception should be retried. This means checking for:
                // - retryable redis exceptions
                // - name resolution exceptions
                if ($exception instanceof RedisException) {
                    if (! self::shouldRetryRedisException($exception)) {
                        throw $exception;
                    }
                } elseif (! self::isNameResolutionException($exception)) {
                    throw $exception;
                }

                // Wait before retry.
                if ($retryAttempts !== 0) {
                    usleep($retryDelay * 1000);
                }

                // Execute optional failure callback.
                if ($failureCallback && is_callable($failureCallback)) {
                    call_user_func($failureCallback);
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
    protected static function shouldRetryRedisException(RedisException $exception): bool
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
     * Check if the given exception is a name resolution exception.
     */
    public static function isNameResolutionException(Throwable $exception)
    {
        // We convert the exception message to lower-case in order to perform case-insensitive comparison.
        $exceptionMessage = strtolower($exception->getMessage());

        // Because we also match only partial exception messages, we cannot use in_array() at this point.
        foreach (self::MESSAGES_INDICATING_NAME_RESOLUTION_ERRORS as $errorMessage) {
            if (str_contains($exceptionMessage, $errorMessage)) {
                return true;
            }
        }

        return false;
    }
}
