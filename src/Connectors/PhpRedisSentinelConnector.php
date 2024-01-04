<?php

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Connectors;

use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Arr;
use Namoshek\Redis\Sentinel\Connections\PhpRedisSentinelConnection;
use Namoshek\Redis\Sentinel\Exceptions\ConfigurationException;
use Namoshek\Redis\Sentinel\Exceptions\NotImplementedException;
use Redis;
use RedisException;
use RedisSentinel;

/**
 * Allows to connect to a Sentinel driven Redis master using the PhpRedis extension.
 */
class PhpRedisSentinelConnector extends PhpRedisConnector
{
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

        return new PhpRedisSentinelConnection($connector(), $connector, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options)
    {
        throw new NotImplementedException('The Redis Sentinel driver does not support connecting to clusters.');
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

            return new RedisSentinel($options);
        }

        if ($auth !== null) {
            /** @noinspection PhpMethodParametersCountMismatchInspection */
            return new RedisSentinel($host, $port, $timeout, $persistent, $retryInterval, $readTimeout, $auth);
        }

        return new RedisSentinel($host, $port, $timeout, $persistent, $retryInterval, $readTimeout);
    }
}
