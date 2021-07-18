<?php

/** @noinspection PhpFullyQualifiedNameUsageInspection */
/** @noinspection PhpMissingParamTypeInspection */

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Tests;

/**
 * Base for all unit tests.
 */
abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    /**
     * Returns a list of service providers required for the tests.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return string[]
     */
    protected function getPackageProviders($app): array
    {
        return [
            \Illuminate\Redis\RedisServiceProvider::class,
            \Namoshek\Redis\Sentinel\RedisSentinelServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // We use the phpredis-sentinel redis driver as default.
        $app['config']->set('database.redis.client', 'phpredis-sentinel');

        // Setup configuration for different types of supported databases.
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_SENTINEL_HOST'),
            'port' => (int) env('REDIS_SENTINEL_PORT', 6379),
            'password' => env('REDIS_PASSWORD'),
            'sentinel_service' => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
            'sentinel_persistent' => env('REDIS_SENTINEL_PERSISTENT'),
        ]);
    }
}
