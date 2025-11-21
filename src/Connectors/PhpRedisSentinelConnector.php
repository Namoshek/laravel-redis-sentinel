<?php

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Connectors;

use Exception;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Arr;
use Namoshek\Redis\Sentinel\Connections\PhpRedisSentinelConnection;
use Namoshek\Redis\Sentinel\Exceptions\ConfigurationException;
use Namoshek\Redis\Sentinel\Services\RetryContext;
use Namoshek\Redis\Sentinel\Services\RetryManager;
use Redis;
use RedisException;
use RedisSentinel;

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
     * Setup the connector.
     */
    public function __construct(
        protected RetryManager $retryManager,
    ) {
        //
    }

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

        $retryContext = new RetryContext($this->retryManager, $retryAttempts, $retryDelay);

        $connection = $retryContext->retryOnFailure(fn () => $connector());

        return new PhpRedisSentinelConnection($connection, $connector, $config, $retryContext);
    }

    /**
     * Create the PhpRedis client instance which connects to Redis Sentinel.
     *
     * @throws ConfigurationException
     * @throws RedisException
     */
    protected function createClient(array $config): Redis
    {
        $master = $this->getMaster($config);

        if (! $this->isValidMaster($master)) {
            throw new RedisException(sprintf("No master found for service '%s'.", $config['sentinel_service'] ?? 'mymaster'));
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
     * Get the master for the given service.
     *
     * @throws ConfigurationException
     * @throws RedisException
     */
    private function getMaster(array $config): array
    {
        $service = $config['sentinel_service'] ?? 'mymaster';

        $exception = null;
        $hosts = $config['sentinel_hosts'] ?? [];

        foreach ($hosts as $host) {
            $hostConfig = array_merge($config, [
                'sentinel_host' => $host['host'] ?? null,
                'sentinel_port' => ((int) $host['port']) ?? null,
            ]);

            try {
                return $this->connectToSentinel($hostConfig)->master($service);
            } catch (RedisException $e) {
                $exception = $e;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $this->connectToSentinel($config)->master($service);
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
}
