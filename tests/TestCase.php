<?php

/** @noinspection PhpFullyQualifiedNameUsageInspection */
/** @noinspection PhpMissingParamTypeInspection */

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Tests;

/**
 * Base for all unit tests.
 *
 * @package Namoshek\Redis\Sentinel\Tests
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
    }
}
