<?php

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use Namoshek\Redis\Sentinel\Connectors\PhpRedisSentinelConnector;
use Namoshek\Redis\Sentinel\Services\RetryService;

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
        $this->app->extend('redis', function (RedisManager $service) {
            $retryService = $this->app->make(RetryService::class);

            return $service->extend('phpredis-sentinel', fn () => new PhpRedisSentinelConnector($retryService));
        });

        // $this->app->singleton(PhpRedisSentinelConnector::class, function ($app) {
        //     return new PhpRedisSentinelConnector($app->make(RetryService::class));
        // });

        // $this->app->extend('redis', function (RedisManager $service) {
        //     $connector = $this->app->make(PhpRedisSentinelConnector::class);

        //     return $service->extend('phpredis-sentinel', fn () => $connector);
        // });
    }
}
