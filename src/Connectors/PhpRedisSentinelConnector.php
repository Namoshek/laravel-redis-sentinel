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

        if ($master === false
            || ! is_array($master)
            || ! isset($master['ip'])
            || ! isset($master['port'])
        ) {
            throw new RedisException(sprintf("No master found for service '%s'.", $service));
        }

        $host = ($config['scheme'] ?? 'tcp').'://'.$master['ip'];
        $options = array_merge($config, [
            'host' => $host,
            'port' => $master['port'],
        ]);

        return parent::createClient($options);
    }

    /**
     * Establish a connection with the Redis host.
     *
     * @param  \Redis  $client
     * @param  array  $config
     * @return void
     */
    protected function establishConnection($client, array $config)
    {
        $persistent = $config['persistent'] ?? false;

        $parameters = [
            $config['host'],
            (int) $config['port'],
            Arr::get($config, 'timeout', 0.0),
            $persistent ? Arr::get($config, 'persistent_id', null) : null,
            Arr::get($config, 'retry_interval', 0),
        ];

        if (version_compare(phpversion('redis'), '3.1.3', '>=')) {
            $parameters[] = Arr::get($config, 'read_timeout', 0.0);
        }

        if (version_compare(phpversion('redis'), '5.3.0', '>=')) {
            if (! is_null($context = Arr::get($config, 'context'))) {
                $parameters[] = $context;
            }
        }

        $client->{($persistent ? 'pconnect' : 'connect')}(...$parameters);
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
        $password = $config['sentinel_password'] ?? '';

        if (strlen(trim($host)) === 0) {
            throw new ConfigurationException('No host has been specified for the Redis Sentinel connection.');
        }

        if (strlen(trim($password)) !== 0) {
            /** @noinspection PhpMethodParametersCountMismatchInspection */
            return new RedisSentinel($host, $port, $timeout, $persistent, $retryInterval, $readTimeout, $password);
        }

        return new RedisSentinel($host, $port, $timeout, $persistent, $retryInterval, $readTimeout);
    }
}
