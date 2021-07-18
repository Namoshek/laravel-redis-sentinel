<?php

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use Namoshek\Redis\Sentinel\Connectors\PhpRedisSentinelConnector;

/**
 * Registers and boots services of the Laravel Redis Sentinel package.
 *
 * @package Namoshek\Redis\Sentinel
 */
class RedisSentinelServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->extend('redis', function (RedisManager $service, $app) {
            return $service->extend('phpredis-sentinel', fn () => new PhpRedisSentinelConnector);
        });
    }
}
