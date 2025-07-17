<?php

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use Namoshek\Redis\Sentinel\Connectors\PhpRedisSentinelConnector;
use Namoshek\Redis\Sentinel\Services\RetryManager;

/**
 * Registers and boots services of the Laravel Redis Sentinel package.
 */
class RedisSentinelServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RetryManager::class, fn () => new RetryManager);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->extend('redis', function (RedisManager $service, $app) {
            $retryManager = $app->make(RetryManager::class);

            return $service->extend('phpredis-sentinel', fn () => new PhpRedisSentinelConnector($retryManager));
        });
    }
}
